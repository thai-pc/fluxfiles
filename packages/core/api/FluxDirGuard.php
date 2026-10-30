<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Defense in depth for the bookkeeping directory (`_fluxfiles/`) on LOCAL disks.
 *
 * When a disk root sits inside the web root, `_fluxfiles/` (audit log, index,
 * trash, sidecars…) would be served as static files. Whenever core writes into a
 * `_fluxfiles/` directory (disk root or `<prefix>/_fluxfiles/`), this drops a
 * deny-all `.htaccess` (Apache 2.2 + 2.4) and an empty `index.html` (no
 * autoindex) there — only when missing, so an operator-customised file is never
 * touched. nginx ignores .htaccess and still needs a `deny all` location.
 *
 * Best-effort: any failure is logged, never thrown.
 */
final class FluxDirGuard
{
    public const HTACCESS = <<<'HTACCESS'
# FluxFiles bookkeeping (audit log, index, trash, metadata): never web-served.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

HTACCESS;

    /** @var array<string,true> */
    private static array $done = [];

    /**
     * Ensure the guard files exist in the `_fluxfiles` dir that a write to
     * $relPath (disk-relative) lands in. No-op when the path is not under one.
     */
    public static function ensureForPath(string $root, string $relPath): void
    {
        $rel = str_replace('\\', '/', $relPath);
        $segments = explode('/', trim($rel, '/'));
        $idx = array_search('_fluxfiles', $segments, true);
        if ($idx === false) {
            return;
        }
        $dirRel = implode('/', array_slice($segments, 0, $idx + 1));
        self::ensureDir($root, $dirRel);
    }

    /** Ensure the guard files in `<root>/<dirRel>` (dirRel ends in `_fluxfiles`). */
    public static function ensureDir(string $root, string $dirRel): void
    {
        $key = $root . '|' . $dirRel;
        if (isset(self::$done[$key])) {
            return;
        }
        $dir = rtrim($root, '/\\') . '/' . $dirRel;
        if (!is_dir($dir)) {
            return; // nothing was created yet; retry on the next write
        }
        self::$done[$key] = true;
        try {
            // Only PHP reads _fluxfiles/ (audit log, index, trash bytes, versions), so
            // keep the whole subtree closed to other users even on a public disk, whose
            // folders are otherwise 0755 so the web server can serve uploads.
            if ((@fileperms($dir) & 0077) !== 0) {
                @chmod($dir, 0700);
            }
            if (!file_exists($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', self::HTACCESS);
            }
            if (!file_exists($dir . '/index.html')) {
                @file_put_contents($dir . '/index.html', '');
            }
        } catch (\Throwable $e) {
            error_log('FluxFiles: could not write _fluxfiles guard files: ' . $e->getMessage());
        }
    }

    /** Test hook: forget the per-request cache. */
    public static function reset(): void
    {
        self::$done = [];
    }
}
