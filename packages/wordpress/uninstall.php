<?php
/**
 * Runs when FluxFiles is DELETED from the Plugins screen (not on deactivation).
 *
 * Always removed: the plugin's settings options, usage transients and the
 * wp_fluxfiles_rate_limits table, on every site of a multisite network.
 *
 * Kept unless the site opted in (Settings > FluxFiles > "Delete data on
 * uninstall"): everything that belongs to the stored files, i.e. the metadata,
 * directories, trash, audit log (+ chain head) and legal-hold tables, the
 * `fluxfiles_secret` (it is the audit chain's HMAC key) and the files
 * themselves. With the opt-in, all of it goes, and the files only when the
 * storage base is the plugin's own location (never a FLUXFILES_STORAGE_DIR /
 * `fluxfiles_storage_base` filter override, never a folder that contains the
 * WordPress install, wp-content, uploads or the plugins dir) and only the
 * files the plugin wrote.
 *
 * Add-on plugins (FluxFiles Pro) keep their own options, transients and user
 * meta in their own namespace; none of those names may appear here.
 *
 * @package FluxFiles
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/FluxFilesDbSchema.php';
require_once __DIR__ . '/includes/FluxFilesStorage.php';

(static function (): void {
    // Everything the plugin registers with register_setting() plus the options it
    // reads/writes without a form field. A test asserts registered ⊆ this list.
    $options = [
        'fluxfiles_max_upload', 'fluxfiles_max_storage', 'fluxfiles_ttl',
        'fluxfiles_storage_backend', 'fluxfiles_default_perms', 'fluxfiles_disks',
        'fluxfiles_offload', 'fluxfiles_replace_picker', 'fluxfiles_delete_storage',
        'fluxfiles_delete_data_on_uninstall',
        'fluxfiles_s3_bucket', 'fluxfiles_s3_region', 'fluxfiles_s3_key', 'fluxfiles_s3_secret',
        'fluxfiles_s3_endpoint', 'fluxfiles_s3_visibility', 'fluxfiles_s3_public_url', 'fluxfiles_s3_url_ttl',
        'fluxfiles_r2_bucket', 'fluxfiles_r2_account_id', 'fluxfiles_r2_key', 'fluxfiles_r2_secret',
        'fluxfiles_r2_visibility', 'fluxfiles_r2_public_url', 'fluxfiles_r2_url_ttl',
        'fluxfiles_local_private', 'fluxfiles_picker_multiple', 'fluxfiles_max_files',
        'fluxfiles_ai_provider', 'fluxfiles_ai_api_key', 'fluxfiles_ai_model', 'fluxfiles_ai_base_url',
        'fluxfiles_db_version', 'fluxfiles_storage_base',
    ];

    // Options that belong to the stored data: removed only with the opt-in.
    $dataOptions = ['fluxfiles_secret'];

    // Local files of ONE site's storage root, only inside the plugin's own
    // `uploads/` disk root plus its known state; a custom FLUXFILES_STORAGE_DIR
    // is never wiped wholesale.
    $rmFile = static function (string $path): void {
        if (function_exists('wp_delete_file')) {
            wp_delete_file($path);
        } else {
            unlink($path);
        }
    };
    $rmdirIfEmpty = static function (string $dir): void {
        if (is_dir($dir) && !is_link($dir) && count((array) scandir($dir)) <= 2) {
            rmdir($dir);
        }
    };
    // Symlinks are unlinked, never followed.
    $rmTree = static function (string $dir) use ($rmFile, $rmdirIfEmpty): void {
        if (is_link($dir) || is_file($dir)) {
            $rmFile($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) {
                $rmdirIfEmpty($f->getPathname());
            } else {
                $rmFile($f->getPathname());
            }
        }
        $rmdirIfEmpty($dir);
    };

    // True when deleting under $root could reach the WordPress install itself: the
    // root equals or CONTAINS ABSPATH, wp-content, the uploads dir or the plugins dir.
    $norm = static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');
    $unsafeRoot = static function (string $root) use ($norm): bool {
        $root = $norm($root);
        if ($root === '' || strpos($root, '..') !== false) {
            return true;
        }
        $roots = [$root];
        $real = realpath($root);
        if ($real !== false) {
            $roots[] = $norm($real);
        }
        $guard = [];
        foreach (['ABSPATH', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR'] as $c) {
            if (defined($c)) {
                $guard[] = (string) constant($c);
            }
        }
        $uploads = FluxFilesStorage::uploadsBase();
        if ($uploads !== null) {
            $guard[] = $uploads;
        }
        if (function_exists('wp_upload_dir')) {
            $u = wp_upload_dir(null, false);
            if (is_array($u) && !empty($u['basedir'])) {
                $guard[] = (string) $u['basedir'];
            }
        }
        foreach ($guard as $g) {
            $variants = [$norm($g)];
            $rg = realpath($g);
            if ($rg !== false) {
                $variants[] = $norm($rg);
            }
            foreach ($variants as $v) {
                foreach ($roots as $r) {
                    if ($v === $r || strpos($v . '/', $r . '/') === 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    };

    $deleteFiles = static function (string $root) use ($rmTree, $rmFile, $rmdirIfEmpty, $unsafeRoot): void {
        if ($root === '' || $root === '/' || $unsafeRoot($root) || $unsafeRoot($root . '/uploads')) {
            return;
        }
        $rmTree($root . '/uploads');
        // Protection files: only when they are the plugin's own (marker / exact stub).
        $htaccess = $root . '/.htaccess';
        if (is_file($htaccess) && !is_link($htaccess)
            && strpos((string) file_get_contents($htaccess, false, null, 0, 256), FluxFilesStorage::PROTECT_MARKER) !== false) {
            $rmFile($htaccess);
        }
        $index = $root . '/index.php';
        if (is_file($index) && !is_link($index) && file_get_contents($index) === FluxFilesStorage::INDEX_STUB) {
            $rmFile($index);
        }
        foreach (['rate_limit.json', 'rate_limit.d'] as $name) {
            $rmTree($root . '/' . $name);
        }
        $rmdirIfEmpty($root); // only if nothing else lives there
    };

    $cleanSite = static function (?int $blogId, string $base, bool $ownBase) use ($options, $dataOptions, $deleteFiles): void {
        global $wpdb;

        // Read the opt-in BEFORE the options go.
        $wipe = get_option('fluxfiles_delete_data_on_uninstall', '0') === '1';
        if ($wipe && $ownBase && $base !== '') {
            $deleteFiles($blogId === null ? $base : $base . '/sites/' . $blogId);
        }

        foreach ($options as $name) {
            delete_option($name);
        }
        if ($wipe) {
            foreach ($dataOptions as $name) {
                delete_option($name);
            }
        }

        // Usage cache transients (fluxfiles_usage_<md5>) and their timeouts.
        if (isset($wpdb) && is_object($wpdb)) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_fluxfiles_usage_') . '%',
                $wpdb->esc_like('_transient_timeout_fluxfiles_usage_') . '%'
            ));
            foreach (FluxFilesDbSchema::tableNames() as $table) {
                // Without the opt-in only the rate-limit counters go; the tables that
                // describe the stored files (metadata, trash, audit, holds) stay.
                if (!$wipe && substr($table, -strlen('_rate_limits')) !== '_rate_limits') {
                    continue;
                }
                // Names are $wpdb->prefix + fixed literals (FluxFilesDbSchema), never user input.
                $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
        }
    };

    $base = FluxFilesStorage::base();
    // Files are deleted only at the plugin's own location, never at an operator override.
    $ownBase = FluxFilesStorage::source() === 'option';
    FluxFilesStorage::uploadsBase(); // resolve (and cache) the main-site uploads dir once, before the per-site loop

    if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
        $offset = 0;
        $batch = 100;
        $network = function_exists('get_current_network_id') ? (int) get_current_network_id() : 1;
        do {
            $ids = (array) get_sites(['fields' => 'ids', 'number' => $batch, 'offset' => $offset, 'network_id' => $network]);
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                $cleanSite((int) $id, $base, $ownBase);
                restore_current_blog();
            }
            $offset += $batch;
        } while (count($ids) === $batch);

        // Network-level copies (the storage base is a site option of THIS network).
        foreach ($options as $name) {
            delete_site_option($name);
        }
        // Drop the now-empty shared folders; non-empty ones are left alone.
        if ($ownBase && !$unsafeRoot($base)) {
            $rmdirIfEmpty($base . '/sites');
            $rmdirIfEmpty($base);
        }
        return;
    }

    $cleanSite(null, $base, $ownBase);
    delete_site_option('fluxfiles_storage_base');
})();
