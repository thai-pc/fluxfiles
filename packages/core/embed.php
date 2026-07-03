<?php

declare(strict_types=1);

require __DIR__ . '/autoload.php';

use FluxFiles\JwtCompat;

/**
 * Generate a JWT token for FluxFiles.
 */
/**
 * @param string     $userId       Subject (your app's user id).
 * @param string[]   $perms        Any of: read, write, delete.
 * @param string[]   $disks        Disks this token may use (e.g. ['local','s3']).
 * @param string     $prefix       Path scope — user is sandboxed under this prefix.
 * @param int        $maxUploadMb  Max size PER uploaded file, in MEGABYTES (MB).
 * @param ?string[]  $allowedExt   Allowed extensions (lowercase, no dot, e.g.
 *                                 ['jpg','png']). null = allow all non-dangerous types.
 * @param int        $ttl          Token lifetime in SECONDS (exp = now + ttl).
 * @param bool       $ownerOnly    Restrict delete/rename/move to the uploader.
 * @param int        $maxStorageMb Total storage quota for the prefix, in MEGABYTES
 *                                 (MB). 0 = unlimited.
 * @param int        $maxFiles     Max number of files allowed under the prefix.
 *                                 0 = unlimited.
 */
function fluxfiles_token(
    string|array $userId,
    array $perms = ['read'],
    array $disks = ['local'],
    string $prefix = '',
    int $maxUploadMb = 10,
    ?array $allowedExt = null,
    int $ttl = 3600,
    bool $ownerOnly = false,
    int $maxStorageMb = 0,
    int $maxFiles = 0,
    ?bool $aiAutoTag = null,
    int $rateRead = 0,
    int $rateWrite = 0,
    ?array $variants = null,
    ?array $import = null,
    ?array $media = null,
    ?array $webp = null,
    ?array $usage = null,
    ?string $edition = null,
    ?array $extra = null
): string {
    // Recommended form: ONE options array (every claim in one place). Pass an array
    // as the first arg, e.g.
    //   fluxfiles_token(['user' => 'u', 'perms' => ['read','write'], 'disks' => ['sftp'],
    //                    'edition' => 'pro', 'webp' => [...], 'media' => [...],
    //                    'claims' => ['allow_terminal' => true, 'terminal_pty_url' => '…',
    //                                 'allow_optimize' => true, 'upload_collision' => 'overwrite']]);
    // The `claims` map takes ANY of the JWT claims (snake_case) — the single escape
    // hatch so nothing is unsettable. See docs/CONFIG.md for the full claim list.
    if (is_array($userId)) {
        return _fluxfiles_build_token($userId);
    }
    // Legacy positional form → fold into the same options builder. `$extra` is a raw
    // claims map (snake_case) merged last, so positional callers also have the hatch.
    return _fluxfiles_build_token([
        'user' => $userId, 'perms' => $perms, 'disks' => $disks, 'prefix' => $prefix,
        'maxUploadMb' => $maxUploadMb, 'allowedExt' => $allowedExt, 'ttl' => $ttl,
        'ownerOnly' => $ownerOnly, 'maxStorageMb' => $maxStorageMb, 'maxFiles' => $maxFiles,
        'aiAutoTag' => $aiAutoTag, 'rateRead' => $rateRead, 'rateWrite' => $rateWrite,
        'variants' => $variants, 'import' => $import, 'media' => $media, 'webp' => $webp,
        'usage' => $usage, 'edition' => $edition, 'claims' => $extra,
    ]);
}

/**
 * Build + sign a token from ONE options array. Accepts both camelCase option names
 * (maxUploadMb) and snake_case claim aliases (max_upload). The `claims` (or `extra`)
 * key is a raw map of any JWT claims merged last — explicit claims win. Decode-side
 * `Claims::fromJwtPayload` sanitizes/clamps everything, so raw merge is safe.
 *
 * @param array<string,mixed> $o
 */
function _fluxfiles_build_token(array $o): string
{
    $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
    $now = time();
    $pick = static function (array $keys, $default) use ($o) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $o)) { return $o[$k]; }
        }
        return $default;
    };

    $ttl = (int) $pick(['ttl'], 3600);
    $payload = [
        'sub'         => (string) $pick(['user', 'userId', 'sub'], ''),
        'iat'         => $now,
        'exp'         => $now + $ttl,
        'jti'         => bin2hex(random_bytes(12)),
        'perms'       => $pick(['perms'], ['read']),
        'disks'       => $pick(['disks'], ['local']),
        'prefix'      => (string) $pick(['prefix'], ''),
        'max_upload'  => (int) $pick(['maxUploadMb', 'max_upload'], 10),
        'allowed_ext' => $pick(['allowedExt', 'allowed_ext'], null),
        'max_storage' => (int) $pick(['maxStorageMb', 'max_storage'], 0),
        'max_files'   => (int) $pick(['maxFiles', 'max_files'], 0),
    ];

    if ($pick(['ownerOnly', 'owner_only'], false)) {
        $payload['owner_only'] = true;
    }
    // Edition preset (DX sugar): turns on a tier's claims; explicit claims still win.
    fluxfiles_apply_edition_preset($payload, $pick(['edition'], null));

    $aiAutoTag = $pick(['aiAutoTag', 'ai_auto_tag'], null);
    if ($aiAutoTag !== null) {
        $payload['ai_auto_tag'] = (bool) $aiAutoTag;
    }
    $rateRead = (int) $pick(['rateRead', 'rate_read'], 0);
    if ($rateRead > 0) {
        $payload['rate_read'] = $rateRead;
    }
    $rateWrite = (int) $pick(['rateWrite', 'rate_write'], 0);
    if ($rateWrite > 0) {
        $payload['rate_write'] = $rateWrite;
    }
    $cleanVariants = \FluxFiles\Claims::sanitizeVariants($pick(['variants'], null));
    if ($cleanVariants !== null) {
        $payload['variants'] = $cleanVariants;
    }
    // Themed claim groups (Claims::fromJwtPayload sanitizes/clamps these on decode).
    $grp = static fn ($v): array => is_array($v) ? $v : [];
    fluxfiles_apply_import_claims($payload, $grp($pick(['import'], [])));
    fluxfiles_apply_media_claims($payload, $grp($pick(['media'], [])));
    fluxfiles_apply_webp_claims($payload, $grp($pick(['webp'], [])));
    fluxfiles_apply_usage_claims($payload, $grp($pick(['usage'], [])));

    // Generic escape hatch — ANY claim by its raw (snake_case) name. Merged last so
    // an explicit claim overrides a preset/group default. Null values are skipped.
    $claims = $pick(['claims', 'extra'], null);
    if (is_array($claims)) {
        foreach ($claims as $k => $v) {
            if ($v !== null) { $payload[(string) $k] = $v; }
        }
    }

    return JwtCompat::encode($payload, $secret);
}

/**
 * Apply an edition preset's default claims onto $payload (DX sugar). Only sets a
 * claim when it's not already present, so explicit overrides win. The license is
 * still the real gate — a preset just defaults the per-tenant claims for a tier.
 *
 * free → nothing · pro → optimize + share · agency → pro + (future) · enterprise → all.
 *
 * @param array<string,mixed> $payload
 */
function fluxfiles_apply_edition_preset(array &$payload, ?string $edition): void
{
    $presets = [
        'pro'        => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true],
        'agency'     => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true],
        'enterprise' => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true, 'allow_virus_scan' => true, 'allow_c2pa' => true],
    ];
    $claims = $presets[strtolower((string) $edition)] ?? [];
    foreach ($claims as $k => $v) {
        if (!array_key_exists($k, $payload)) {
            $payload[$k] = $v;
        }
    }
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
    bool $ownerOnly = false,
    ?array $import = null,
    ?array $media = null,
    ?array $webp = null,
    ?array $usage = null
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
    fluxfiles_apply_import_claims($payload, $import ?? []);
    fluxfiles_apply_media_claims($payload, $media ?? []);
    fluxfiles_apply_webp_claims($payload, $webp ?? []);
    fluxfiles_apply_usage_claims($payload, $usage ?? []);

    return JwtCompat::encode($payload, $secret);
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
    bool $ownerOnly = false,
    ?array $import = null,
    ?array $media = null,
    ?array $webp = null,
    ?array $usage = null
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
    fluxfiles_apply_import_claims($payload, $import ?? []);
    fluxfiles_apply_media_claims($payload, $media ?? []);
    fluxfiles_apply_webp_claims($payload, $webp ?? []);
    fluxfiles_apply_usage_claims($payload, $usage ?? []);

    return JwtCompat::encode($payload, $secret);
}

/**
 * Forward URL-import claims from an options array into a token payload, when set.
 * The server (Claims::fromJwtPayload) sanitizes/clamps these on decode, so the
 * mint side only copies them — no hard dependency on a core method.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $import allow_url_import, max_import_mb,
 *        import_url_allowlist, import_path, import_rate_limit, import_concurrency
 */
function fluxfiles_apply_import_claims(array &$payload, array $import): void
{
    if (!empty($import['allow_url_import'])) {
        $payload['allow_url_import'] = true;
    }
    foreach (['max_import_mb', 'import_rate_limit', 'import_concurrency'] as $k) {
        if (!empty($import[$k])) {
            $payload[$k] = (int) $import[$k];
        }
    }
    if (!empty($import['import_path'])) {
        $payload['import_path'] = (string) $import['import_path'];
    }
    if (!empty($import['import_url_allowlist']) && is_array($import['import_url_allowlist'])) {
        $payload['import_url_allowlist'] = array_values($import['import_url_allowlist']);
    }
}

/**
 * Forward media-preview claims from an options array into a token payload, when set.
 * Claims::fromJwtPayload sanitizes/clamps these on decode.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $media media_preview (bool), preview_url_ttl (int),
 *        max_preview_mb (int), stream_token_ttl (int)
 */
function fluxfiles_apply_media_claims(array &$payload, array $media): void
{
    // media_preview is a tri-state: only embed it when explicitly provided, so an
    // unset claim inherits the default (true).
    if (array_key_exists('media_preview', $media)) {
        $payload['media_preview'] = (bool) $media['media_preview'];
    }
    foreach (['preview_url_ttl', 'max_preview_mb', 'stream_token_ttl'] as $k) {
        if (!empty($media[$k])) {
            $payload[$k] = (int) $media[$k];
        }
    }
}

/**
 * Forward image-serving claims into a token payload, when set: on-demand WebP
 * (`webp_*`), the download gate (`allow_download`), and watermark (`watermark_*`).
 * The core sanitizes/clamps these on decode.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $webp webp_enabled, webp_max_width, webp_default_quality,
 *        srcset_widths, srcset_sizes, allow_download, watermark_enabled,
 *        watermark_type, watermark_text, watermark_logo_path, watermark_position,
 *        watermark_opacity, watermark_font_size
 */
function fluxfiles_apply_webp_claims(array &$payload, array $webp): void
{
    if (array_key_exists('webp_enabled', $webp)) {
        $payload['webp_enabled'] = (bool) $webp['webp_enabled'];
    }
    foreach (['webp_max_width', 'webp_default_quality'] as $k) {
        if (!empty($webp[$k])) {
            $payload[$k] = (int) $webp[$k];
        }
    }
    // Responsive srcset ladder + sizes (Claims sanitizes the ladder on decode).
    if (isset($webp['srcset_widths']) && is_array($webp['srcset_widths'])) {
        $payload['srcset_widths'] = array_values(array_map('intval', $webp['srcset_widths']));
    }
    if (!empty($webp['srcset_sizes'])) {
        $payload['srcset_sizes'] = (string) $webp['srcset_sizes'];
    }
    // Paid-module claims (3-layer gate; inert unless the module is installed+licensed).
    foreach (['allow_share', 'allow_intake', 'allow_versioning', 'allow_ai_vision', 'allow_ocr', 'allow_virus_scan', 'allow_backup', 'allow_c2pa'] as $mc) {
        if (array_key_exists($mc, $webp)) {
            $payload[$mc] = (bool) $webp[$mc];
        }
    }
    // Access gates (download / chmod) + watermark.
    if (array_key_exists('allow_download', $webp)) {
        $payload['allow_download'] = (bool) $webp['allow_download'];
    }
    if (array_key_exists('allow_chmod', $webp)) {
        $payload['allow_chmod'] = (bool) $webp['allow_chmod'];
    }
    if (array_key_exists('allow_terminal', $webp)) {
        $payload['allow_terminal'] = (bool) $webp['allow_terminal'];
    }
    if (!empty($webp['terminal_pty_url'])) {
        $payload['terminal_pty_url'] = (string) $webp['terminal_pty_url'];
    }
    if (array_key_exists('allow_code_edit', $webp)) {
        $payload['allow_code_edit'] = (bool) $webp['allow_code_edit'];
    }
    if (array_key_exists('allow_optimize', $webp)) {
        $payload['allow_optimize'] = (bool) $webp['allow_optimize'];
    }
    if (array_key_exists('auto_optimize', $webp)) {
        $payload['auto_optimize'] = (bool) $webp['auto_optimize'];
    }
    if (!empty($webp['optimize_quality'])) {
        $payload['optimize_quality'] = (int) $webp['optimize_quality'];
    }
    if (array_key_exists('optimize_keep_original', $webp)) {
        $payload['optimize_keep_original'] = (bool) $webp['optimize_keep_original'];
    }
    if (!empty($webp['optimize_max_mb'])) {
        $payload['optimize_max_mb'] = (int) $webp['optimize_max_mb'];
    }
    if (!empty($webp['versioning_max'])) {
        $payload['versioning_max'] = (int) $webp['versioning_max'];
    }
    if (!empty($webp['versioning_max_mb'])) {
        $payload['versioning_max_mb'] = (int) $webp['versioning_max_mb'];
    }
    if (isset($webp['pdf_level'])
        && in_array($webp['pdf_level'], ['screen', 'ebook', 'printer', 'prepress', 'default'], true)) {
        $payload['pdf_level'] = (string) $webp['pdf_level'];
    }
    if (isset($webp['upload_collision'])
        && in_array($webp['upload_collision'], ['rename', 'overwrite', 'reject'], true)) {
        $payload['upload_collision'] = (string) $webp['upload_collision'];
    }
    if (array_key_exists('show_hidden', $webp)) {
        $payload['show_hidden'] = (bool) $webp['show_hidden'];
    }
    if (array_key_exists('dedupe_uploads', $webp)) {
        $payload['dedupe_uploads'] = (bool) $webp['dedupe_uploads'];
    }
    if (array_key_exists('allow_zip', $webp)) {
        $payload['allow_zip'] = (bool) $webp['allow_zip'];
    }
    if (array_key_exists('allow_extract', $webp)) {
        $payload['allow_extract'] = (bool) $webp['allow_extract'];
    }
    foreach (['zip_max_mb', 'zip_max_files'] as $k) {
        if (!empty($webp[$k])) {
            $payload[$k] = (int) $webp[$k];
        }
    }
    if (!empty($webp['watermark_enabled'])) {
        $payload['watermark_enabled'] = true;
        foreach (['watermark_type', 'watermark_text', 'watermark_logo_path', 'watermark_position'] as $s) {
            if (!empty($webp[$s])) {
                $payload[$s] = (string) $webp[$s];
            }
        }
        if (isset($webp['watermark_opacity'])) {
            $payload['watermark_opacity'] = (float) $webp['watermark_opacity'];
        }
        if (!empty($webp['watermark_font_size'])) {
            $payload['watermark_font_size'] = (int) $webp['watermark_font_size'];
        }
    }
}

/**
 * Forward usage-dashboard claims into a token payload, when set.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $usage usage_cache_ttl, usage_warning_threshold,
 *        usage_critical_threshold, usage_top_folders_count, usage_folder_depth
 */
function fluxfiles_apply_usage_claims(array &$payload, array $usage): void
{
    foreach ([
        'usage_cache_ttl', 'usage_warning_threshold', 'usage_critical_threshold',
        'usage_top_folders_count', 'usage_folder_depth',
    ] as $k) {
        if (isset($usage[$k]) && $usage[$k] !== '') {
            $payload[$k] = (int) $usage[$k];
        }
    }
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
