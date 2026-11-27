<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- core is a WordPress-independent library (it also runs standalone); this writes one small guard file into a local disk root

/**
 * Defense in depth for uploaded files on LOCAL disks served straight by Apache.
 *
 * `.html/.htm/.xhtml/.xml/.svg` uploads are allowed by default, so a file served
 * from the disk root would run as same-origin script. `router.php` / `nginx.conf`
 * already answer those with `CSP: sandbox` + `attachment`; Apache needs the same in
 * an `.htaccess`. On the first write into a local disk root core drops one there
 * (CSP sandbox, nosniff, attachment for the active-content types, no script
 * execution), exactly like FluxDirGuard does for `_fluxfiles/`:
 *   - never overwrites an existing `.htaccess` (an operator's or the WordPress
 *     plugin's file wins);
 *   - skipped when the root has an `index.php` (it is then a site docroot, where
 *     "no PHP" would break the site);
 *   - best-effort, a failure is logged and never thrown.
 * nginx ignores .htaccess; its config must carry the same headers (see nginx.conf).
 */
final class UploadRootGuard
{
    public const FILENAME = '.htaccess';

    public const HTACCESS = <<<'HTACCESS'
# FluxFiles upload-root guard: uploaded files are data, never code.
# (Only FileInfo/AuthConfig-level directives: no php_flag/Options, which a stricter
# AllowOverride would turn into a 500 for every upload.)
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    <FilesMatch "\.(?i:html?|xhtml|shtml|xml|svgz?)$">
        Header always set Content-Security-Policy "sandbox"
        Header always set Content-Disposition "attachment"
    </FilesMatch>
</IfModule>
<FilesMatch "\.(?i:php[0-9]?|phtml|phar|pht|phps)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

HTACCESS;

    private const MARKER = '# FluxFiles upload-root guard';

    /** @var array<string,true> */
    private static array $done = [];
    /** @var array<string,bool> root => is the root .htaccess ours (per-request cache) */
    private static array $ours = [];

    /** Drop the guard into $root when it is missing. */
    public static function ensure(string $root): void
    {
        $root = rtrim($root, '/\\');
        if ($root === '' || isset(self::$done[$root])) {
            return;
        }
        self::$done[$root] = true;
        try {
            if (!is_dir($root) || file_exists($root . '/' . self::FILENAME) || file_exists($root . '/index.php')) {
                return;
            }
            if (@file_put_contents($root . '/' . self::FILENAME, self::HTACCESS) !== false) {
                self::$ours[$root] = true;
            }
        } catch (\Throwable $e) {
            \FluxFiles\Log::warn('FluxFiles: could not write the upload-root guard: ' . $e->getMessage());
        }
    }

    /**
     * Is this DISK-relative key the guard file core wrote at a local disk root (so it is
     * hidden from listings, quota and indexing)? Only OUR file qualifies (marker line):
     * an operator's own root `.htaccess` stays an ordinary, visible file.
     *
     * @param array<string,mixed> $diskConfig
     */
    public static function isRootGuardKey(array $diskConfig, string $key): bool
    {
        if (trim($key, '/') !== self::FILENAME || ($diskConfig['driver'] ?? 'local') !== 'local' || empty($diskConfig['root'])) {
            return false;
        }
        $root = rtrim((string) $diskConfig['root'], '/\\');
        if (!isset(self::$ours[$root])) {
            $head = is_file($root . '/' . self::FILENAME) ? (string) @file_get_contents($root . '/' . self::FILENAME, false, null, 0, 64) : '';
            self::$ours[$root] = str_starts_with($head, self::MARKER);
        }
        return self::$ours[$root];
    }

    /** Test hook: forget the per-request cache. */
    public static function reset(): void
    {
        self::$done = [];
        self::$ours = [];
    }
}
