<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Public demo mode — turns a self-hosted core into a safe "try it live" instance that
 * the marketing site can embed by iframe and let anonymous visitors upload for real.
 *
 * Enabled with FLUXFILES_DEMO=1. Every visitor gets a heavily-scoped, short-TTL token
 * (its own `demo/<id>/` prefix on the local disk, images only, small size + quota +
 * file caps, rate-limited, owner-only, no dangerous claims) minted server-side and
 * injected as window.__FM_BOOT__ — so the token never touches the marketing site and
 * an abuser can't reach outside their sandbox. Old sandboxes auto-purge.
 *
 * This is opt-in and off by default: a normal deployment is unaffected.
 */
final class DemoMode
{
    /** Visitor cookie holding the sandbox id (so a reload keeps the same files). */
    private const COOKIE = 'ff_demo';

    /**
     * Read an env var from getenv() OR $_ENV — vlucas/phpdotenv v5 populates $_ENV but
     * does NOT putenv() by default, so getenv() alone misses .env-provided values.
     */
    private static function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = (string) ($_ENV[$key] ?? '');
        }
        return $v !== '' ? $v : $default;
    }

    public static function enabled(): bool
    {
        $v = self::env('FLUXFILES_DEMO');
        return $v === '1' || $v === 'true';
    }

    /** Hours a demo sandbox lives before it's eligible for purge (also the token TTL). */
    public static function ttlHours(): int
    {
        return max(1, (int) self::env('FLUXFILES_DEMO_TTL_HOURS', '6'));
    }

    /** This visitor's existing sandbox id from the cookie, or '' if none/invalid. */
    public static function existingSandbox(): string
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        return preg_match('/^[a-z0-9]{16}$/', (string) $raw) ? (string) $raw : '';
    }

    /** Assign a fresh sandbox id + set the cookie. Ids are `[a-z0-9]{16}`. */
    public static function newSandbox(): string
    {
        $id = bin2hex(random_bytes(8));
        if (!headers_sent()) {
            setcookie(self::COOKIE, $id, [
                'expires'  => time() + self::ttlHours() * 3600,
                'path'     => '/',
                'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['SERVER_PORT'] ?? '') === '443'),
                'httponly' => true,
                'samesite' => 'None', // embedded cross-site in the marketing iframe
            ]);
        }
        return $id;
    }

    /**
     * Mint the hardened demo token for this visitor. Requires FLUXFILES_SECRET (like any
     * token) and the embed.php helpers to be loaded.
     */
    public static function mintToken(string $sandboxId): string
    {
        $prefix = 'demo/' . $sandboxId;
        return fluxfiles_token([
            'user'         => 'demo-' . $sandboxId,
            'perms'        => ['read', 'write', 'delete'],
            'disks'        => ['local'],
            'prefix'       => $prefix,
            'ownerOnly'    => true,
            'maxUploadMb'  => (int) (self::env('FLUXFILES_DEMO_MAX_MB', '5')),
            'maxStorageMb' => (int) (self::env('FLUXFILES_DEMO_QUOTA_MB', '50')),
            'maxFiles'     => (int) (self::env('FLUXFILES_DEMO_MAX_FILES', '30')),
            'allowedExt'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'],
            'ttl'          => self::ttlHours() * 3600,
            'rateRead'     => 120,
            'rateWrite'    => 20,
            // Explicitly deny the powerful/abusable claims (defaults are already off,
            // but be loud about it — this token is handed to anonymous visitors).
            'claims'       => [
                'allow_url_import' => false,
                'allow_terminal'   => false,
                'allow_optimize'   => false,
                'byob'             => false,
            ],
        ]);
    }

    /**
     * The window.__FM_BOOT__ config the UI consumes (token + sandbox path + caps).
     * A returning visitor (valid cookie) reuses their sandbox; a NEW visitor is only
     * given a fresh sandbox if their IP is under the hourly mint budget — otherwise
     * `{ throttled: true }` (no token) so the UI shows the auth-required state instead
     * of letting one abuser spin up unlimited sandboxes. $stateDir holds the IP counter.
     *
     * @return array<string,mixed>
     */
    public static function bootConfig(string $stateDir): array
    {
        $id = self::existingSandbox();
        if ($id === '') {
            // New sandbox → rate-limit fresh mints per IP.
            if (!self::recordMintAllowed($stateDir)) {
                return ['throttled' => true];
            }
            $id = self::newSandbox();
        }
        $cfg = [
            'token'       => self::mintToken($id),
            'disk'        => 'local',
            'path'        => 'demo/' . $id,
            'maxUploadMb' => (int) (self::env('FLUXFILES_DEMO_MAX_MB', '5')),
            'multiple'    => true,
        ];
        // Let the embedding page hint a theme via ?theme=dark|light (matches the host).
        $theme = $_GET['theme'] ?? '';
        if ($theme === 'dark' || $theme === 'light') {
            $cfg['theme'] = $theme;
        }
        return $cfg;
    }

    /**
     * DEFENSE-IN-DEPTH: strip every non-local disk from the config when demo mode is on,
     * so the public demo can NEVER reach S3/R2/SFTP (no egress cost, no BYOB) even if the
     * host's disks.php defines them or a claim bug slips through. The demo token is already
     * local-only; this makes it structurally impossible at the disk layer too.
     *
     * @param array<string,mixed> $configs
     * @return array<string,mixed>
     */
    public static function forceLocalDisks(array $configs): array
    {
        $out = [];
        foreach ($configs as $name => $cfg) {
            if (($cfg['driver'] ?? '') === 'local') {
                $out[$name] = $cfg;
            }
        }
        // Guarantee at least the 'local' disk exists so the demo still boots.
        if (!isset($out['local']) && isset($configs['local'])) {
            $out['local'] = $configs['local'];
        }
        return $out;
    }

    /** Total global demo disk budget (MB) across all sandboxes. */
    public static function totalBudgetMb(): int
    {
        return max(50, (int) (self::env('FLUXFILES_DEMO_TOTAL_MB', '2000')));
    }

    /** Max new sandboxes a single IP may mint per hour (anti sandbox-spam). */
    public static function ipMintsPerHour(): int
    {
        return max(1, (int) (self::env('FLUXFILES_DEMO_IP_MINTS', '20')));
    }

    /** Best-effort client IP, preferring the proxy/CDN header when present. */
    public static function clientIp(): string
    {
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cf !== '') {
            return (string) $cf;
        }
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            return trim(explode(',', (string) $xff)[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Per-IP sandbox-mint throttle. Returns true when this IP is UNDER its hourly mint
     * budget (record the mint) — false when it has spammed too many fresh sandboxes, so
     * the caller can refuse to hand out a new token. File-based, self-contained, locked.
     * A returning visitor with a valid cookie sandbox is not throttled (see bootConfig).
     */
    public static function recordMintAllowed(string $stateDir): bool
    {
        $file = rtrim($stateDir, '/') . '/demo_ip.json';
        $ip = self::clientIp();
        $now = time();
        $window = 3600;
        $limit = self::ipMintsPerHour();

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return true; // never block on a storage hiccup
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $data = json_decode($raw ?: '[]', true);
            if (!is_array($data)) {
                $data = [];
            }
            // Drop expired entries + count this IP's mints in the window.
            $kept = [];
            $count = 0;
            foreach ($data as $row) {
                if (($row['t'] ?? 0) >= $now - $window) {
                    $kept[] = $row;
                    if (($row['ip'] ?? '') === $ip) {
                        $count++;
                    }
                }
            }
            if ($count >= $limit) {
                // Persist the pruned list (cheap GC) but do NOT record a new mint.
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($kept));
                return false;
            }
            $kept[] = ['ip' => $ip, 't' => $now];
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($kept));
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Purge demo sandboxes older than the TTL, then enforce the global size budget by
     * deleting the OLDEST sandboxes until total demo bytes are under the cap. Best-effort,
     * cheap; call opportunistically or from cron. $localRoot is the local disk root.
     */
    public static function purge(string $localRoot): void
    {
        $base = rtrim($localRoot, '/') . '/demo';
        if (!is_dir($base)) {
            return;
        }
        // 1. TTL purge.
        $cutoff = time() - self::ttlHours() * 3600;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (@filemtime($dir) < $cutoff) {
                self::rrmdir($dir);
            }
        }
        // 2. Global budget: if still over cap, delete oldest sandboxes first.
        $budget = self::totalBudgetMb() * 1024 * 1024;
        $dirs = glob($base . '/*', GLOB_ONLYDIR) ?: [];
        $sizes = [];
        $total = 0;
        foreach ($dirs as $dir) {
            $s = self::dirSize($dir);
            $sizes[$dir] = ['size' => $s, 'mtime' => @filemtime($dir) ?: 0];
            $total += $s;
        }
        if ($total <= $budget) {
            return;
        }
        uasort($sizes, static fn ($a, $b) => $a['mtime'] <=> $b['mtime']); // oldest first
        foreach ($sizes as $dir => $meta) {
            if ($total <= $budget) {
                break;
            }
            self::rrmdir($dir);
            $total -= $meta['size'];
        }
    }

    private static function dirSize(string $dir): int
    {
        $total = 0;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            $total += is_dir($p) ? self::dirSize($p) : (int) @filesize($p);
        }
        return $total;
    }

    private static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
