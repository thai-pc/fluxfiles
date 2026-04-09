<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;

/**
 * Generate a JWT token for FluxFiles.
 */
function fluxfiles_token(
    string $userId,
    array $perms = ['read'],
    array $disks = ['local'],
    string $prefix = '',
    int $maxUploadMb = 10,
    ?array $allowedExt = null,
    int $ttl = 3600,
    bool $ownerOnly = false
): string {
    $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
    $now = time();

    $payload = [
        'sub'         => $userId,
        'iat'         => $now,
        'exp'         => $now + $ttl,
        'jti'         => bin2hex(random_bytes(12)),
        'perms'       => $perms,
        'disks'       => $disks,
        'prefix'      => $prefix,
        'max_upload'  => $maxUploadMb,
        'allowed_ext' => $allowedExt,
    ];

    if ($ownerOnly) {
        $payload['owner_only'] = true;
    }

    return JWT::encode($payload, $secret, 'HS256');
}

/**
 * Generate a BYOB (Bring Your Own Bucket) JWT token.
 *
 * Users provide their own S3/R2 credentials, which are AES-256-GCM encrypted
 * inside the JWT. The FluxFiles server decrypts them at runtime to access
 * the user's own storage bucket.
 *
 * @param string $userId
 * @param array  $byobDisks Map of disk name => config array.
 *                          Each config: ['driver'=>'s3', 'key'=>..., 'secret'=>..., 'bucket'=>..., 'region'=>..., 'endpoint'=>...]
 * @param array  $perms     Permissions (read, write, delete)
 * @param string $prefix    Path prefix scope
 * @param int    $maxUploadMb
 * @param array|null $allowedExt
 * @param int    $ttl       Token TTL (default 1800s — shorter for security)
 * @return string JWT token
 */
function fluxfiles_byob_token(
    string $userId,
    array $byobDisks,
    array $perms = ['read', 'write'],
    string $prefix = '',
    int $maxUploadMb = 10,
    ?array $allowedExt = null,
    int $ttl = 1800,
    bool $ownerOnly = false
): string {
    $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
    $now = time();

    // Encrypt each BYOB disk config
    $encryptedDisks = [];
    $diskNames = [];
    foreach ($byobDisks as $name => $config) {
        // Validate before encrypting
        \FluxFiles\CredentialEncryptor::validate($name, $config);
        $encryptedDisks[$name] = \FluxFiles\CredentialEncryptor::encrypt($config, $secret);
        $diskNames[] = $name;
    }

    $payload = [
        'sub'         => $userId,
        'iat'         => $now,
        'exp'         => $now + $ttl,
        'jti'         => bin2hex(random_bytes(12)),
        'perms'       => $perms,
        'disks'       => $diskNames,
        'prefix'      => $prefix,
        'max_upload'  => $maxUploadMb,
        'allowed_ext' => $allowedExt,
        'byob_disks'  => $encryptedDisks,
    ];

    if ($ownerOnly) {
        $payload['owner_only'] = true;
    }

    return JWT::encode($payload, $secret, 'HS256');
}

/**
 * Generate a mixed-mode token: some server disks + some BYOB disks.
 *
 * @param string $userId
 * @param array  $serverDisks Server-side disk names (e.g. ['local'])
 * @param array  $byobDisks  BYOB disk configs (e.g. ['my-s3' => [...]])
 * @param array  $perms
 * @param string $prefix
 * @param int    $maxUploadMb
 * @param array|null $allowedExt
 * @param int    $ttl
 * @return string JWT token
 */
function fluxfiles_mixed_token(
    string $userId,
    array $serverDisks,
    array $byobDisks,
    array $perms = ['read', 'write'],
    string $prefix = '',
    int $maxUploadMb = 10,
    ?array $allowedExt = null,
    int $ttl = 1800,
    bool $ownerOnly = false
): string {
    $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
    $now = time();

    // Encrypt BYOB disks
    $encryptedDisks = [];
    foreach ($byobDisks as $name => $config) {
        \FluxFiles\CredentialEncryptor::validate($name, $config);
        $encryptedDisks[$name] = \FluxFiles\CredentialEncryptor::encrypt($config, $secret);
    }

    // Merge disk names: server disks + BYOB disk names
    $allDisks = array_merge($serverDisks, array_keys($byobDisks));

    $payload = [
        'sub'         => $userId,
        'iat'         => $now,
        'exp'         => $now + $ttl,
        'jti'         => bin2hex(random_bytes(12)),
        'perms'       => $perms,
        'disks'       => $allDisks,
        'prefix'      => $prefix,
        'max_upload'  => $maxUploadMb,
        'allowed_ext' => $allowedExt,
        'byob_disks'  => !empty($encryptedDisks) ? $encryptedDisks : null,
    ];

    if ($ownerOnly) {
        $payload['owner_only'] = true;
    }

    return JWT::encode($payload, $secret, 'HS256');
}

/**
 * Render the FluxFiles iframe embed tag.
 */
function fluxfiles_embed(
    string $endpoint,
    string $token,
    string $disk = 'local',
    string $mode = 'picker',
    string $width = '100%',
    string $height = '600px'
): string {
    $endpoint = htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8');
    $token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    $disk = htmlspecialchars($disk, ENT_QUOTES, 'UTF-8');
    $mode = htmlspecialchars($mode, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div id="fluxfiles-container" style="width:{$width};height:{$height}">
    <iframe id="fluxfiles-iframe"
            src="{$endpoint}/public/index.html"
            style="width:100%;height:100%;border:none;"
            allow="clipboard-write"></iframe>
</div>
<script src="{$endpoint}/fluxfiles.js"></script>
<script>
FluxFiles.open({
    endpoint: "{$endpoint}",
    token: "{$token}",
    disk: "{$disk}",
    mode: "{$mode}",
    container: "#fluxfiles-container"
});
</script>
HTML;
}
