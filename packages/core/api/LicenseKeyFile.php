<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.WP.AlternativeFunctions -- server-local runtime state of the core library, which also runs outside WordPress

/**
 * The server-local file where `fluxfiles license refresh` keeps the newest key of the
 * operator's subscription (design: docs/design/LICENSE-KEY-REFRESH-DESIGN.md).
 *
 * The file is an OVERLAY on `FLUXFILES_LICENSE_KEY`, never a licence source of its own, and
 * {@see effectiveKey()} uses it only when ALL of these hold:
 *   1. the env key is set and verifies;
 *   2. the file parses, its key verifies, and its `anchor` is the sha256 of the (whitespace
 *      stripped) env key it was derived from;
 *   3. the file key's signed `issued` is strictly greater than the env key's.
 * So an operator who pastes a different or newer key wins at once, and a leftover file from
 * another licence can never bleed in. A missing, corrupt or foreign file is ignored silently
 * and the env key is used exactly as before.
 *
 * Runtime stays offline: this reads one local file (a stat() per call; verification only
 * when the file changed). Only the CLI ever writes it.
 */
final class LicenseKeyFile
{
    public const MAX_BYTES = 16384;
    /** File name of the default location, `<storage>/license/license-refresh.json`. */
    public const FILE = 'license-refresh.json';

    /** @var array<string,array{key:string,source:string,fetched_at:?int}> per-process memo */
    private static array $memo = [];

    /** Strip every whitespace character (a pasted key often carries a newline). */
    public static function clean(#[\SensitiveParameter] string $key): string
    {
        return (string) preg_replace('/\s+/', '', $key);
    }

    public static function anchor(#[\SensitiveParameter] string $envKey): string
    {
        return 'sha256:' . hash('sha256', self::clean($envKey));
    }

    /** The file path every caller (CLI and runtime) agrees on. */
    public static function path(array $env): string
    {
        $explicit = trim((string) ($env['FLUXFILES_LICENSE_FILE'] ?? ''));
        if ($explicit !== '' && self::isAbsolute($explicit)) {
            return $explicit;
        }
        $storage = rtrim((string) ($env['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage')), '/');
        return $storage . '/license/' . self::FILE;
    }

    /** A message when FLUXFILES_LICENSE_FILE is set but relative (and therefore ignored), else null. */
    public static function pathWarning(array $env): ?string
    {
        $explicit = trim((string) ($env['FLUXFILES_LICENSE_FILE'] ?? ''));
        return $explicit !== '' && !self::isAbsolute($explicit)
            ? 'FLUXFILES_LICENSE_FILE must be an absolute path; ignoring it.'
            : null;
    }

    private static function isAbsolute(string $p): bool
    {
        return $p[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $p) === 1;
    }

    /**
     * Parse the file without verifying anything. Null when absent, unreadable, oversized or
     * not the expected shape.
     *
     * @return array{anchor:string,key:string,fetched_at:?int}|null
     */
    public static function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            return null;
        }
        $raw = @file_get_contents($path);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j) || ($j['v'] ?? null) !== 1 || !is_string($j['anchor'] ?? null) || !is_string($j['key'] ?? null)) {
            return null;
        }
        return [
            'anchor'     => $j['anchor'],
            'key'        => self::clean($j['key']),
            'fetched_at' => is_int($j['fetched_at'] ?? null) ? $j['fetched_at'] : null,
        ];
    }

    /**
     * Which key is in effect, and where it came from. `source`: env | refreshed |
     * unreadable (a file exists but is corrupt or does not verify; the env key is used).
     *
     * @return array{key:string,source:string,fetched_at:?int}
     */
    public static function resolve(array $env): array
    {
        $raw = isset($env['FLUXFILES_LICENSE_KEY']) ? (string) $env['FLUXFILES_LICENSE_KEY'] : '';
        $envKey = self::clean($raw);
        $none = ['key' => $raw, 'source' => 'env', 'fetched_at' => null];
        if ($envKey === '') {
            return $none;
        }
        $path = self::path($env);
        clearstatcache(true, $path);
        $st = @stat($path);
        if ($st === false) {
            return $none;
        }
        $memoKey = hash('sha256', $envKey) . '|' . $path . '|' . $st['ino'] . '|' . $st['mtime'] . '|' . $st['size'];
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }
        $decided = self::decide($raw, $envKey, $path, $none);
        // A file touched within the last 2 s is not memoised: an in-place edit in the same
        // second would keep the same (inode, mtime, size) and serve a stale answer.
        if (time() - $st['mtime'] >= 2) {
            if (count(self::$memo) > 16) {
                self::$memo = [];
            }
            self::$memo[$memoKey] = $decided;
        }
        return $decided;
    }

    /** @param array{key:string,source:string,fetched_at:?int} $none */
    private static function decide(#[\SensitiveParameter] string $raw, #[\SensitiveParameter] string $envKey, string $path, array $none): array
    {
        $envLm = new LicenseManager($envKey);
        if (!$envLm->isVerified()) {
            return $none;
        }
        $file = self::read($path);
        if ($file === null) {
            return ['key' => $raw, 'source' => 'unreadable', 'fetched_at' => null];
        }
        if (!hash_equals(self::anchor($envKey), $file['anchor'])) {
            // derived from some other key: not ours (unless it is corrupt, which we report)
            return (new LicenseManager($file['key']))->isVerified() ? $none : ['key' => $raw, 'source' => 'unreadable', 'fetched_at' => null];
        }
        if (!(new LicenseManager($file['key']))->isVerified()) {
            return ['key' => $raw, 'source' => 'unreadable', 'fetched_at' => null];
        }
        // Same rules as a live refresh: paid edition, issued strictly newer, expiry not earlier.
        if (!LicenseRefresh::accept($envKey, $file['key'])) {
            return $none;
        }
        return ['key' => $file['key'], 'source' => 'refreshed', 'fetched_at' => $file['fetched_at']];
    }

    /** The key to verify: the refreshed one when the overlay rules allow it, else the env key as given. */
    public static function effectiveKey(array $env): string
    {
        return self::resolve($env)['key'];
    }

    /**
     * Store a verified key atomically: flock, re-read (two crons can race and the newer key
     * must survive), write a temp file in the same directory, rename over the target.
     *
     * Permissions: an existing file keeps its mode and group, capped at 0640. A NEW file is
     * 0600, except when the directory belongs to another group than this process (the CLI
     * runs as root or a deploy user, PHP-FPM as www-data): it then gets the directory's group
     * (when we may) and 0640, so the web user can read it. The lock file follows the same
     * rule with 0640 (it is only ever flock()ed; a locker that cannot open it for writing falls back to read-only).
     *
     * @return string 'written' | 'kept' (the file already held a key at least as good)
     * @throws \RuntimeException with a message that never contains the key
     */
    public static function store(string $path, #[\SensitiveParameter] string $key, string $anchor): string
    {
        $key = self::clean($key);
        $lm = new LicenseManager($key);
        if (!$lm->isVerified() || $lm->edition() === 'free') {
            throw new \RuntimeException('refusing to store a key that does not verify');
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            // A directory we create: 0750, or 0770 with the parent's group when the CLI runs as
            // another group than PHP-FPM (then the web user can reach and, if it ever refreshes,
            // write in it). Created up to the nearest existing ancestor.
            $parent = $dir;
            while (!is_dir($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            $parentGid = self::sharedGid($parent);
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('licence file directory is not writable');
            }
            if ($parentGid !== null && @chgrp($dir, $parentGid)) {
                @chmod($dir, 0770);
            }
        }
        $dirGid = self::sharedGid($dir);

        $old = umask(0077);
        try {
            $lockPath = $path . '.lock';
            $fresh = !file_exists($lockPath);
            $lock = @fopen($lockPath, 'c') ?: @fopen($lockPath, 'r');   // flock needs no write access
            if ($lock === false) {
                throw new \RuntimeException('could not open the licence lock file (is it owned by another user?)');
            }
            // Bounded wait (~30 s): a stuck holder or an NFS mount must not hang the CLI forever.
            $locked = false;
            for ($i = 0; $i < 300 && !($locked = flock($lock, LOCK_EX | LOCK_NB)); $i++) {
                usleep(100000);
            }
            if (!$locked) {
                fclose($lock);
                throw new \RuntimeException('could not lock the licence file (another refresh is running?)');
            }
            if ($fresh && $dirGid !== null && @chgrp($lockPath, $dirGid)) {
                @chmod($lockPath, 0640);   // never written by a locker, so group-read is enough
            }
            $tmp = null;
            try {
                self::sweepTemp($dir);
                $existing = self::read($path);
                if ($existing !== null && hash_equals($anchor, $existing['anchor']) && LicenseRefresh::accept($existing['key'], $key) === false
                    && (new LicenseManager($existing['key']))->isVerified()) {
                    return 'kept';
                }

                $mode = 0600;
                $gid = null;
                $st = @stat($path);
                if ($st !== false) {
                    $mode = ($st['mode'] & 0640) ?: 0600;
                    $gid = $st['gid'];
                } elseif ($dirGid !== null) {
                    $gid = $dirGid;
                    $mode = 0640;
                }

                $json = json_encode([
                    'v'          => 1,
                    'anchor'     => $anchor,
                    'key'        => $key,
                    'jti'        => $lm->id(),
                    'issued'     => $lm->issuedAt(),
                    'expires'    => $lm->expiresAt(),
                    'fetched_at' => time(),
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $tmp = @tempnam($dir, '.license-refresh-');
                if ($json === false || $tmp === false || @file_put_contents($tmp, $json . "\n") === false) {
                    throw new \RuntimeException('could not write the licence file');
                }
                if ($gid !== null && !@chgrp($tmp, $gid)) {
                    $mode = 0600;   // cannot keep or share the group: stay private to the owner
                }
                @chmod($tmp, $mode);
                if (!@rename($tmp, $path)) {
                    throw new \RuntimeException('could not replace the licence file');
                }
                $tmp = null;
                self::guardDir($dir, basename($dir) === 'license' && basename($path) === self::FILE);
                return 'written';
            } finally {
                if (is_string($tmp)) {
                    @unlink($tmp);
                }
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            umask($old);
        }
    }

    /** The directory's group when it differs from this process's primary group, else null. */
    private static function sharedGid(string $dir): ?int
    {
        $st = @stat($dir);
        if ($st === false || !function_exists('posix_getegid') || $st['gid'] === posix_getegid()) {
            return null;
        }
        return $st['gid'];
    }

    /** Remove crashed temp files (older than 1 h); called while holding the lock. */
    private static function sweepTemp(string $dir): void
    {
        foreach (glob($dir . '/.license-refresh-*') ?: [] as $f) {
            if (is_file($f) && time() - (int) @filemtime($f) > 3600) {
                @unlink($f);
            }
        }
    }

    /**
     * Keep the directory closed to the web like FluxDirGuard does for `_fluxfiles/`: a deny-all
     * .htaccess and an empty index.html, only when missing (never overwritten).
     *
     * $own = the default `<storage>/license/` directory FluxFiles created for this file: always
     * guarded. For an operator-chosen FLUXFILES_LICENSE_FILE directory we write nothing when it
     * looks like something else lives there (an index.php or index.html, or public uploads/).
     * nginx ignores .htaccess; /storage/ is denied by router.php and docker/nginx.conf.
     */
    private static function guardDir(string $dir, bool $own): void
    {
        if (!$own && (is_dir($dir . '/uploads') || is_file($dir . '/index.php') || is_file($dir . '/index.html'))) {
            return;
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', FluxDirGuard::HTACCESS);
        }
        if (!file_exists($dir . '/index.html') && ($own || !is_file($dir . '/index.php'))) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    /**
     * Why PHP-FPM may not be able to read the file, or null. The CLI often runs as root or a
     * deploy user while FPM runs as www-data: a file that belongs to neither the directory's
     * owner nor (readable by) the directory's group is silently ignored at runtime.
     */
    public static function permissionWarning(string $path, bool $withPath = false): ?string
    {
        $f = @stat($path);
        $d = @stat(dirname($path));
        if ($f === false || $d === false) {
            return null;
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $f['uid'] === 0 && ($f['mode'] & 0044) === 0) {
            return 'The licence file is owned by root and readable only by root, so PHP-FPM (the web user) will probably not be able to read it '
                . 'and would ignore the refreshed key. Run the command as the web user (sudo -u www-data php vendor/bin/fluxfiles ...) or fix it: '
                . 'chgrp <web group> ' . ($withPath ? $path : '<licence file>') . ' && chmod 0640 ' . ($withPath ? $path : '<licence file>');
        }
        if ($f['uid'] === $d['uid']) {
            return null;
        }
        if ($f['gid'] === $d['gid'] && ($f['mode'] & 0040) !== 0) {
            return null;
        }
        $target = $withPath ? $path : '<licence file>';
        return 'The licence file is not owned by the storage directory\'s owner and is not group-readable by its group, '
            . 'so PHP-FPM (another user) may be unable to read it and would ignore the refreshed key. '
            . 'Run the command as the web user (sudo -u www-data php vendor/bin/fluxfiles ...) or fix it: '
            . 'chgrp ' . $d['gid'] . ' ' . $target . ' && chmod 0640 ' . $target;
    }

    /** Warning when the resolved default path sits inside vendor/ (a composer install wipes it). */
    public static function vendorWarning(array $env): ?string
    {
        return strpos(self::path($env), '/vendor/') !== false
            ? 'The licence file would live inside vendor/, which "composer install" wipes. Set FLUXFILES_LICENSE_FILE to an absolute path outside vendor/.'
            : null;
    }

    /**
     * Ask the licence server for a newer key and (unless $write is false) store it.
     *
     * outcome: refreshed | current | nothing (no expiry / no modules: nothing to refresh) |
     *          config (no or invalid key, bad URL) | rejected (the server's key failed our
     *          checks) | unavailable | invalid | throttled | error
     *
     * @param callable $http see {@see LicenseRefresh::request()}
     * @return array{outcome:string,message:string,key?:string,issued?:?int,expires?:?int,stored?:bool,retry_after?:int}
     *         `key` is set on `refreshed` only, for `--print`; callers must not log it.
     */
    public static function refresh(array $env, callable $http, bool $write = true): array
    {
        $envKey = self::clean(isset($env['FLUXFILES_LICENSE_KEY']) ? (string) $env['FLUXFILES_LICENSE_KEY'] : '');
        if ($envKey === '') {
            return ['outcome' => 'config', 'message' => 'No FLUXFILES_LICENSE_KEY set; nothing to refresh.'];
        }
        if (!(new LicenseManager($envKey))->isVerified()) {
            return ['outcome' => 'config', 'message' => 'FLUXFILES_LICENSE_KEY is not a valid licence key.'];
        }
        $base = LicenseRefresh::baseUrl((string) ($env['FLUXFILES_LICENSE_SERVER_URL'] ?? ''));
        if ($base === '') {
            return ['outcome' => 'config', 'message' => 'FLUXFILES_LICENSE_SERVER_URL must be an https URL.'];
        }

        $current = self::clean(self::resolve($env)['key']);
        $lm = new LicenseManager($current);
        if ($lm->expiresAt() === null || $lm->modules() === []) {
            return ['outcome' => 'nothing', 'message' => 'This licence has no expiry or no modules; nothing to refresh.'];
        }

        $r = LicenseRefresh::request($current, $base, $http, 'core/' . (CoreVersion::current() ?? 'unknown'));
        switch ($r['status']) {
            case 'current':
                return ['outcome' => 'current', 'message' => 'Licence key is current.'];
            case 'invalid':
                return ['outcome' => 'invalid', 'message' => 'The licence server did not accept this key.'];
            case 'unavailable':
                return ['outcome' => 'unavailable', 'message' => 'Key refresh is not available for this licence; paste the key from your licence e-mail.'];
            case 'throttled':
                $out = ['outcome' => 'throttled', 'message' => 'Too many refresh attempts; try again later.'];
                if (isset($r['retry_after'])) {
                    $out['retry_after'] = $r['retry_after'];
                }
                return $out;
            case 'refreshed':
                break;
            default:
                return ['outcome' => 'error', 'message' => 'Licence refresh failed: ' . (string) ($r['error'] ?? 'server error')];
        }

        $new = (string) $r['key'];
        if (!LicenseRefresh::accept($current, $new)) {
            return ['outcome' => 'rejected', 'message' => 'The licence server returned a key that is not newer or expires earlier than the current one; ignored. Paste the key from your licence e-mail if you changed plan.'];
        }
        $nlm = new LicenseManager($new);
        $out = [
            'outcome' => 'refreshed',
            'message' => 'Licence key refreshed.',
            'key'     => $new,
            'issued'  => $nlm->issuedAt(),
            'expires' => $nlm->expiresAt(),
            'stored'  => false,
        ];
        if ($write) {
            try {
                $stored = self::store(self::path($env), $new, self::anchor($envKey));
                unset($out['key']);
                if ($stored === 'kept') {
                    return ['outcome' => 'current', 'message' => 'A key at least as new was already stored; nothing changed.', 'stored' => false];
                }
                $out['stored'] = true;
            } catch (\RuntimeException $e) {
                return ['outcome' => 'error', 'message' => 'Licence refresh failed: ' . $e->getMessage()];
            }
        }
        return $out;
    }
}
