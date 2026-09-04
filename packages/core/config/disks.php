<?php

declare(strict_types=1);

$disks = [
    'local' => [
        'driver' => 'local',
        'root'   => __DIR__ . '/../storage/uploads',
        'url'    => '/storage/uploads',
        // Gated media: when true, files are served through per-file /api/fm/stream
        // tokens (Range-capable) instead of the static `url`. The disk root must
        // then NOT be served statically by the web server. Default off.
        'private' => ($_ENV['FLUXFILES_LOCAL_PRIVATE'] ?? '') === 'true',
    ],
    's3' => [
        'driver'     => 's3',
        'region'     => $_ENV['AWS_DEFAULT_REGION'] ?? 'ap-southeast-1',
        'bucket'     => $_ENV['AWS_BUCKET'] ?? '',
        'key'        => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
        'secret'     => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
        // Custom S3-compatible endpoint (MinIO, DigitalOcean Spaces, …). Empty =
        // native AWS S3. When set, path-style addressing is used automatically.
        'endpoint'   => $_ENV['AWS_ENDPOINT'] ?? '',
        // 'public'  → direct object URLs (writes use public-read ACL).
        // 'private' → short-lived presigned GET URLs (default; safe on private buckets).
        'visibility' => $_ENV['AWS_VISIBILITY'] ?? 'private',
        // Optional CDN / custom domain base for public disks, e.g. https://cdn.example.com
        'public_url' => $_ENV['AWS_PUBLIC_URL'] ?? '',
        // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h, max 24h.
        // Media files can override this per tenant with the `preview_url_ttl` claim.
        'url_ttl'    => (int) ($_ENV['AWS_URL_TTL'] ?? 3600),
    ],
    'r2' => [
        'driver'     => 's3',
        'endpoint'   => 'https://' . ($_ENV['R2_ACCOUNT_ID'] ?? '') . '.r2.cloudflarestorage.com',
        'region'     => 'auto',
        'bucket'     => $_ENV['R2_BUCKET'] ?? '',
        'key'        => $_ENV['R2_ACCESS_KEY_ID'] ?? '',
        'secret'     => $_ENV['R2_SECRET_ACCESS_KEY'] ?? '',
        // R2 has no object ACLs: 'public' relies on a public bucket + public_url
        // (r2.dev or a custom domain); 'private' (default) serves presigned GET URLs.
        'visibility' => $_ENV['R2_VISIBILITY'] ?? 'private',
        'public_url' => $_ENV['R2_PUBLIC_URL'] ?? '',
        // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h, max 24h.
        'url_ttl'    => (int) ($_ENV['R2_URL_TTL'] ?? 3600),
    ],
];

// SFTP disk — only registered when SFTP_HOST is set (a 3rd disk driver for VPS /
// shared hosting). Connect/disconnect per request; auth is password OR private
// key. Files are served through the app (no presigned URL), so size is capped.
if (($_ENV['SFTP_HOST'] ?? '') !== '') {
    $disks['sftp'] = [
        'driver'                 => 'sftp',
        'host'                   => $_ENV['SFTP_HOST'],
        'port'                   => (int) ($_ENV['SFTP_PORT'] ?? 22),
        'username'               => $_ENV['SFTP_USERNAME'] ?? '',
        'password'               => $_ENV['SFTP_PASSWORD'] ?? '',
        'private_key'            => $_ENV['SFTP_PRIVATE_KEY'] ?? '',
        'private_key_passphrase' => $_ENV['SFTP_PRIVATE_KEY_PASSPHRASE'] ?? '',
        'root'                   => $_ENV['SFTP_ROOT'] ?? '/',
        // Host-key pinning (recommended): the expected fingerprint(s) of the
        // server's host key — without it the client trusts ANY host key (MITM
        // risk). Colon-hex form (e.g. `aa:bb:..`); md5 for an RSA host key,
        // sha512 otherwise. Comma-separate to allow several (key rotation).
        'host_fingerprint'       => $_ENV['SFTP_HOST_FINGERPRINT'] ?? '',
        // Fail closed if host_fingerprint above isn't set, instead of silently
        // trusting any host key. Off by default (backward-compatible).
        'require_host_key'       => ($_ENV['SFTP_REQUIRE_HOST_KEY'] ?? '') === 'true',
        // Restrict the SSH handshake to modern KEX/cipher/MAC/host-key algorithms
        // only (no SHA-1 KEX, RC4, 3DES, CBC ciphers, ssh-dss, MD5/SHA-1 MACs).
        // Off by default — some old/embedded SFTP servers only speak the legacy
        // set and would fail to connect at all with this on.
        'strict_algorithms'      => ($_ENV['SFTP_STRICT_ALGORITHMS'] ?? '') === 'true',
        // Reuse an OpenSSH ControlMaster session across terminal commands on this
        // disk instead of reconnecting per command. Off by default. Key-based auth
        // only — a password-only (or passphrase-protected-key) config silently
        // falls back to the existing per-request phpseclib path. See
        // docs/SFTP-CONTROLMASTER-SPEC.md.
        'ssh_multiplex'          => ($_ENV['SFTP_MULTIPLEX'] ?? '') === 'true',
    ];
}

return $disks;
