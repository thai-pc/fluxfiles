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
        if (\class_exists('\\FluxFiles\\SsrfGuard')) {
            SsrfGuard::assertHostSafe($host);
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

        return \League\Flysystem\PhpseclibV3\SftpConnectionProvider::fromArray([
            'host'            => $host,
            'username'        => (string) ($cfg['username'] ?? ''),
            'password'        => ($cfg['password'] ?? '') !== '' ? (string) $cfg['password'] : null,
            'privateKey'      => ($cfg['private_key'] ?? '') !== '' ? (string) $cfg['private_key'] : null,
            'passphrase'      => ($cfg['private_key_passphrase'] ?? '') !== '' ? (string) $cfg['private_key_passphrase'] : null,
            'port'            => (int) ($cfg['port'] ?? 22),
            'useAgent'        => false, // never reach for a local ssh-agent on the server
            'hostFingerprint' => $hostFingerprint,
            'timeout'         => (int) ($cfg['timeout'] ?? 20),
            'maxTries'        => 2,
        ]);
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
}
