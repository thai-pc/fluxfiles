<?php
/**
 * Runs when FluxFiles is DELETED from the Plugins screen (not on deactivation).
 *
 * Always removed: this plugin's options, usage transients, the wp_fluxfiles_*
 * tables, on every site of a multisite network. Stored files are removed ONLY
 * when the site opted in (Settings > FluxFiles > "Delete data on uninstall").
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
        'fluxfiles_secret', 'fluxfiles_max_upload', 'fluxfiles_max_storage', 'fluxfiles_ttl',
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

    // Local files of ONE site's storage root, only inside the plugin's own
    // `uploads/` disk root plus its known state; a custom FLUXFILES_STORAGE_DIR
    // is never wiped wholesale.
    $rmTree = static function (string $dir): void {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
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
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    };
    $deleteFiles = static function (string $root) use ($rmTree): void {
        if ($root === '' || $root === '/') {
            return;
        }
        $rmTree($root . '/uploads');
        foreach (['.htaccess', 'index.php', 'rate_limit.json', 'rate_limit.d'] as $name) {
            $rmTree($root . '/' . $name);
        }
        @rmdir($root); // only if nothing else lives there
    };

    $cleanSite = static function (?int $blogId, string $base) use ($options, $deleteFiles): void {
        global $wpdb;

        // Read the opt-in BEFORE the options go.
        if (get_option('fluxfiles_delete_data_on_uninstall', '0') === '1' && $base !== '') {
            $deleteFiles($blogId === null ? $base : $base . '/sites/' . $blogId);
        }

        foreach ($options as $name) {
            delete_option($name);
        }

        // Usage cache transients (fluxfiles_usage_<md5>) and their timeouts.
        if (isset($wpdb) && is_object($wpdb)) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_fluxfiles_usage_') . '%',
                $wpdb->esc_like('_transient_timeout_fluxfiles_usage_') . '%'
            ));
            foreach (FluxFilesDbSchema::tableNames() as $table) {
                // Names are $wpdb->prefix + fixed literals (FluxFilesDbSchema), never user input.
                $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
        }
    };

    $base = FluxFilesStorage::base();

    if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
        $offset = 0;
        $batch = 100;
        do {
            $ids = (array) get_sites(['fields' => 'ids', 'number' => $batch, 'offset' => $offset]);
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                $cleanSite((int) $id, $base);
                restore_current_blog();
            }
            $offset += $batch;
        } while (count($ids) === $batch);

        // Network-level copies (the storage base is a site option).
        foreach ($options as $name) {
            delete_site_option($name);
        }
        // Drop the now-empty shared folders; non-empty ones are left alone.
        @rmdir($base . '/sites');
        @rmdir($base);
        return;
    }

    $cleanSite(null, $base);
    delete_site_option('fluxfiles_storage_base');
})();
