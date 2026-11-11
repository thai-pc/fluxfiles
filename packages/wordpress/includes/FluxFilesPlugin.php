<?php

defined('ABSPATH') || exit;

require_once __DIR__ . '/FluxFilesStorage.php';

/**
 * Main plugin class — singleton that wires all hooks.
 */
class FluxFilesPlugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        self::seedSsrfAllowHosts();
        // Core resolves the licence for its own module wiring (FileManagerFactory,
        // AboutInfo) through this; the free plugin answers NoLicense unless an add-on
        // supplies one via the `fluxfiles_license` filter.
        \FluxFiles\ModuleRegistry::setLicenseResolver([self::class, 'license']);
        // Add-ons boot here: after every plugin file is loaded, before anything else.
        add_action('plugins_loaded', [self::class, 'loaded'], 1);
        // Core's warnings (Log::warn) go to the same WP_DEBUG-gated log as the plugin's own.
        if (class_exists(\FluxFiles\Log::class)) {
            \FluxFiles\Log::setSink([self::class, 'debugLog']);
        }

        register_activation_hook(FLUXFILES_PLUGIN_FILE, [$this, 'activate']);
        add_action('wp_initialize_site', [self::class, 'onNewSite'], 900);
        add_action('init', [self::class, 'ensureSiteReady'], 1);
        // Flipping private local storage rewrites the uploads .htaccess on save, not on the
        // next API call (extra hook arguments are ignored by protectStorage()).
        add_action('add_option_fluxfiles_local_private', [self::class, 'protectStorage']);
        add_action('update_option_fluxfiles_local_private', [self::class, 'protectStorage']);
        register_deactivation_hook(FLUXFILES_PLUGIN_FILE, [$this, 'deactivate']);
        add_action('admin_init', [FluxFilesDbSchema::class, 'maybeUpgrade']);

        // Tools > Site Health > Info: the operator's "About" (versions, modules, tools).
        add_filter('debug_information', [self::class, 'siteHealthInfo']);

        // Admin
        if (is_admin()) {
            new FluxFilesAdmin();
            new FluxFilesMediaButton();
        }
        // Native media-modal integration (experimental, opt-in) — runs in admin +
        // the Customizer, so it's booted outside the is_admin() block.
        new FluxFilesMediaIntegration();

        // REST API
        add_action('rest_api_init', [FluxFilesApi::class, 'registerRoutes']);

        // Shortcode
        new FluxFilesShortcode();

        // Attachment bridge (3-in-1 media manager): register FluxFiles picks as WP
        // attachments served from your bucket + a Gutenberg block. URL-rewrite filters
        // load early; the block registers on init.
        add_action('plugins_loaded', ['FluxFilesAttachments', 'register']);
        new FluxFilesBlock();
    }

    /**
     * `plugins_loaded` priority 1: let add-ons register module directories, then hand
     * them the plugin. Order matters: the module paths are registered with core's
     * autoloader BEFORE `fluxfiles_loaded` fires, so a listener can already use them.
     */
    public static function loaded(): void
    {
        $paths = apply_filters('fluxfiles_module_paths', []);
        if (is_array($paths)) {
            // Absolute, existing directories only (a relative path would resolve against
            // whatever the cwd happens to be); realpath() so the autoloader sees one form.
            $abs = static fn (string $p): bool => function_exists('path_is_absolute')
                ? path_is_absolute($p)
                : preg_match('#^(/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $p) === 1;
            $clean = [];
            foreach ($paths as $p) {
                if (!is_string($p) || $p === '' || !$abs($p) || !is_dir($p)) {
                    continue;
                }
                $real = realpath($p);
                if ($real !== false) {
                    $clean[] = $real;
                }
            }
            $paths = array_values(array_unique($clean));
            if ($paths !== [] && class_exists(\FluxFiles\ModuleAutoloader::class)) {
                \FluxFiles\ModuleAutoloader::register($paths);
            }
        }
        do_action('fluxfiles_loaded', self::instance());
    }

    /**
     * Plugin activation — create storage directory and set default options.
     */
    public function activate(bool $networkWide = false): void
    {
        if ($networkWide && self::isMultisite() && function_exists('get_sites')) {
            // Network activation runs this once, on the main site only: every other
            // sub-site would be left without a secret ("Unable to generate token").
            // WordPress records the plugin as active only AFTER this hook returns, so the
            // loop has a budget (sites and seconds) and is skipped on a large network; the
            // sites it does not reach are set up by ensureSiteReady() on their first request.
            $network = function_exists('get_current_network_id') ? (int) get_current_network_id() : 1;
            if (!(function_exists('wp_is_large_network') && wp_is_large_network('sites'))) {
                $maxSites = max(0, (int) apply_filters('fluxfiles_activation_max_sites', 200));
                $deadline = microtime(true) + (float) apply_filters('fluxfiles_activation_budget_seconds', 10);
                $done = 0;
                $offset = 0;
                $batch = 100;
                do {
                    $ids = (array) get_sites(['fields' => 'ids', 'number' => $batch, 'offset' => $offset, 'network_id' => $network]);
                    foreach ($ids as $id) {
                        if ($done >= $maxSites || microtime(true) >= $deadline) {
                            break 2;
                        }
                        switch_to_blog((int) $id);
                        try {
                            self::initSite();
                        } finally {
                            restore_current_blog();
                        }
                        $done++;
                    }
                    $offset += $batch;
                } while (count($ids) === $batch);
            }
        } else {
            self::initSite();
        }

        flush_rewrite_rules();
    }

    /**
     * A site created while FluxFiles is network-active gets its own defaults + storage
     * root (priority > 100 so core has populated the new site's options first).
     *
     * @param mixed $site WP_Site
     */
    public static function onNewSite($site): void
    {
        if (!is_object($site) || empty($site->blog_id) || !self::networkActive()) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        try {
            self::initSite();
        } finally {
            restore_current_blog();
        }
    }

    private static function networkActive(): bool
    {
        if (!function_exists('is_plugin_active_for_network') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network(plugin_basename(FLUXFILES_PLUGIN_FILE));
    }

    /**
     * Safety net behind activation: a site that never ran initSite() (added before
     * this was network-aware, imported, cloned, or activated by a deploy tool that
     * skips the hook) gets its defaults on its first request instead of failing
     * every token with "secret is not configured". One cheap option read once set.
     */
    public static function ensureSiteReady(): void
    {
        if (get_option('fluxfiles_secret', false) === false) {
            self::initSite();
        }
    }

    /**
     * Per-site setup: storage directory + protection files, default options and the
     * DB tables, for the CURRENT blog (the caller switches blogs on multisite).
     */
    public static function initSite(): void
    {
        // Decide the storage base before anything creates a directory, so an
        // existing wp-content/fluxfiles is kept and a fresh install lands in uploads/.
        FluxFilesStorage::decide();
        $storagePath = self::storagePath();

        if (!FluxFilesStorage::onFallback()) {
            if (!is_dir($storagePath)) {
                wp_mkdir_p($storagePath);
            }
            if (!is_dir($storagePath . '/uploads')) {
                wp_mkdir_p($storagePath . '/uploads');
            }
            self::protectStorage();
        }

        // Set default options if not already set
        if (get_option('fluxfiles_secret') === false) {
            update_option('fluxfiles_secret', wp_generate_password(32, false));
        }
        if (get_option('fluxfiles_disks') === false) {
            update_option('fluxfiles_disks', ['local']);
        }
        if (get_option('fluxfiles_default_perms') === false) {
            update_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        }
        if (get_option('fluxfiles_max_upload') === false) {
            update_option('fluxfiles_max_upload', 10);
        }
        if (get_option('fluxfiles_max_storage') === false) {
            update_option('fluxfiles_max_storage', 0);
        }
        if (get_option('fluxfiles_ttl') === false) {
            update_option('fluxfiles_ttl', 3600);
        }

        FluxFilesDbSchema::install();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate(): void
    {
        flush_rewrite_rules();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the FluxFiles storage directory path
     * (FLUXFILES_STORAGE_DIR > `fluxfiles_storage_base` filter > option; see FluxFilesStorage).
     */
    public static function storagePath(): string
    {
        $base = FluxFilesStorage::base();
        $site = self::multisiteSegment();
        return $site === '' ? $base : $base . '/' . $site;
    }

    /**
     * `sites/<blog_id>` on a multisite network, '' on a single site. Every blog
     * gets its OWN storage root (uploads, `_fluxfiles/` index/audit/trash, rate
     * limits): the token `prefix` is empty by default, so a shared root would let
     * any sub-site user address every other site's files. Blog 1 is included on
     * purpose — its root must not be the parent of `sites/2`.
     */
    private static function multisiteSegment(): string
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return '';
        }
        $id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        return 'sites/' . max(1, $id);
    }

    /**
     * The local disk root relative to the directory its public URL is built from
     * (wp-content, or the uploads dir when the base lives there); '' when the
     * storage is outside both and has no public URL.
     */
    public static function uploadsRelPath(): string
    {
        return FluxFilesStorage::relPath(self::storagePath() . '/uploads');
    }

    /** Bump when the generated rules change, so existing installs get rewritten. */
    private const PROTECT_VERSION = 1;
    private const PROTECT_MARKER  = FluxFilesStorage::PROTECT_MARKER;

    /**
     * Write web-server rules into the storage dir, which lives under the publicly
     * served wp-content/. The standalone app gets these from router.php / the
     * shipped nginx config; WordPress serves the directory raw, so without them:
     *
     *  - `uploads/…/_fluxfiles/` (audit log with IPs, search index, trash.json
     *    and every tenant's soft-deleted bytes, the pre-watermark originals) was
     *    downloadable by anyone;
     *  - an uploaded .html/.svg rendered as active content in the SAME origin as
     *    wp-admin — a stored-XSS path WordPress core itself closes for Authors;
     *  - a host that maps `.php8`/`.pht`/… to PHP could execute an upload.
     *
     * Apache / LiteSpeed only (`.htaccess`); nginx sites must add the equivalent
     * rules by hand (see readme.txt), or enable "private local storage".
     * Idempotent and cheap (one file_exists per call); never overwrites a file
     * the operator wrote themselves (no marker), only our own older versions.
     */
    public static function protectStorage(): void
    {
        $root = self::storagePath();
        $uploads = $root . '/uploads';
        if (!is_dir($uploads)) {
            return;
        }
        self::writeProtectFile($root . '/.htaccess', self::rootHtaccess());
        // Private local storage: nothing under uploads/ may be fetched by its plain URL
        // (files stream through the signed REST route), so the folder is denied too.
        if ((bool) get_option('fluxfiles_local_private', false)) {
            self::writeProtectFile($uploads . '/.htaccess', self::privateUploadsHtaccess(), '-private');
        } else {
            self::writeProtectFile($uploads . '/.htaccess', self::uploadsHtaccess());
        }
        foreach ([$root, $uploads] as $dir) {
            if (!file_exists($dir . '/index.php')) {
                @file_put_contents($dir . '/index.php', FluxFilesStorage::INDEX_STUB);
            }
        }
    }

    private static function writeProtectFile(string $path, string $body, string $variant = ''): void
    {
        $header = self::PROTECT_MARKER . self::PROTECT_VERSION . $variant;
        if (is_file($path)) {
            $current = (string) @file_get_contents($path, false, null, 0, 256);
            if (strtok($current, "\r\n") === $header) {
                return; // already current (this mode)
            }
            if (strpos($current, self::PROTECT_MARKER) === false) {
                return; // operator-authored — leave it alone
            }
        }
        @file_put_contents($path, $header . "\n" . $body);
    }

    /** Storage root: runtime state only — nothing here is public except uploads/. */
    private static function rootHtaccess(): string
    {
        return <<<'HTACCESS'
# Rate-limit state, demo/ssh runtime files: never web-served.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;
    }

    /** Private local storage: the whole uploads folder is denied; files are only served through the signed REST route. */
    private static function privateUploadsHtaccess(): string
    {
        return <<<'HTACCESS'
# Private local storage: uploads are never fetched by their plain URL.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;
    }

    /** Public user uploads: serve as inert bytes, never as code or active content. */
    private static function uploadsHtaccess(): string
    {
        return <<<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all granted
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Allow from all
</IfModule>

# Storage-resident bookkeeping at ANY depth (a path-scoped tenant keeps it at
# <prefix>/_fluxfiles/), case-insensitively, plus every dotfile.
RedirectMatch 403 (?i)/_fluxfiles(/|$)
RedirectMatch 403 /\.

# Never hand an upload to a script handler.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .pht .phtml .phar .cgi .pl .py .shtml
<FilesMatch "\.(?i:php[0-9]?|pht|phtml?|phar|phps|pgif|shtml?|stm|cgi|pl|py|asp|aspx|jsp)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

# HTML-family documents are never rendered (works without mod_headers).
<FilesMatch "\.(?i:html?|xhtml|shtml|xml)$">
    ForceType application/octet-stream
</FilesMatch>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    # Images still display; scripts inside an SVG/HTML opened directly cannot run.
    Header always set Content-Security-Policy "sandbox"
    <FilesMatch "\.(?i:html?|xhtml|shtml|xml|svg)$">
        Header always set Content-Disposition "attachment"
    </FilesMatch>
</IfModule>
HTACCESS;
    }

    /** Diagnostics go to the PHP error log only when the site runs with WP_DEBUG. */
    public static function debugLog(string $msg): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($msg); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG
        }
    }

    /**
     * The licence verifier. The free plugin has no licence of its own: it is NoLicense
     * unless an add-on (FluxFiles Pro) returns a LicenseCheckInterface from the
     * `fluxfiles_license` filter (anything else is ignored). Every paid-module gate in
     * the plugin goes through this, and core reads it via ModuleRegistry::license().
     */
    public static function license(): \FluxFiles\LicenseCheckInterface
    {
        try {
            $custom = apply_filters('fluxfiles_license', null);
        } catch (\Throwable $e) {
            // A throwing filter must fail closed (no licence), not 500 the caller.
            self::debugLog('FluxFiles: fluxfiles_license filter threw ' . get_class($e));
            return new \FluxFiles\NoLicense();
        }
        return $custom instanceof \FluxFiles\LicenseCheckInterface ? $custom : new \FluxFiles\NoLicense();
    }

    /**
     * `debug_information` filter: a "FluxFiles" section in Site Health > Info, built from
     * core's AboutInfo (the same report as `fluxfiles about` / `artisan about`). Never
     * carries a key, credential or endpoint. Rows that name disks, the licence or the
     * SSRF allow-list are `private`, so "Copy site info to clipboard" leaves them out of
     * a support ticket. Every value is escaped here, not trusted to the renderer.
     * Against an older core without AboutInfo it degrades to the plugin version alone.
     *
     * @param array<string,mixed> $info
     * @return array<string,mixed>
     */
    public static function siteHealthInfo(array $info): array
    {
        $esc = static fn ($v): string => function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $fields = [
            'plugin_version' => ['label' => 'Plugin version', 'value' => $esc(defined('FLUXFILES_VERSION') ? FLUXFILES_VERSION : 'unknown'), 'private' => false],
        ];
        $bundledCore = self::bundledCoreVersion();
        if ($bundledCore !== null) {
            $fields['bundled_core'] = ['label' => 'Bundled core', 'value' => $esc($bundledCore), 'private' => false];
        }
        if (class_exists(\FluxFiles\AboutInfo::class)) {
            try {
                $ssrf = defined('FLUXFILES_SSRF_ALLOW_HOSTS')
                    ? constant('FLUXFILES_SSRF_ALLOW_HOSTS')
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration from the environment (not user input), only parsed or compared
                    : (getenv('FLUXFILES_SSRF_ALLOW_HOSTS') ?: ($_ENV['FLUXFILES_SSRF_ALLOW_HOSTS'] ?? ''));
                $about = \FluxFiles\AboutInfo::collect([
                    'env' => array_merge($_ENV, $bundledCore !== null ? ['FLUXFILES_CORE_VERSION' => $bundledCore] : [], [
                        'FLUXFILES_STORAGE_BACKEND' => (string) get_option('fluxfiles_storage_backend', 'json'),
                        'FLUXFILES_SSRF_ALLOW_HOSTS' => (string) $ssrf,
                    ]),
                    'disks' => self::diskConfigs(),
                    // core ships inside the plugin zip: don't tell a WP admin to set an env var
                    'bundled_with' => defined('FLUXFILES_VERSION') ? 'plugin ' . FLUXFILES_VERSION : null,
                ]);
                foreach (\FluxFiles\AboutInfo::rows($about) as $i => $row) {
                    $fields['about_' . $i] = ['label' => $esc($row['label']), 'value' => $esc($row['value']), 'private' => $row['private']];
                }
            } catch (\Throwable $e) {
                $fields['about_error'] = ['label' => 'Report', 'value' => 'unavailable', 'private' => false];
            }
        } else {
            $fields['about_unavailable'] = ['label' => 'Report', 'value' => 'update the bundled FluxFiles core for the full report', 'private' => false];
        }
        $info['fluxfiles'] = ['label' => 'FluxFiles', 'fields' => $fields];
        return $info;
    }

    /**
     * The release of the core bundled in this build, as stamped by build-wordpress.sh from
     * the latest core-v* tag; null in an unstamped (dev checkout) copy. The bundled core
     * has no Composer metadata, so AboutInfo cannot discover its version by itself.
     */
    public static function bundledCoreVersion(): ?string
    {
        if (!defined('FLUXFILES_CORE_VERSION')) {
            return null;
        }
        $v = (string) constant('FLUXFILES_CORE_VERSION');
        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $v) === 1 ? $v : null;
    }

    /**
     * FLUXFILES_SSRF_ALLOW_HOSTS for a server on the operator's private network, as a
     * wp-config.php constant or a real env var. Deliberately no Settings field: it
     * loosens the SSRF guard, so it stays with whoever controls the server.
     */
    public static function seedSsrfAllowHosts(): void
    {
        $raw = defined('FLUXFILES_SSRF_ALLOW_HOSTS')
            ? constant('FLUXFILES_SSRF_ALLOW_HOSTS')
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration from the environment (not user input), only parsed or compared
            : (getenv('FLUXFILES_SSRF_ALLOW_HOSTS') ?: ($_ENV['FLUXFILES_SSRF_ALLOW_HOSTS'] ?? ''));
        \FluxFiles\SsrfGuard::setAllowHosts((string) $raw);
    }

    /**
     * Gate and send a module's recipient page (share / intake) with the API base pointed
     * at this site's REST namespace. Used by the REST `<module>/page`
     * routes.
     *
     * Core's PublicRoutes::dispatch() cannot be used for pages: it hard-codes
     * `/api/fm` as the base (right for standalone, wrong here), so the gate is repeated
     * in the same order (secret configured -> module installed -> licensed) and the page
     * is rendered via PublicRoutes::page(). A gate failure is core's standard
     * `{data,error,error_code}` envelope. Sends headers and body; the caller exits.
     *
     * On multisite the caller must already have switched to the link's blog, so the
     * secret, licence and frame-ancestors read here are that site's.
     *
     * @param string $apiBase REST base the page calls, no trailing slash
     */
    public static function servePublicPage(string $which, string $apiBase): void
    {
        try {
            if (!in_array($which, ['share', 'intake'], true)) {
                throw new \FluxFiles\ApiException('Not found', 404, 'not_found');
            }
            $secret = (string) get_option('fluxfiles_secret', '');
            if ($secret === '' || $secret === \FluxFiles\PublicContext::DEFAULT_SECRET) {
                throw new \FluxFiles\ApiException('FLUXFILES_SECRET is not configured', 500, 'server_error');
            }
            \FluxFiles\ModuleRegistry::requireServer($which, self::license());
            $html = \FluxFiles\PublicRoutes::page($which, $apiBase);
            if ($html === null) {
                // Installed, but too old to ship its page (or core cannot find it).
                throw new \FluxFiles\ApiException(
                    "The '{$which}' module needs an update",
                    501,
                    'module_update_required',
                    ['module' => $which]
                );
            }
        } catch (\FluxFiles\ApiException $e) {
            $body = ['data' => null, 'error' => $e->getMessage()];
            if ($e->getErrorCode() !== null) {
                $body['error_code'] = $e->getErrorCode();
            }
            if ($e->getErrorParams()) {
                $body['error_params'] = $e->getErrorParams();
            }
            status_header($e->getHttpCode());
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
            echo wp_json_encode($body);
            return;
        }

        // The URL carries the token: no Referer leak, no shared-proxy caching.
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        // Same frame-ancestors policy as the WP UI (self + site origins, filterable) —
        // core's FrameAncestors helper reads only $_ENV, which WordPress never fills.
        foreach (FluxFilesApi::uiHtmlHeaders('en') as $h) {
            if (stripos($h, 'Content-Security-Policy:') === 0) {
                header($h);
            }
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete static HTML page from the module; the only dynamic value (the API base) is attribute-escaped by PublicRoutes::injectApiBase().
        echo $html;
    }

    /**
     * Get the FluxFiles base installation path (project root).
     */
    public static function basePath(): string
    {
        return dirname(FLUXFILES_PLUGIN_DIR, 2);
    }

    /**
     * Locate a core asset path. Release ZIPs bundle core under <plugin>/{public,assets,lang};
     * monorepo dev checkouts have them at packages/core/{public,assets,lang}.
     */
    public static function corePath(string $subdir): ?string
    {
        $candidates = [
            FLUXFILES_PLUGIN_DIR . $subdir,
            dirname(FLUXFILES_PLUGIN_DIR) . '/core/' . $subdir,
        ];
        foreach ($candidates as $path) {
            if (is_dir($path)) {
                return rtrim($path, '/');
            }
        }
        return null;
    }

    /**
     * The public URL of a bundled recipient page (share.html / intake.html), with the
     * one-shot token attached.
     *
     * Standalone serves these from /public/ at the site root; a WordPress site has no
     * such path, so the link is this site's own REST `<module>/page` route, which injects
     * the REST base the static page cannot know. get_rest_url() is per blog, so on
     * multisite the link already names the minting site and needs no `blog` hint; and on
     * plain permalinks it is the `?rest_route=` form (the token is appended with `&`).
     */
    public static function publicLinkUrl(string $page, string $token = ''): string
    {
        $which = $page === 'intake.html' ? 'intake' : 'share';
        $path = 'fluxfiles/v1/api/fm/' . $which . '/page';
        $url = self::isMultisite() && function_exists('get_rest_url')
            ? get_rest_url((int) get_current_blog_id(), $path)
            : rest_url($path);
        // Token omitted = the BASE url, which is what the *_base_url claims carry: the
        // module appends the token itself, so a base that already had one would
        // produce two and resolve the wrong (first) value.
        return $token !== '' ? add_query_arg(['token' => rawurlencode($token)], $url) : $url;
    }

    public static function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    /**
     * What the REST `<module>/page` route does with a `?blog=` hint (carried by an
     * old link). The target is built from the validated
     * site record, never from the request, so it cannot be steered to another host.
     *
     * @param mixed $blogParam raw `?blog=` value
     * @return array{0:int,1:?string} [200, null] serve here; [404, null] bad blog;
     *                                [302, url] redirect to that blog's own page route
     */
    public static function publicPageRedirect(string $which, $blogParam, string $token): array
    {
        if (!self::isMultisite() || $blogParam === null || $blogParam === '') {
            return [200, null];
        }
        $base = self::publicLinkRestBase($blogParam);
        if ($base === null) {
            return [404, null];
        }
        if ($base === rtrim(rest_url('fluxfiles/v1/api/fm'), '/')) {
            return [200, null];
        }
        $target = $base . '/' . ($which === 'intake' ? 'intake' : 'share') . '/page';
        return [302, $token !== '' ? add_query_arg(['token' => rawurlencode($token)], $target) : $target];
    }

    /**
     * The REST base the recipient page must call, for the site a public link belongs to.
     * Returns null when the link names a blog that is not a live, public site (the caller
     * answers 404). Single site: always this site's base, `$blogParam` is ignored.
     * Multisite: a missing `blog` means the current site (pre-1.0.4 behaviour).
     *
     * @param mixed $blogParam raw `?blog=` value
     */
    public static function publicLinkRestBase($blogParam): ?string
    {
        if (!self::isMultisite()) {
            return rtrim(rest_url('fluxfiles/v1/api/fm'), '/');
        }
        if ($blogParam === null || $blogParam === '') {
            $id = (int) get_current_blog_id();
        } elseif (is_string($blogParam) && preg_match('/^[1-9][0-9]{0,9}\z/', $blogParam) === 1) {
            $id = (int) $blogParam;
        } else {
            return null;
        }
        if (!function_exists('get_site') || !function_exists('get_rest_url')) {
            return null;
        }
        $site = get_site($id);
        if (!$site || (int) $site->blog_id !== $id) {
            return null;
        }
        foreach (['deleted', 'archived', 'spam'] as $flag) {
            if (!empty($site->$flag)) {
                return null;
            }
        }
        return rtrim(get_rest_url($id, 'fluxfiles/v1/api/fm'), '/');
    }

    /**
     * Get disk configurations.
     */
    public static function diskConfigs(): array
    {
        $storagePath = self::storagePath();
        $localUrl = FluxFilesStorage::url($storagePath . '/uploads');

        $disks = [
            'local' => [
                'driver' => 'local',
                'root'   => $storagePath . '/uploads',
                // '' = the base is outside uploads/ and wp-content/ (custom
                // FLUXFILES_STORAGE_DIR): no public URL, bytes go through /stream.
                'url'    => $localUrl,
                // The plugin's own protection files in the disk root: listed by no
                // one (core matches these exact root keys only).
                'hidden_files' => FluxFilesStorage::guardFiles(),
                // true = files are NOT served from the public 'url' above; the UI's
                // list() marks them gated and the embedded client fetches bytes via
                // the signed `/api/fm/stream` route instead. Off by default (the WP
                // content dir is normally web-served).
                'private' => (bool) get_option('fluxfiles_local_private', false) || $localUrl === '',
            ],
        ];

        // S3
        $s3Bucket = get_option('fluxfiles_s3_bucket', '');
        if (!empty($s3Bucket)) {
            $disks['s3'] = [
                'driver'     => 's3',
                'region'     => get_option('fluxfiles_s3_region', 'ap-southeast-1'),
                'bucket'     => $s3Bucket,
                'key'        => get_option('fluxfiles_s3_key', ''),
                'secret'     => get_option('fluxfiles_s3_secret', ''),
                // Custom S3-compatible endpoint (MinIO / DO Spaces). Empty = native AWS.
                'endpoint'   => get_option('fluxfiles_s3_endpoint', ''),
                // 'private' = presigned URLs (default); 'public' = direct object URLs.
                'visibility' => get_option('fluxfiles_s3_visibility', 'private'),
                'public_url' => get_option('fluxfiles_s3_public_url', ''),
                // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h.
                'url_ttl'    => (int) get_option('fluxfiles_s3_url_ttl', 3600),
            ];
        }

        // R2
        $r2Bucket = get_option('fluxfiles_r2_bucket', '');
        if (!empty($r2Bucket)) {
            $accountId = get_option('fluxfiles_r2_account_id', '');
            $disks['r2'] = [
                'driver'     => 's3',
                'endpoint'   => "https://{$accountId}.r2.cloudflarestorage.com",
                'region'     => 'auto',
                'bucket'     => $r2Bucket,
                'key'        => get_option('fluxfiles_r2_key', ''),
                'secret'     => get_option('fluxfiles_r2_secret', ''),
                // 'public' needs a public bucket + public_url (r2.dev / custom domain).
                'visibility' => get_option('fluxfiles_r2_visibility', 'private'),
                'public_url' => get_option('fluxfiles_r2_public_url', ''),
                // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h.
                'url_ttl'    => (int) get_option('fluxfiles_r2_url_ttl', 3600),
            ];
        }

        return $disks;
    }

    /**
     * Generate a JWT token for a WordPress user.
     */
    public static function generateToken(
        int $userId,
        array $overrides = []
    ): string {
        $secret = get_option('fluxfiles_secret', '');

        if (empty($secret)) {
            throw new \RuntimeException('FluxFiles secret is not configured.');
        }

        $defaultPerms  = get_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        $defaultDisks  = get_option('fluxfiles_disks', ['local']);
        $maxUpload     = (int) get_option('fluxfiles_max_upload', 10);
        $maxStorage    = (int) get_option('fluxfiles_max_storage', 0);
        $maxFiles      = (int) get_option('fluxfiles_max_files', 0);
        $ttl           = (int) get_option('fluxfiles_ttl', 3600);

        $now = time();

        // Role preset (DX sugar, docs/design/ACL-ROLE-PRESETS-DESIGN.md): resolved BEFORE the
        // base payload array, because `perms` already has an unconditional default baked
        // into that array below — a plain "set if absent" guard would never fire for it.
        $roleDefaults = self::rolePreset(isset($overrides['role']) ? (string) $overrides['role'] : null);

        $payload = [
            'sub'         => (string) $userId,
            'iat'         => $now,
            'exp'         => $now + ($overrides['ttl'] ?? $ttl),
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? ($roleDefaults['perms'] ?? $defaultPerms),
            'disks'       => $overrides['disks'] ?? $defaultDisks,
            'prefix'      => $overrides['prefix'] ?? '',
            'max_upload'  => $overrides['max_upload'] ?? $maxUpload,
            'allowed_ext' => $overrides['allowed_ext'] ?? null,
            'max_storage' => $overrides['max_storage'] ?? $maxStorage,
            'max_files'   => $overrides['max_files'] ?? $maxFiles,
        ];

        if (array_key_exists('owner_only', $overrides) ? (bool) $overrides['owner_only'] : ($roleDefaults['owner_only'] ?? false)) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides, $roleDefaults);
        // Last, so neither `allowed_ext` nor the raw `claims` escape hatch can undo it.
        self::restrictActiveContent($payload, $userId);
        self::disableFileEditing($payload);

        return \FluxFiles\JwtCompat::encode(self::sealWebhook($payload, (string) $secret), $secret);
    }

    /**
     * The WordPress plugin is a sandboxed media/document library, never a code or
     * file-content editor (WordPress.org guideline: no arbitrary code insertion or
     * execution). Runs last on every token, after role presets, overrides, the raw
     * `claims` escape hatch and the `fluxfiles_token_overrides` filter:
     *
     *  - `allow_code_edit`: core's putContent() deliberately skips the dangerous-
     *    extension block (editing an existing .php is its job on a server you manage),
     *    so it is forced false. Read-only previews (getContent) are unaffected.
     *  - `allow_chmod`: only meaningful on SFTP disks, which exist in WordPress only
     *    through a developer-minted BYOB token. Forced false everywhere rather than
     *    per disk type: simpler, and nothing in the UI depends on it.
     *
     *  - `terminal_pty_url`: no terminal or shell entry in the WordPress plugin; the
     *    claim is removed so the UI toolbar never shows one.
     *
     * @param array<string,mixed> $payload
     */
    private static function disableFileEditing(array &$payload): void
    {
        $payload['allow_code_edit'] = false;
        $payload['allow_chmod']     = false;
        unset($payload['terminal_pty_url']);
    }

    /**
     * File types that render as ACTIVE content (script/markup/server code) when
     * served from the site's own origin. A user without `unfiltered_html` can never
     * be handed a token that uploads them, whatever the override or filter says.
     */
    private const ACTIVE_CONTENT_EXT = [
        'html', 'htm', 'xhtml', 'shtml', 'shtm', 'svg', 'svgz', 'xml', 'xsl', 'xslt',
        'js', 'mjs', 'cjs', 'swf', 'phtml', 'pht', 'phar', 'phps',
    ];

    /**
     * Stored XSS guard (Author → Administrator). The local disk lives under
     * wp-content/, the SAME origin as wp-admin, and only Apache gets the .htaccess
     * that serves uploads inertly — on nginx an uploaded .html/.svg runs script as
     * the site. WordPress itself withholds these types from anyone lacking
     * `unfiltered_html`; FluxFiles used to mint `allowed_ext: null` (= everything).
     *
     * For such a user the minted `allowed_ext` becomes: the requested list (or, when
     * unrestricted, everything) ∩ the extensions get_allowed_mime_types() grants that
     * user, minus ACTIVE_CONTENT_EXT and php*. An empty result stays `[]`, which core
     * reads as "nothing allowed" (fail closed), never as "no restriction".
     * Outside WordPress (no user_can()/get_allowed_mime_types()) it is a no-op.
     *
     * @param array<string,mixed> $payload
     */
    private static function restrictActiveContent(array &$payload, int $userId): void
    {
        if (!function_exists('user_can') || !function_exists('get_allowed_mime_types')) {
            return;
        }
        if (user_can($userId, 'unfiltered_html')) {
            return;
        }
        // The token's user, not the current one: get_allowed_mime_types(null) would
        // judge whoever is logged in (an admin minting a token for an Author).
        $wpExt = [];
        foreach (array_keys((array) get_allowed_mime_types($userId > 0 ? $userId : null)) as $group) {
            foreach (explode('|', strtolower((string) $group)) as $ext) {
                $ext = trim($ext);
                if ($ext !== '') {
                    $wpExt[$ext] = true;
                }
            }
        }
        $requested = $payload['allowed_ext'] ?? null;
        $candidates = is_array($requested)
            ? array_map(static fn ($e) => strtolower(ltrim(trim((string) $e), '.')), $requested)
            : array_keys($wpExt);
        $out = [];
        foreach ($candidates as $ext) {
            if ($ext === '' || !isset($wpExt[$ext]) || in_array($ext, self::ACTIVE_CONTENT_EXT, true)
                || strncmp($ext, 'php', 3) === 0) {
                continue;
            }
            $out[$ext] = true;
        }
        $payload['allowed_ext'] = array_keys($out);
    }

    /**
     * Forward the optional per-tenant override claims into a token payload. The
     * core sanitizes/clamps them on decode, so this only copies them — no hard
     * dependency on a specific core method.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $overrides
     */
    /** @param array<string,mixed> $payload */
    private static function applyEditionPreset(array &$payload, ?string $edition): void
    {
        if ($edition === null || $edition === '') {
            return;
        }
        // The free plugin ships no editions: an add-on (FluxFiles Pro) registers them.
        // Shape: ['<edition>' => ['allow_x' => true, ...]]; anything malformed is skipped.
        $presets = apply_filters('fluxfiles_edition_presets', []);
        // Match case-insensitively: normalise the map's keys the same way $key is.
        if (is_array($presets)) {
            $presets = array_change_key_case($presets, CASE_LOWER);
        }
        $key = strtolower($edition);
        if (!is_array($presets) || !isset($presets[$key]) || !is_array($presets[$key])) {
            // A typo or an edition whose add-on is not installed: ignore (mint stays
            // valid, just without the extra claims) and tell the developer under WP_DEBUG.
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('_doing_it_wrong')) {
                _doing_it_wrong(
                    'fluxfiles_token',
                    sprintf('Unknown FluxFiles edition "%s" ignored (registered: %s).', esc_html($edition), is_array($presets) && $presets ? esc_html(implode(', ', array_keys($presets))) : 'none'),
                    '1.1.0'
                );
            }
            return;
        }
        foreach ($presets[$key] as $k => $v) {
            if (is_string($k) && !array_key_exists($k, $payload)) {
                $payload[$k] = $v;
            }
        }
    }

    /**
     * Look up a role preset's raw claim map (DX sugar, docs/design/ACL-ROLE-PRESETS-DESIGN.md).
     * `role` never itself becomes a JWT claim — it only ever expands, at mint time, into
     * ordinary claims the core already decodes. Mirrors packages/core/embed.php's
     * fluxfiles_role_preset() / packages/laravel/src/FluxFilesManager.php's rolePreset().
     *
     * @return array<string,mixed>
     */
    private static function rolePreset(?string $role): array
    {
        $presets = [
            'viewer'     => ['perms' => ['read'], 'owner_only' => true,
                              'allow_extract' => false, 'allow_chmod' => false],
            'editor'     => ['perms' => ['read', 'write'], 'owner_only' => true,
                              'allow_extract' => true, 'allow_chmod' => false],
            'admin'      => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                              'allow_extract' => true, 'allow_chmod' => false, 'show_hidden' => true],
            'superadmin' => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                              'allow_extract' => true, 'allow_chmod' => false, 'show_hidden' => true],
        ];

        if ($role === null || $role === '') {
            return [];
        }
        $key = strtolower($role);
        if (!isset($presets[$key])) {
            // An unknown role used to expand to NOTHING, so the token fell back to the
            // site-wide default perms (read+write+delete) — a typo like `'veiwer'`
            // failed OPEN. Refuse to mint instead.
            throw new \InvalidArgumentException('Unknown FluxFiles role "' . esc_html($role) . '" (expected: ' . esc_html(implode(', ', array_keys($presets))) . ')');
        }
        return $presets[$key];
    }

    /**
     * Map the CURRENT WordPress user's capabilities onto a role preset name,
     * so the minted token can never exceed what they may do on the site.
     *
     *   manage_options       → admin  (read/write/delete/audit, cross-owner)
     *   delete_others_posts  → admin  (an Editor already deletes other people's content)
     *   upload_files         → editor (read/write, owner_only)
     *   anything else        → viewer (read, owner_only)
     *
     * NOT `delete_posts`: that is "delete MY OWN posts" and every Contributor and
     * Author holds it by default, so mapping it to admin handed both roles a
     * cross-owner read/write/delete + code-edit + chmod token over the whole disk.
     *
     * The presets are still intersected with the site-wide
     * `fluxfiles_default_perms` ceiling by generateToken(): a role can only
     * ever narrow, never widen, what the operator configured. Returns null when
     * the capability functions are unavailable (non-WP context / unit stubs),
     * which leaves the previous behaviour untouched.
     */
    private static function roleForCapabilities(): ?string
    {
        if (!function_exists('current_user_can')) {
            return null;
        }
        if (current_user_can('manage_options') || current_user_can('delete_others_posts')) {
            return 'admin';
        }
        if (current_user_can('upload_files')) {
            return 'editor';
        }
        return 'viewer';
    }

    /**
     * Seal the webhook HMAC key into `webhook_secret_enc` right before encoding (a JWT is
     * signed, not encrypted). Needs core >= 1.1.3; on an older core a supplied secret is
     * refused rather than ever being emitted in plaintext.
     */
    private static function sealWebhook(array $payload, string $secret): array
    {
        if (\method_exists(\FluxFiles\CredentialEncryptor::class, 'sealWebhookSecret')) {
            return \FluxFiles\CredentialEncryptor::sealWebhookSecret($payload, $secret);
        }
        if (isset($payload['webhook_secret']) && (string) $payload['webhook_secret'] !== '') {
            throw new \RuntimeException('webhook_secret requires fluxfiles/fluxfiles >= 1.1.3 (it is sealed into the token, never sent in plaintext). Upgrade core or drop webhook_secret.');
        }
        unset($payload['webhook_secret']);
        return $payload;
    }

    /**
     * Apply a role preset's default claims onto $payload. Only sets a claim when it's not
     * already present, so explicit overrides win. Deliberately excludes `perms` and
     * `owner_only` — both are already resolved earlier (in generateToken()/generateByobToken(),
     * before this function ever runs), because unlike these claims they already have an
     * unconditional default baked into the base payload array and this guard would never
     * fire for them.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $roleDefaults
     */
    private static function applyRolePreset(array &$payload, array $roleDefaults): void
    {
        foreach ($roleDefaults as $k => $v) {
            if ($k !== 'perms' && $k !== 'owner_only' && !array_key_exists($k, $payload)) {
                $payload[$k] = $v;
            }
        }
    }

    private static function applyTenantOverrides(array &$payload, array $overrides, array $roleDefaults = []): void
    {
        // Edition preset (DX sugar): default a tier's claims before explicit overrides.
        self::applyEditionPreset($payload, isset($overrides['edition']) ? (string) $overrides['edition'] : null);
        // Role preset: the rest of the bundle (perms/owner_only already resolved by the
        // caller before applyTenantOverrides() ever runs). Reaches BYOB tokens too, since
        // this method is already shared by generateToken()/generateByobToken() here and
        // edition already reaches both — a deliberate consistency choice, not an oversight.
        self::applyRolePreset($payload, $roleDefaults);
        if (isset($overrides['ai_auto_tag'])) {
            $payload['ai_auto_tag'] = (bool) $overrides['ai_auto_tag'];
        }
        if (!empty($overrides['rate_read'])) {
            $payload['rate_read'] = (int) $overrides['rate_read'];
        }
        if (!empty($overrides['rate_write'])) {
            $payload['rate_write'] = (int) $overrides['rate_write'];
        }
        if (is_array($overrides['variants'] ?? null) && $overrides['variants'] !== []) {
            $payload['variants'] = $overrides['variants'];
        }
        // URL-import claims.
        if (!empty($overrides['allow_url_import'])) {
            $payload['allow_url_import'] = true;
        }
        foreach (['max_import_mb', 'import_rate_limit', 'import_concurrency'] as $intClaim) {
            if (!empty($overrides[$intClaim])) {
                $payload[$intClaim] = (int) $overrides[$intClaim];
            }
        }
        if (!empty($overrides['import_path'])) {
            $payload['import_path'] = (string) $overrides['import_path'];
        }
        if (is_array($overrides['import_url_allowlist'] ?? null) && $overrides['import_url_allowlist'] !== []) {
            $payload['import_url_allowlist'] = array_values($overrides['import_url_allowlist']);
        }
        // Media-preview claims.
        if (array_key_exists('media_preview', $overrides)) {
            $payload['media_preview'] = (bool) $overrides['media_preview'];
        }
        foreach (['preview_url_ttl', 'max_preview_mb', 'stream_token_ttl'] as $mediaClaim) {
            if (!empty($overrides[$mediaClaim])) {
                $payload[$mediaClaim] = (int) $overrides[$mediaClaim];
            }
        }
        // On-demand WebP claims.
        if (array_key_exists('webp_enabled', $overrides)) {
            $payload['webp_enabled'] = (bool) $overrides['webp_enabled'];
        }
        foreach (['webp_max_width', 'webp_default_quality'] as $webpClaim) {
            if (!empty($overrides[$webpClaim])) {
                $payload[$webpClaim] = (int) $overrides[$webpClaim];
            }
        }
        // Responsive srcset (Claims sanitizes the ladder on decode).
        if (isset($overrides['srcset_widths']) && is_array($overrides['srcset_widths'])) {
            $payload['srcset_widths'] = array_values(array_map('intval', $overrides['srcset_widths']));
        }
        if (!empty($overrides['srcset_sizes'])) {
            $payload['srcset_sizes'] = (string) $overrides['srcset_sizes'];
        }
        // Download gate + watermark.
        if (array_key_exists('allow_download', $overrides)) {
            $payload['allow_download'] = (bool) $overrides['allow_download'];
        }
        // `allow_chmod` / `allow_code_edit` are deliberately not overridable here:
        // disableFileEditing() forces both off on every token (see below).
        // `terminal_pty_url` is not forwarded either: the WordPress plugin has no
        // terminal entry (disableFileEditing() strips it from every token).
        // PDF-tools + office embeds are pure UI (no core endpoint) → work in any mode.
        if (!empty($overrides['pdf_tools_url'])) {
            $payload['pdf_tools_url'] = (string) $overrides['pdf_tools_url'];
        }
        if (!empty($overrides['office_url'])) {
            $payload['office_url'] = (string) $overrides['office_url'];
        }
        if (!empty($overrides['esign_url'])) {
            $payload['esign_url'] = (string) $overrides['esign_url'];
        }
        // About dialog + light white-label (pure UI; the core sanitizes on decode).
        if (array_key_exists('about', $overrides)) {
            $payload['about'] = (bool) $overrides['about'];
        }
        foreach (['brand_name', 'brand_logo_url', 'accent_color'] as $brandClaim) {
            if (!empty($overrides[$brandClaim])) {
                $payload[$brandClaim] = (string) $overrides[$brandClaim];
            }
        }
        // Share and Intake. These used to be stripped here, because the REST API
        // exposed none of their endpoints and a forwarded claim would have rendered a
        // button that 404s. It now proxies all eleven — the six operator routes and the
        // five public recipient ones — so the claims are forwarded like any other.
        foreach (['allow_share', 'allow_intake'] as $mc) {
            if (array_key_exists($mc, $overrides)) {
                $payload[$mc] = (bool) $overrides[$mc];
            }
        }
        // Share landing config, read by the module at create time and baked into the
        // record, so it travels with the gate claim (core clamps the TTL and drops a
        // non-http(s) base URL on decode).
        if (!empty($overrides['share_url_ttl'])) {
            $payload['share_url_ttl'] = (int) $overrides['share_url_ttl'];
        }
        if (array_key_exists('share_preview', $overrides)) {
            $payload['share_preview'] = (bool) $overrides['share_preview'];
        }
        if (array_key_exists('share_analytics', $overrides)) {
            $payload['share_analytics'] = (bool) $overrides['share_analytics'];
        }
        // The recipient link base. Defaults to this site's own page rather than being
        // left empty: empty makes the module fall back to the request origin plus
        // `/public/share.html`, a path a WordPress site does not have.
        $payload['share_base_url'] = !empty($overrides['share_base_url'])
            ? (string) $overrides['share_base_url']
            : self::publicLinkUrl('share.html');
        $payload['intake_base_url'] = !empty($overrides['intake_base_url'])
            ? (string) $overrides['intake_base_url']
            : self::publicLinkUrl('intake.html');
        if (array_key_exists('intake_analytics', $overrides)) {
            $payload['intake_analytics'] = (bool) $overrides['intake_analytics'];
        }
        // Share/intake landing branding — like the config above, read at create time
        // and baked into the record, so a later token change never rewrites an
        // already-published link. The core drops a non-http(s) logo/link URL and
        // clamps the colour on decode.
        foreach ([
            'share_brand_name', 'share_brand_logo_url',
            'share_brand_color', 'share_brand_link_url',
            'intake_brand_name', 'intake_brand_logo_url',
            'intake_brand_color', 'intake_brand_link_url',
        ] as $brandClaim) {
            if (!empty($overrides[$brandClaim])) {
                $payload[$brandClaim] = (string) $overrides[$brandClaim];
            }
        }
        // File versioning. This used to be stripped here, because the REST API exposed
        // no /api/fm/versions* endpoint and a forwarded claim would have rendered a
        // History panel that 404s. It now proxies list + restore (see FluxFilesApi's
        // handleVersions()/handleVersionsRestore()), so the gate + its tuning claims
        // forward like any other, same as allow_share/allow_intake above.
        if (array_key_exists('allow_versioning', $overrides)) {
            $payload['allow_versioning'] = (bool) $overrides['allow_versioning'];
        }
        foreach (['versioning_max', 'versioning_max_mb'] as $verClaim) {
            if (!empty($overrides[$verClaim])) {
                $payload[$verClaim] = (int) $overrides[$verClaim];
            }
        }
        // Audit export/purge now proxies too (see FluxFilesApi's
        // handleAuditExport()/handleAuditPurge()), so the gate + its retention-days
        // tuning claim forward like any other, same as allow_versioning above.
        if (array_key_exists('allow_audit_export', $overrides)) {
            $payload['allow_audit_export'] = (bool) $overrides['allow_audit_export'];
        }
        if (!empty($overrides['retention_rules']) && is_array($overrides['retention_rules'])) {
            $payload['retention_rules'] = array_values($overrides['retention_rules']);
        }
        if (array_key_exists('media_posters', $overrides)) {
            $payload['media_posters'] = (bool) $overrides['media_posters'];
        }
        if (array_key_exists('allow_presign_put', $overrides)) {
            $payload['allow_presign_put'] = (bool) $overrides['allow_presign_put'];
        }
        if (array_key_exists('strip_exif', $overrides)) {
            $payload['strip_exif'] = (bool) $overrides['strip_exif'];
        }
        if (array_key_exists('audit_reads', $overrides)) {
            $payload['audit_reads'] = (bool) $overrides['audit_reads'];
        }
        if (!empty($overrides['audit_retention_days'])) {
            $payload['audit_retention_days'] = (int) $overrides['audit_retention_days'];
        }
        // AI Vision now proxies too (see FluxFilesApi's handleAiVision()), so the gate
        // forwards like any other, same as allow_versioning/allow_audit_export above.
        if (array_key_exists('allow_ai_vision', $overrides)) {
            $payload['allow_ai_vision'] = (bool) $overrides['allow_ai_vision'];
        }
        // DLP / PII scan now proxies too (see FluxFilesApi's fileManager() setDlpScanner()
        // wiring + the chunk-route 409 checks), so the gate + its tuning claims forward
        // like any other, same as allow_versioning/allow_audit_export above.
        if (array_key_exists('allow_dlp_scan', $overrides)) {
            $payload['allow_dlp_scan'] = (bool) $overrides['allow_dlp_scan'];
        }
        if (!empty($overrides['dlp_entity_types'])) {
            $payload['dlp_entity_types'] = (array) $overrides['dlp_entity_types'];
        }
        if (!empty($overrides['dlp_scan_extensions'])) {
            $payload['dlp_scan_extensions'] = (array) $overrides['dlp_scan_extensions'];
        }
        if (!empty($overrides['dlp_max_scan_kb'])) {
            $payload['dlp_max_scan_kb'] = (int) $overrides['dlp_max_scan_kb'];
        }
        if (!empty($overrides['dlp_min_score'])) {
            $payload['dlp_min_score'] = (float) $overrides['dlp_min_score'];
        }
        // Legal hold now proxies too (see FluxFilesApi's
        // handleHold()/handleHoldRelease()/handleHoldList()/handleHoldStatus()), so
        // the gate forwards like any other, same as allow_versioning/allow_audit_export/
        // allow_dlp_scan above. Enforcement itself needs no claim — it's wired inside
        // FileManager.
        if (array_key_exists('allow_legal_hold', $overrides)) {
            $payload['allow_legal_hold'] = (bool) $overrides['allow_legal_hold'];
        }
        // NOTE: SSO (FLUXFILES_SSO_*) is NOT a claim-forwarding concern at all — those
        // are pure server env vars configuring the standalone /public UI's own login
        // endpoint. They never travel inside a JWT, so there is nothing to forward
        // here either. SSO only applies to deployments with no host app (like this
        // plugin) minting tokens in the first place.
        foreach (['allow_webhooks', 'allow_ocr', 'allow_virus_scan', 'allow_backup', 'allow_c2pa'] as $mc) {
            if (array_key_exists($mc, $overrides)) {
                $payload[$mc] = (bool) $overrides[$mc];
            }
        }
        // Webhook config. Without a URL `allow_webhooks` is inert (the module has
        // nowhere to POST), so these travel with the gate claim. The core drops a
        // non-http(s) URL on decode; `webhook_secret` is sealed into
        // `webhook_secret_enc` at encode time (never plaintext); unset = the derived key.
        if (!empty($overrides['webhook_url'])) {
            $payload['webhook_url'] = (string) $overrides['webhook_url'];
        }
        if (!empty($overrides['webhook_events'])) {
            // Array or a comma-separated string (the core normalizes both), so a plain
            // settings text field works as well as a list.
            $payload['webhook_events'] = is_array($overrides['webhook_events'])
                ? array_values($overrides['webhook_events'])
                : (string) $overrides['webhook_events'];
        }
        // Plaintext here only until sealed into `webhook_secret_enc` just before the token is
        // encoded (sealWebhookSecret) — a JWT is signed, not encrypted.
        if (isset($overrides['webhook_secret']) && (string) $overrides['webhook_secret'] !== '') {
            $payload['webhook_secret'] = (string) $overrides['webhook_secret'];
        }
        if (array_key_exists('allow_optimize', $overrides)) {
            $payload['allow_optimize'] = (bool) $overrides['allow_optimize'];
        }
        if (array_key_exists('auto_optimize', $overrides)) {
            $payload['auto_optimize'] = (bool) $overrides['auto_optimize'];
        }
        if (!empty($overrides['optimize_quality'])) {
            $payload['optimize_quality'] = (int) $overrides['optimize_quality'];
        }
        if (array_key_exists('optimize_keep_original', $overrides)) {
            $payload['optimize_keep_original'] = (bool) $overrides['optimize_keep_original'];
        }
        if (!empty($overrides['optimize_max_mb'])) {
            $payload['optimize_max_mb'] = (int) $overrides['optimize_max_mb'];
        }
        if (isset($overrides['pdf_level'])
            && in_array($overrides['pdf_level'], ['screen', 'ebook', 'printer', 'prepress', 'default'], true)) {
            $payload['pdf_level'] = (string) $overrides['pdf_level'];
        }
        if (isset($overrides['upload_collision'])
            && in_array($overrides['upload_collision'], ['rename', 'overwrite', 'reject'], true)) {
            $payload['upload_collision'] = (string) $overrides['upload_collision'];
        }
        if (array_key_exists('show_hidden', $overrides)) {
            $payload['show_hidden'] = (bool) $overrides['show_hidden'];
        }
        if (array_key_exists('dedupe_uploads', $overrides)) {
            $payload['dedupe_uploads'] = (bool) $overrides['dedupe_uploads'];
        }
        if (array_key_exists('allow_zip', $overrides)) {
            $payload['allow_zip'] = (bool) $overrides['allow_zip'];
        }
        if (array_key_exists('allow_extract', $overrides)) {
            $payload['allow_extract'] = (bool) $overrides['allow_extract'];
        }
        foreach (['zip_max_mb', 'zip_max_files'] as $zipClaim) {
            if (!empty($overrides[$zipClaim])) {
                $payload[$zipClaim] = (int) $overrides[$zipClaim];
            }
        }
        // OVERLAY watermark (preview-time, served via /api/fm/img): the proxy's /img is
        // the shared core MediaEndpoint, which composites it. The config claims travel
        // with the gate, and Claims clamps them on decode.
        if (!empty($overrides['watermark_enabled'])) {
            $payload['watermark_enabled'] = true;
            foreach (['watermark_type', 'watermark_text', 'watermark_logo_path', 'watermark_position'] as $wmClaim) {
                if (!empty($overrides[$wmClaim])) {
                    $payload[$wmClaim] = (string) $overrides[$wmClaim];
                }
            }
            if (isset($overrides['watermark_opacity'])) {
                $payload['watermark_opacity'] = (float) $overrides['watermark_opacity'];
            }
            if (!empty($overrides['watermark_font_size'])) {
                $payload['watermark_font_size'] = (int) $overrides['watermark_font_size'];
            }
        }
        // Usage-dashboard claims.
        foreach ([
            'usage_cache_ttl', 'usage_warning_threshold', 'usage_critical_threshold',
            'usage_top_folders_count', 'usage_folder_depth',
        ] as $usageClaim) {
            if (isset($overrides[$usageClaim]) && $overrides[$usageClaim] !== '') {
                $payload[$usageClaim] = (int) $overrides[$usageClaim];
            }
        }

        // UI upsell hints for paid modules. Core decodes an absent claim as TRUE, so the
        // free plugin always embeds it: false unless the caller (or an add-on that
        // sells modules) explicitly turns it on.
        $payload['pro_hints'] = array_key_exists('pro_hints', $overrides) ? (bool) $overrides['pro_hints'] : false;

        // Generic escape hatch: any JWT claim by its raw snake_case name, e.g.
        // ['claims' => ['allow_optimize' => true, 'upload_collision' => 'overwrite']].
        // Merged last so explicit claims win; the core sanitizes on decode. See docs/reference/CONFIG.md.
        if (!empty($overrides['claims']) && is_array($overrides['claims'])) {
            foreach ($overrides['claims'] as $k => $v) {
                if ($v !== null) {
                    $payload[(string) $k] = $v;
                }
            }
        }
    }

    /**
     * Generate a BYOB (Bring Your Own Bucket) JWT token.
     *
     * @param int   $userId    WordPress user ID
     * @param array $byobDisks Map of disk name => S3 config array
     * @param array $overrides Optional overrides
     */
    public static function generateByobToken(
        int $userId,
        array $byobDisks,
        array $overrides = []
    ): string {
        $secret = get_option('fluxfiles_secret', '');

        if (empty($secret)) {
            throw new \RuntimeException('FluxFiles secret is not configured.');
        }

        $defaultPerms = get_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        $defaultDisks = get_option('fluxfiles_disks', ['local']);
        $maxUpload    = (int) get_option('fluxfiles_max_upload', 10);
        $maxStorage   = (int) get_option('fluxfiles_max_storage', 0);
        $ttl          = (int) get_option('fluxfiles_ttl', 3600);

        $now = time();

        // Encrypt BYOB disk configs
        $encryptedDisks = [];
        foreach ($byobDisks as $name => $config) {
            \FluxFiles\CredentialEncryptor::validate($name, $config);
            $encryptedDisks[$name] = \FluxFiles\CredentialEncryptor::encrypt($config, $secret);
        }

        $serverDisks = $overrides['disks'] ?? $defaultDisks;
        $allDisks = array_merge($serverDisks, array_keys($byobDisks));

        // Role preset — see the identical early-resolution note in generateToken() above.
        $roleDefaults = self::rolePreset(isset($overrides['role']) ? (string) $overrides['role'] : null);

        $payload = [
            'sub'         => (string) $userId,
            'iat'         => $now,
            'exp'         => $now + min($overrides['ttl'] ?? $ttl, 1800), // cap at 1800s for BYOB
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? ($roleDefaults['perms'] ?? $defaultPerms),
            'disks'       => $allDisks,
            'prefix'      => $overrides['prefix'] ?? '',
            'max_upload'  => $overrides['max_upload'] ?? $maxUpload,
            'allowed_ext' => $overrides['allowed_ext'] ?? null,
            'max_storage' => $overrides['max_storage'] ?? $maxStorage,
            'byob_disks'  => $encryptedDisks,
        ];

        if (array_key_exists('owner_only', $overrides) ? (bool) $overrides['owner_only'] : ($roleDefaults['owner_only'] ?? false)) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides, $roleDefaults);
        // Last, so neither `allowed_ext` nor the raw `claims` escape hatch can undo it.
        self::restrictActiveContent($payload, $userId);
        self::disableFileEditing($payload);

        return \FluxFiles\JwtCompat::encode(self::sealWebhook($payload, (string) $secret), $secret);
    }

    /**
     * Generate a token for the current logged-in user.
     *
     * The built-in shortcode / media button / REST proxy mint tokens through
     * here, so the `fluxfiles_token_overrides` filter is the supported way to
     * set per-tenant claims (e.g. enable Import from URL) without a custom
     * token caller:
     *
     *   add_filter('fluxfiles_token_overrides', function ($overrides, $userId) {
     *       $overrides['allow_url_import'] = true;
     *       $overrides['max_import_mb']    = 20;
     *       return $overrides;
     *   }, 10, 2);
     */
    public static function tokenForCurrentUser(array $overrides = []): string
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            throw new \RuntimeException('No authenticated WordPress user.');
        }

        // Cap the minted token by what this WP user may actually do on this
        // site. Without this, every logged-in user — including a Subscriber on
        // an open-registration site — got the same read+write+delete token over
        // an empty prefix, because `fluxfiles_default_perms` is one site-wide
        // value applied to everybody.
        //
        // `role` (not `perms`) so it goes through the same claim bundle the
        // other three token builders use: viewer is read + owner_only, editor
        // adds write, admin adds delete. Then intersected with the site-wide
        // option, so this path can only ever NARROW what the operator
        // configured — a site set read-only stays read-only for an
        // administrator too.
        //
        // Only this implicit, capability-derived path is capped. An explicit
        // `role`/`perms` override is a deliberate operator decision and still
        // means exactly what it means in the other three builders; so does the
        // fluxfiles_token_overrides filter below, which runs after this.
        if (!isset($overrides['role']) && !isset($overrides['perms'])) {
            // Same capability bar as the REST routes (FluxFilesApi::checkAuth). The
            // shortcode, block editor, media button and media-library integration
            // all mint through here and embed the result as a Bearer token — which
            // checkAuth() then accepts WITHOUT re-checking the capability. So the
            // gate has to live at mint time too, or `[fluxfiles]` on any page hands
            // a Subscriber a read token over every user's files. Operators who want
            // that can lower the bar via the `fluxfiles_required_capability` filter.
            if (function_exists('current_user_can') && class_exists('FluxFilesApi')
                && !current_user_can(FluxFilesApi::requiredCapability())) {
                throw new \RuntimeException('The current WordPress user may not use FluxFiles.');
            }
            $role = self::roleForCapabilities();
            if ($role !== null) {
                $overrides['role']  = $role;
                $bundle             = self::rolePreset($role);
                $overrides['perms'] = array_values(array_intersect(
                    $bundle['perms'] ?? [],
                    (array) get_option('fluxfiles_default_perms', ['read', 'write', 'delete'])
                ));
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fluxfiles_token_overrides', $overrides, $userId);
            if (is_array($filtered)) {
                $overrides = $filtered;
            }
        }

        return self::generateToken($userId, $overrides);
    }

    /**
     * Get the FluxFiles REST API endpoint base URL.
     */
    /**
     * The disks enabled under Settings › Access, in order, for the picker's disk switcher.
     * The token carries the same list; without it the embedded UI only offers `local`.
     *
     * @return string[]
     */
    public static function enabledDisks(): array
    {
        $disks = array_values(array_filter(
            array_map('strval', (array) get_option('fluxfiles_disks', ['local'])),
            static function ($d) { return in_array($d, ['local', 's3', 'r2'], true); }
        ));
        return $disks ?: ['local'];
    }

    public static function apiEndpoint(): string
    {
        return rest_url('fluxfiles/v1');
    }
}
