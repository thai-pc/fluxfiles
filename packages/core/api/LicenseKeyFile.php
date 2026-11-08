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
        return $storage . '/license-refresh.json';
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
        $fileLm = new LicenseManager($file['key']);
        if (!$fileLm->isVerified()) {
            return ['key' => $raw, 'source' => 'unreadable', 'fetched_at' => null];
        }
        if (!hash_equals(self::anchor($envKey), $file['anchor'])) {
            return $none; // derived from some other key: not ours
        }
        $fileIssued = $fileLm->issuedAt();
        if ($fileIssued === null || $fileIssued <= ($envLm->issuedAt() ?? 0)) {
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
     * Store a verified key atomically: flock, re-read (two crons can race and the higher
     * `issued` must survive), write a temp file in the same directory, rename over the target.
     * A new file is 0600; an existing one keeps its mode and group, capped at 0640.
     *
     * @return string 'written' | 'kept' (the file already held a key at least as new)
     * @throws \RuntimeException with a message that never contains the key
     */
    public static function store(string $path, #[\SensitiveParameter] string $key, string $anchor): string
    {
        $key = self::clean($key);
        $lm = new LicenseManager($key);
        if (!$lm->isVerified()) {
            throw new \RuntimeException('refusing to store a key that does not verify');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('licence file directory is not writable');
        }

        $old = umask(0077);
        try {
            $lock = @fopen($path . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException('could not lock the licence file');
            }
            $tmp = null;
            try {
                $existing = self::read($path);
                if ($existing !== null && hash_equals($anchor, $existing['anchor'])) {
                    $ex = new LicenseManager($existing['key']);
                    if ($ex->isVerified() && ($ex->issuedAt() ?? 0) >= ($lm->issuedAt() ?? 0)) {
                        return 'kept';
                    }
                }

                $mode = 0600;
                $gid = null;
                $st = @stat($path);
                if ($st !== false) {
                    $mode = ($st['mode'] & 0640) ?: 0600;
                    $gid = $st['gid'];
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
                @chmod($tmp, $mode);
                if ($gid !== null) {
                    @chgrp($tmp, $gid);
                }
                if (!@rename($tmp, $path)) {
                    throw new \RuntimeException('could not replace the licence file');
                }
                $tmp = null;
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
                self::store(self::path($env), $new, self::anchor($envKey));
                $out['stored'] = true;
                unset($out['key']);
            } catch (\RuntimeException $e) {
                return ['outcome' => 'error', 'message' => 'Licence refresh failed: ' . $e->getMessage()];
            }
        }
        return $out;
    }
}
