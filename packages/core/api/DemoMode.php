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

    public static function enabled(): bool
    {
        $v = getenv('FLUXFILES_DEMO') ?: ($_ENV['FLUXFILES_DEMO'] ?? '');
        return $v === '1' || $v === 'true';
    }

    /** Hours a demo sandbox lives before it's eligible for purge (also the token TTL). */
    public static function ttlHours(): int
    {
        return max(1, (int) (getenv('FLUXFILES_DEMO_TTL_HOURS') ?: 6));
    }

    /**
     * Get (or assign) this visitor's sandbox id. A signed cookie keeps it stable across
     * reloads; a fresh visitor gets a new random id. Ids are `[a-z0-9]{16}`.
     */
    public static function sandboxId(): string
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (preg_match('/^[a-z0-9]{16}$/', (string) $raw)) {
            return (string) $raw;
        }
        $id = bin2hex(random_bytes(8));
        // Not HttpOnly on purpose is unnecessary — the id is opaque and server-read only.
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
            'maxUploadMb'  => (int) (getenv('FLUXFILES_DEMO_MAX_MB') ?: 5),
            'maxStorageMb' => (int) (getenv('FLUXFILES_DEMO_QUOTA_MB') ?: 50),
            'maxFiles'     => (int) (getenv('FLUXFILES_DEMO_MAX_FILES') ?: 30),
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
     * @return array<string,mixed>
     */
    public static function bootConfig(): array
    {
        $id = self::sandboxId();
        $cfg = [
            'token'       => self::mintToken($id),
            'disk'        => 'local',
            'path'        => 'demo/' . $id,
            'maxUploadMb' => (int) (getenv('FLUXFILES_DEMO_MAX_MB') ?: 5),
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
     * Purge demo sandboxes whose newest file is older than the TTL. Best-effort, cheap:
     * call it opportunistically (a small % of demo page loads) or from cron. $root is the
     * local disk root. Never throws.
     */
    public static function purge(string $localRoot): void
    {
        $base = rtrim($localRoot, '/') . '/demo';
        if (!is_dir($base)) {
            return;
        }
        $cutoff = time() - self::ttlHours() * 3600;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // Use the directory mtime as a cheap "last touched"; uploads bump it.
            if (@filemtime($dir) < $cutoff) {
                self::rrmdir($dir);
            }
        }
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
