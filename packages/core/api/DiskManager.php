<?php

declare(strict_types=1);

namespace FluxFiles;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

class DiskManager
{
    private array $disks = [];
    private array $s3Clients = [];
    private array $configs;

    public function __construct(array $configs)
    {
        $this->configs = $configs;
    }

    public function disk(string $name): Filesystem
    {
        if (!isset($this->disks[$name])) {
            if (!isset($this->configs[$name])) {
                throw new ApiException("Disk '{$name}' is not configured", 400);
            }
            $this->disks[$name] = $this->build($name, $this->configs[$name]);
        }

        return $this->disks[$name];
    }

    public function s3Client(string $name): S3Client
    {
        if (!isset($this->s3Clients[$name])) {
            // Force disk init which also creates the S3 client
            $this->disk($name);
        }

        if (!isset($this->s3Clients[$name])) {
            throw new ApiException("Disk '{$name}' is not an S3-compatible disk", 400);
        }

        return $this->s3Clients[$name];
    }

    /**
     * Presigned GET URL for an object on an S3-compatible disk, or null when the
     * disk isn't S3 or presigning fails. Lets a caller redirect (302) to the
     * bucket instead of proxying the bytes through the app server.
     *
     * $disposition (optional) is signed into the URL as ResponseContentDisposition,
     * so a redirected download lands with the right filename/inline behaviour
     * instead of the raw object key.
     */
    public function presignGetUrl(string $name, string $key, int $ttl = 3600, ?string $disposition = null): ?string
    {
        $cfg = $this->config($name);
        if (($cfg['driver'] ?? '') !== 's3') {
            return null;
        }
        try {
            $client = $this->s3Client($name);
            $params = ['Bucket' => $cfg['bucket'] ?? '', 'Key' => $key];
            if ($disposition !== null && $disposition !== '') {
                $params['ResponseContentDisposition'] = $disposition;
            }
            $cmd = $client->getCommand('GetObject', $params);
            return (string) $client->createPresignedRequest($cmd, '+' . max(1, $ttl) . ' seconds')->getUri();
        } catch (\Throwable $e) {
            error_log('FluxFiles: presign GET failed — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Register a BYOB (Bring Your Own Bucket) disk at runtime.
     * Only S3-compatible drivers are allowed — local driver is rejected for security.
     */
    public function registerByobDisk(string $name, array $config): void
    {
        if (($config['driver'] ?? '') === 'local') {
            throw new ApiException("BYOB disk '{$name}' cannot use local driver", 403);
        }

        $this->configs[$name] = $config;

        // Clear cached instances so next call rebuilds with new config
        unset($this->disks[$name], $this->s3Clients[$name]);
    }

    public function config(string $name): array
    {
        return $this->configs[$name] ?? [];
    }

    private function build(string $name, array $cfg): Filesystem
    {
        $driver = $cfg['driver'] ?? 'local';

        if ($driver === 'local') {
            $root = $cfg['root'] ?? __DIR__ . '/../storage/uploads';
            if (!is_dir($root)) {
                mkdir($root, 0755, true);
            }
            $adapter = new LocalFilesystemAdapter($root);
        } elseif ($driver === 's3') {
            $s3Params = [
                'credentials' => [
                    'key'    => $cfg['key'] ?? '',
                    'secret' => $cfg['secret'] ?? '',
                ],
                'region'  => $cfg['region'] ?? 'us-east-1',
                'version' => 'latest',
            ];

            if (!empty($cfg['endpoint'])) {
                $s3Params['endpoint'] = $cfg['endpoint'];
                $s3Params['use_path_style_endpoint'] = true;

                // Pin the connection to the IP CredentialEncryptor::validate() already
                // resolved+validated this request (see Claims::fromJwtPayload), instead
                // of letting curl re-resolve the host at actual-connect time — which
                // could be much later in the request and is exactly the DNS-rebinding
                // TOCTOU window UrlImporter::assertConnectedIpSafe() defends against
                // for plain fetches. CURLOPT_RESOLVE keeps the original host for TLS
                // SNI/cert verification while forcing the connection to that one IP.
                // No pin (e.g. this disk wasn't built from a JWT-decoded BYOB config,
                // or the host didn't resolve at validation time) → unchanged behavior.
                if (!empty($cfg['_pinned_ip'])) {
                    $epHost = strtolower(trim((string) (parse_url((string) $cfg['endpoint'], PHP_URL_HOST) ?? ''), '[]'));
                    $epPort = parse_url((string) $cfg['endpoint'], PHP_URL_PORT)
                        ?? (strtolower((string) parse_url((string) $cfg['endpoint'], PHP_URL_SCHEME)) === 'http' ? 80 : 443);
                    if ($epHost !== '') {
                        $s3Params['http']['curl'][CURLOPT_RESOLVE] = ["{$epHost}:{$epPort}:{$cfg['_pinned_ip']}"];
                    }
                }
            }

            $client = new S3Client($s3Params);

            // Only native AWS S3 marked public should send an object ACL (public-read).
            // Everyone else — private AWS disks, and all R2/MinIO endpoint disks — must
            // NOT send an ACL: modern S3 buckets default to "Bucket owner enforced"
            // (ACLs disabled) and reject any ACL header, and R2 has no ACL support.
            // Strip the ACL param from every command so writes/copies/multipart succeed.
            $wantsAcl = empty($cfg['endpoint']) && ($cfg['visibility'] ?? 'private') === 'public';
            if (!$wantsAcl) {
                $client->getHandlerList()->appendInit(
                    Middleware::mapCommand(static function (CommandInterface $command) {
                        unset($command['ACL']);
                        return $command;
                    }),
                    'fluxfiles.strip_acl'
                );
            }

            $this->s3Clients[$name] = $client;

            $adapter = new AwsS3V3Adapter($client, $cfg['bucket'] ?? '');

            // R2/MinIO: disable retain_visibility to avoid GetObjectAcl reads. Public
            // access is handled at the URL layer (public_url / public bucket).
            if (!empty($cfg['endpoint'])) {
                return new Filesystem($adapter, ['retain_visibility' => false]);
            }

            // Native AWS S3 public disk → default writes to public-read ACL so the
            // direct object URL is readable (requires a bucket that allows ACLs).
            if ($wantsAcl) {
                return new Filesystem($adapter, ['visibility' => 'public']);
            }
        } elseif ($driver === 'sftp') {
            $adapter = self::buildSftpAdapter($cfg);
        } else {
            throw new ApiException("Unknown disk driver: {$driver}", 400);
        }

        return new Filesystem($adapter);
    }

    /**
     * Build a Flysystem SFTP adapter (connect/disconnect per request — no pool,
     * no DB). Auth is password OR private key (key wins when both are set). The
     * host is SSRF-checked so an SFTP disk can't be pointed at a loopback /
     * link-local / cloud-metadata address the way a BYOB S3 endpoint is guarded.
     *
     * @param array<string,mixed> $cfg host, port, username, password, private_key,
     *        private_key_passphrase, root
     */
    private static function buildSftpAdapter(array $cfg)
    {
        $root = (string) ($cfg['root'] ?? '/');
        return new \League\Flysystem\PhpseclibV3\SftpAdapter(self::buildSftpProvider($cfg), $root);
    }

    /**
     * Build the SFTP connection provider from a disk config (SSRF-checking the
     * host). Shared by the Flysystem adapter and the raw-connection path used for
     * chmod (which Flysystem only exposes as coarse public/private visibility).
     */
    private static function buildSftpProvider(array $cfg): \League\Flysystem\PhpseclibV3\SftpConnectionProvider
    {
        $host = (string) ($cfg['host'] ?? '');
        if ($host === '') {
            throw new ApiException('SFTP disk: missing host', 400, 'sftp_config');
        }
        // Reuse the SSRF denylist (same guard the BYOB S3 endpoint check uses):
        // reject loopback / RFC1918 / link-local / CGNAT / cloud-metadata targets.
        // Connect to the validated IP itself rather than the hostname — SFTP has no
        // TLS/SNI to preserve, so pinning to the already-checked IP (instead of
        // letting phpseclib re-resolve the host moments later) closes the same
        // DNS-rebinding TOCTOU window the BYOB S3 endpoint pin closes.
        if (\class_exists('\\FluxFiles\\SsrfGuard')) {
            $safeIps = SsrfGuard::assertHostSafe($host);
            if ($safeIps !== [] && !filter_var($host, FILTER_VALIDATE_IP)) {
                $host = $safeIps[0];
            }
        }
        // Host-key pinning: when a fingerprint is configured, the provider rejects
        // a server whose host key doesn't match (anti-MITM). Comma-separated →
        // an array so several keys are accepted (rotation). Empty → null (the
        // provider then trusts any host key — backward-compatible default).
        $fpRaw = trim((string) ($cfg['host_fingerprint'] ?? ''));
        $hostFingerprint = null;
        if ($fpRaw !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', $fpRaw)), 'strlen'));
            $hostFingerprint = count($list) === 1 ? $list[0] : $list;
        }

        // Fail-closed host verification: an operator who wants to guarantee no
        // connection ever trusts an unpinned key opts in here — without it, a
        // missing host_fingerprint silently falls back to trust-any-key above
        // (kept as the default for backward compatibility with existing disks).
        if (!empty($cfg['require_host_key']) && $hostFingerprint === null) {
            throw new ApiException(
                "SFTP disk requires host_fingerprint to be set (require_host_key is on)",
                400,
                'sftp_host_key_required'
            );
        }

        return \League\Flysystem\PhpseclibV3\SftpConnectionProvider::fromArray([
            'host'                => $host,
            'username'            => (string) ($cfg['username'] ?? ''),
            'password'            => ($cfg['password'] ?? '') !== '' ? (string) $cfg['password'] : null,
            'privateKey'          => ($cfg['private_key'] ?? '') !== '' ? (string) $cfg['private_key'] : null,
            'passphrase'          => ($cfg['private_key_passphrase'] ?? '') !== '' ? (string) $cfg['private_key_passphrase'] : null,
            'port'                => (int) ($cfg['port'] ?? 22),
            'useAgent'            => false, // never reach for a local ssh-agent on the server
            'hostFingerprint'     => $hostFingerprint,
            'timeout'             => (int) ($cfg['timeout'] ?? 20),
            'maxTries'            => 2,
            'preferredAlgorithms' => !empty($cfg['strict_algorithms']) ? self::modernSshAlgorithms() : [],
        ]);
    }

    /**
     * THE single source for the modern-only KEX/hostkey/cipher/MAC allowlist.
     * Every algorithm name is already OpenSSH's own IANA-registry naming (that's
     * why the exact same strings work for both phpseclib's preferredAlgorithms
     * AND OpenSSH's -o *Algorithms= flags, see modernSshOpensshFlags()) — only
     * the packaging differs. Excludes everything phpseclib still offers for
     * legacy-server compat: SHA-1 KEX (incl. group1/group14-sha1), RC4 (arcfour),
     * 3DES, Blowfish/Twofish, CBC-mode ciphers, ssh-dss, SHA-1-only ssh-rsa, and
     * MD5/SHA-1 MACs.
     *
     * @return array{kex:string[],hostkey:string[],ciphers:string[],macs:string[]}
     */
    private static function modernSshAlgorithmLists(): array
    {
        return [
            'kex' => [
                'curve25519-sha256', 'curve25519-sha256@libssh.org',
                'ecdh-sha2-nistp256', 'ecdh-sha2-nistp384', 'ecdh-sha2-nistp521',
                'diffie-hellman-group-exchange-sha256',
                'diffie-hellman-group16-sha512', 'diffie-hellman-group18-sha512',
                'diffie-hellman-group14-sha256',
            ],
            'hostkey' => [
                'ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384',
                'ecdsa-sha2-nistp521', 'rsa-sha2-512', 'rsa-sha2-256',
            ],
            'ciphers' => [
                'aes256-gcm@openssh.com', 'chacha20-poly1305@openssh.com',
                'aes128-gcm@openssh.com', 'aes256-ctr', 'aes192-ctr', 'aes128-ctr',
            ],
            'macs' => [
                'hmac-sha2-256-etm@openssh.com', 'hmac-sha2-512-etm@openssh.com',
                'hmac-sha2-256', 'hmac-sha2-512',
            ],
        ];
    }

    /**
     * KEX/cipher/MAC/host-key allowlist for `strict_algorithms`, shaped for
     * phpseclib's `preferredAlgorithms`. Opt-in (default off) since some
     * old/embedded SFTP servers only speak the legacy set — turning this on for
     * such a host will fail the handshake outright rather than silently
     * downgrading. UNCHANGED signature/return shape — now a thin reshape of
     * modernSshAlgorithmLists().
     *
     * @return array{kex:string[],hostkey:string[],client_to_server:array,server_to_client:array}
     */
    private static function modernSshAlgorithms(): array
    {
        $l = self::modernSshAlgorithmLists();
        return [
            'kex' => $l['kex'],
            'hostkey' => $l['hostkey'],
            'client_to_server' => ['crypt' => $l['ciphers'], 'mac' => $l['macs']],
            'server_to_client' => ['crypt' => $l['ciphers'], 'mac' => $l['macs']],
        ];
    }

    /**
     * OpenSSH -o flag pairs for the SAME allowlist, consumed by SshMultiplexer's
     * proc_open argv (see docs/SFTP-CONTROLMASTER-SPEC.md §8/§11). Both this and
     * modernSshAlgorithms() are pure reshapes of modernSshAlgorithmLists(), so
     * there's structurally nothing to forget to update when the allowlist changes.
     *
     * @return string[] flat ['-o','KexAlgorithms=...', '-o','HostKeyAlgorithms=...', ...]
     */
    public static function modernSshOpensshFlags(): array
    {
        $l = self::modernSshAlgorithmLists();
        return [
            '-o', 'KexAlgorithms=' . implode(',', $l['kex']),
            '-o', 'HostKeyAlgorithms=' . implode(',', $l['hostkey']),
            '-o', 'Ciphers=' . implode(',', $l['ciphers']),
            '-o', 'MACs=' . implode(',', $l['macs']),
        ];
    }

    /**
     * The raw phpseclib SFTP connection for a disk + the disk root, so a caller
     * can read/set Unix file modes (chmod) — beyond Flysystem's public/private
     * visibility. Throws for a non-SFTP disk.
     *
     * @return array{0:\phpseclib3\Net\SFTP,1:string} [connection, root]
     */
    public function sftpConnection(string $name): array
    {
        if (!isset($this->configs[$name])) {
            throw new ApiException("Disk '{$name}' is not configured", 400);
        }
        $cfg = $this->configs[$name];
        if (($cfg['driver'] ?? '') !== 'sftp') {
            throw new ApiException("Disk '{$name}' is not an SFTP disk", 400, 'not_sftp');
        }
        $conn = self::buildSftpProvider($cfg)->provideConnection();
        return [$conn, rtrim((string) ($cfg['root'] ?? '/'), '/')];
    }

    /**
     * True iff a disk is eligible for SshMultiplexer::acquire() — `ssh_multiplex`
     * on, SFTP driver, and key-based auth ONLY (no passphrase). A
     * passphrase-protected key falls back to phpseclib for the same reason a
     * password-only config does: OpenSSH's `-i` has no non-interactive way to
     * supply a passphrase (no TTY under proc_open, no local ssh-agent — see
     * buildSftpProvider()'s useAgent comment), so shelling out to `ssh` would
     * need SSH_ASKPASS/sshpass tricks that put the secret in argv/env — the
     * exact exposure this gate exists to avoid. See
     * docs/SFTP-CONTROLMASTER-SPEC.md §7 (extends the security review's F4).
     */
    private static function multiplexEligible(array $cfg): bool
    {
        if (($cfg['driver'] ?? '') !== 'sftp' || empty($cfg['ssh_multiplex'])) {
            return false;
        }
        if (($cfg['private_key'] ?? '') === '') {
            return false; // password-only → sshpass/argv exposure (F4). Fall back.
        }
        if (($cfg['private_key_passphrase'] ?? '') !== '') {
            return false; // passphrase-protected key → same exposure, one level down.
        }
        return true;
    }

    /**
     * SSH ControlMaster connection-reuse handle for `SshTerminal`'s
     * `/api/fm/terminal` ONLY — see docs/SFTP-CONTROLMASTER-SPEC.md. Never call
     * this from GitDeploy or the Flysystem SFTP adapter (out of scope, spec §19).
     *
     * @return array{0:SshMultiplexer,1:string}|null [handle, root], or null →
     *         caller falls back to sftpConnection(). Also covers the
     *         `require_host_key` + no `host_fingerprint` case for free: returning
     *         null here just routes to sftpConnection(), which already throws
     *         `sftp_host_key_required` at that point.
     */
    public function multiplexHandle(string $name): ?array
    {
        $cfg = $this->configs[$name] ?? [];
        if (!self::multiplexEligible($cfg)) {
            return null;
        }
        return [SshMultiplexer::acquire($cfg, $name, $this), rtrim((string) ($cfg['root'] ?? '/'), '/')];
    }
}
