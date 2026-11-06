<?php

defined('ABSPATH') || exit;

/**
 * Where the local disk lives. Deliberately free of any FluxFiles core dependency
 * so uninstall.php (which runs without the plugin loaded) resolves the same path.
 *
 * Base = the directory that holds `uploads/` (the disk root) and the runtime
 * state; multisite adds `sites/<blog_id>/` below it (FluxFilesPlugin).
 *
 * Resolution order: the FLUXFILES_STORAGE_DIR constant, then the
 * `fluxfiles_storage_base` filter, then the `fluxfiles_storage_base` site option.
 * The option is decided ONCE (activation, or the first request that needs it):
 * an install that already has wp-content/fluxfiles keeps it (no data move), a
 * fresh one gets <uploads>/fluxfiles, which managed hosts back up and persist.
 */
final class FluxFilesStorage
{
    public const OPTION = 'fluxfiles_storage_base';

    /**
     * Files the plugin itself writes into the disk root to protect it. The local
     * disk lists/searches/counts everything else; core hides exactly these keys at
     * the disk root (disk config `hidden_files`), never a same-named user file
     * in a subfolder.
     *
     * @return string[]
     */
    public static function guardFiles(): array
    {
        return ['.htaccess', 'index.php'];
    }

    /** Absolute storage base (no trailing slash, forward slashes). */
    public static function base(): string
    {
        if (defined('FLUXFILES_STORAGE_DIR')) {
            $const = constant('FLUXFILES_STORAGE_DIR');
            if (is_string($const) && trim($const) !== '') {
                return self::normalize($const);
            }
        }
        $decided = self::decide();
        $filtered = apply_filters('fluxfiles_storage_base', $decided);
        return is_string($filtered) && trim($filtered) !== '' ? self::normalize($filtered) : $decided;
    }

    /**
     * Read the stored base, or decide + persist it. Must run before anything
     * creates the directory, or a fresh install would look like a legacy one.
     */
    public static function decide(): string
    {
        $stored = self::getOption();
        if (is_string($stored) && trim($stored) !== '') {
            return self::normalize($stored);
        }
        $legacy = self::normalize(WP_CONTENT_DIR) . '/fluxfiles';
        if (is_dir($legacy)) {
            self::saveOption($legacy);
            return $legacy;
        }
        $up = self::uploadsInfo();
        if ($up === null) {
            return $legacy; // no uploads API (stubbed/early load): don't persist a guess
        }
        $base = $up[0] . '/fluxfiles';
        self::saveOption($base);
        return $base;
    }

    /**
     * Public URL of an absolute path under the base, or '' when it is not web
     * reachable (a custom location outside uploads and wp-content). Callers must
     * treat '' as "serve through the signed /stream route only".
     */
    public static function url(string $abs): string
    {
        $loc = self::locate($abs);
        if ($loc === null) {
            return '';
        }
        return $loc[0] === 'uploads'
            ? rtrim($loc[2], '/') . ($loc[1] === '' ? '' : '/' . $loc[1])
            : content_url($loc[1]);
    }

    /**
     * Path relative to the URL root it is served from (wp-content, or the uploads
     * dir when it lives there), '' when not web reachable.
     */
    public static function relPath(string $abs): string
    {
        $loc = self::locate($abs);
        return $loc === null ? '' : $loc[1];
    }

    /**
     * @return array{0:string,1:string,2:string}|null [kind, relative path, root url]
     */
    private static function locate(string $abs): ?array
    {
        $abs = self::normalize($abs);
        $up = self::uploadsInfo();
        if ($up !== null && ($abs === $up[0] || str_starts_with($abs, $up[0] . '/'))) {
            return ['uploads', ltrim(substr($abs, strlen($up[0])), '/'), $up[1]];
        }
        $content = self::normalize(WP_CONTENT_DIR);
        if ($abs === $content || str_starts_with($abs, $content . '/')) {
            return ['content', ltrim(substr($abs, strlen($content)), '/'), ''];
        }
        return null;
    }

    /**
     * Main-site uploads dir + URL (the base is network-wide, so a sub-site's
     * `uploads/sites/N` must not leak into it). Null when WordPress has no
     * usable uploads dir.
     *
     * @return array{0:string,1:string}|null
     */
    private static function uploadsInfo(): ?array
    {
        if (!function_exists('wp_upload_dir')) {
            return null;
        }
        $switched = false;
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_main_site_id')
            && function_exists('switch_to_blog') && function_exists('get_current_blog_id')
            && (int) get_current_blog_id() !== (int) get_main_site_id()) {
            switch_to_blog((int) get_main_site_id());
            $switched = true;
        }
        try {
            $u = wp_upload_dir(null, false);
        } finally {
            if ($switched && function_exists('restore_current_blog')) {
                restore_current_blog();
            }
        }
        if (!is_array($u) || !empty($u['error']) || empty($u['basedir']) || empty($u['baseurl'])) {
            return null;
        }
        return [self::normalize((string) $u['basedir']), (string) $u['baseurl']];
    }

    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    /** @return mixed */
    private static function getOption()
    {
        if (function_exists('get_site_option')) {
            return get_site_option(self::OPTION, '');
        }
        return get_option(self::OPTION, '');
    }

    private static function saveOption(string $base): void
    {
        $base = self::normalize($base);
        if (function_exists('update_site_option')) {
            update_site_option(self::OPTION, $base);
        } elseif (function_exists('update_option')) {
            update_option(self::OPTION, $base);
        }
    }
}
