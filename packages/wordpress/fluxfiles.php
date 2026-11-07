<?php
/**
 * Plugin Name: FluxFiles
 * Plugin URI:  https://github.com/thai-pc/fluxfiles
 * Description: Multi-storage file manager with Local/S3/R2 support (SFTP through BYOB tokens), image optimization, and full-text search.
 * Version:     1.1.0
 * Author:      thai-pc
 * Author URI:  https://github.com/thai-pc
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: fluxfiles
 * Requires PHP: 8.1.0
 * Requires at least: 6.5
 *
 * @package FluxFiles
 */

defined('ABSPATH') || exit;

define('FLUXFILES_VERSION', '1.1.0');
// Release of the core bundled in this build; build-wordpress.sh stamps it from the latest core-v* tag.
define('FLUXFILES_CORE_VERSION', 'unknown');
// Extension API level for add-on plugins (FluxFiles Pro): bumped only when a hook breaks.
// See docs/HOOKS.md.
define('FLUXFILES_EXT_API', 1);
define('FLUXFILES_PLUGIN_FILE', __FILE__);
define('FLUXFILES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FLUXFILES_PLUGIN_URL', plugin_dir_url(__FILE__));
// Keep in step with `Requires PHP` above (test-wp-smoke guards it).
define('FLUXFILES_MIN_PHP', '8.1.0');

// The bundled vendor/ refuses to load below the floor with a fatal (Composer's
// platform check), which would take the whole site down. WordPress holds back an
// install/update on an older PHP, but a plugin already active when the server's PHP
// is downgraded — or copied in by hand — still gets here: stop with a notice instead.
if (version_compare(PHP_VERSION, FLUXFILES_MIN_PHP, '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>FluxFiles:</strong> ';
        echo esc_html(sprintf(
            /* translators: 1: required PHP version, 2: the PHP version this server runs. */
            __('requires PHP %1$s or newer. This server runs PHP %2$s, so FluxFiles is not loaded.', 'fluxfiles'),
            FLUXFILES_MIN_PHP,
            PHP_VERSION
        ));
        echo '</p></div>';
    });
    return;
}

// Only the plugin's own bundled dependencies (vendor/) are loaded, never another
// plugin's or wp-content's autoloader.
$fluxfiles_autoloader = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ . '/vendor/autoload.php' : null;
if ($fluxfiles_autoloader === null) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>FluxFiles:</strong> ';
        echo esc_html__('The bundled dependencies (vendor/) are missing. Install the release ZIP from WordPress.org or GitHub, which includes them.', 'fluxfiles');
        echo ' ';
        echo esc_html__('Maintainers: build the plugin with scripts/build-wordpress.sh, or run composer install --no-dev in this plugin folder.', 'fluxfiles');
        echo '</p></div>';
    });
    return;
}
require_once $fluxfiles_autoloader;

// Load plugin classes
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesDbSchema.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesStorage.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesPlugin.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesAdmin.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/WpDbMetadataHandler.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/WpRateLimiterStorage.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/WpHttpTransport.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesMediaEndpoint.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesApi.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesShortcode.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesMediaButton.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesAttachments.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesBlock.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesMediaIntegration.php';

// Register WP-CLI commands (only when running under wp-cli)
if (defined('WP_CLI') && WP_CLI) {
    require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesCli.php';
    WP_CLI::add_command('fluxfiles', 'FluxFilesCli');
}

// Boot
FluxFilesPlugin::instance();
