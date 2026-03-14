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
    int $ttl = 3600
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
