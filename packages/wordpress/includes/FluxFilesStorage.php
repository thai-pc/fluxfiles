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
 * The option stores a LOCATION TOKEN, never an absolute path, so a moved or
 * cloned site follows its own directories (like WordPress's relative upload_path):
 *   'content' => WP_CONTENT_DIR/fluxfiles   (installs that already had that folder)
 *   'uploads' => <main-site uploads>/fluxfiles (fresh installs; managed hosts back it up)
 * The token is decided ONCE (activation, or the first request that needs it).
 * A pre-release build stored an absolute path; that value is still honoured as-is.
 * The constant and the filter are code, so they stay absolute.
 */
final class FluxFilesStorage
{
    public const OPTION = 'fluxfiles_storage_base';

    /** First bytes of every .htaccess the plugin writes; uninstall deletes only files carrying it. */
    public const PROTECT_MARKER = '# FluxFiles storage protection v';

    /** Exact body of the index.php the plugin writes next to the .htaccess files. */
    public const INDEX_STUB = "<?php\n// Silence is golden.\n";

    /** @var string|null per-request cache of the option-derived base */
    private static ?string $decided = null;

    /** @var array{0:string,1:string}|null|false per-request cache of uploadsInfo() (false = not yet computed) */
    private static $uploads = false;

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

    /** Drop the per-request caches (tests, or after the option/constants change). */
    public static function flush(): void
    {
        self::$decided = null;
        self::$uploads = false;
    }

    /** Absolute storage base (no trailing slash, forward slashes). */
    public static function base(): string
    {
        return self::resolve()[0];
    }

    /**
     * Where the base came from: 'const' (FLUXFILES_STORAGE_DIR), 'filter'
     * (`fluxfiles_storage_base`) or 'option'. Code-chosen locations ('const',
     * 'filter') are the operator's, not ours: uninstall never deletes files there.
     */
    public static function source(): string
    {
        return self::resolve()[1];
    }

    /** @return array{0:string,1:string} [base, source] */
    private static function resolve(): array
    {
        if (defined('FLUXFILES_STORAGE_DIR')) {
            $const = constant('FLUXFILES_STORAGE_DIR');
            if (is_string($const) && trim($const) !== '') {
                return [self::normalize($const), 'const'];
            }
        }
        $decided = self::decide();
        $filtered = apply_filters('fluxfiles_storage_base', $decided);
        if (is_string($filtered) && trim($filtered) !== '') {
            $filtered = self::normalize($filtered);
            return [$filtered, $filtered === $decided ? 'option' : 'filter'];
        }
        return [$decided, 'option'];
    }

    /**
     * Read the stored token, or decide + persist it. Must run before anything
     * creates the directory, or a fresh install would look like a legacy one.
     * Returns the absolute path the token resolves to RIGHT NOW.
     */
    public static function decide(): string
    {
        if (self::$decided !== null) {
            return self::$decided;
        }
        $stored = self::getOption();
        if (is_string($stored) && trim($stored) !== '') {
            return self::$decided = self::fromToken(trim($stored));
        }
        if (is_dir(self::contentBase())) {
            self::saveOption('content');
            return self::$decided = self::contentBase();
        }
        // Persist only when the uploads API answered: a failed early load must not
        // pin a guess. The fallback path below is the one 'uploads' resolves to
        // without the API, so the decision cannot flip when it comes back.
        if (self::uploadsInfo() !== null) {
            self::saveOption('uploads');
        }
        return self::$decided = self::fromToken('uploads');
    }

    private static function contentBase(): string
    {
        return self::normalize(WP_CONTENT_DIR) . '/fluxfiles';
    }

    private static function fromToken(string $token): string
    {
        if ($token === 'content') {
            return self::contentBase();
        }
        if ($token === 'uploads') {
            $up = self::uploadsInfo();
            return $up !== null ? $up[0] . '/fluxfiles' : self::normalize(WP_CONTENT_DIR) . '/uploads/fluxfiles';
        }
        return self::normalize($token); // legacy: absolute path stored by a pre-release build
    }

    /** Main-site uploads basedir (no trailing slash), or null without a usable uploads API. */
    public static function uploadsBase(): ?string
    {
        $up = self::uploadsInfo();
        return $up === null ? null : $up[0];
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
        if (self::$uploads !== false) {
            return self::$uploads;
        }
        return self::$uploads = self::loadUploadsInfo();
    }

    /** @return array{0:string,1:string}|null */
    private static function loadUploadsInfo(): ?array
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

    private static function saveOption(string $token): void
    {
        if (function_exists('update_site_option')) {
            update_site_option(self::OPTION, $token);
        } elseif (function_exists('update_option')) {
            update_option(self::OPTION, $token);
        }
    }
}
