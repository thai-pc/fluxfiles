<?php
/**
 * Plugin Name: FluxFiles
 * Plugin URI:  https://github.com/fluxfiles/fluxfiles
 * Description: Multi-storage file manager with Local/S3/R2 support, image optimization, and full-text search.
 * Version:     1.0.0
 * Author:      FluxFiles
 * License:     MIT
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package FluxFiles
 */

defined('ABSPATH') || exit;

define('FLUXFILES_VERSION', '1.0.0');
define('FLUXFILES_PLUGIN_FILE', __FILE__);
define('FLUXFILES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FLUXFILES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require the FluxFiles core autoloader
$autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>FluxFiles:</strong> Composer dependencies not installed. ';
        echo 'Run <code>composer install</code> in the FluxFiles root directory.';
        echo '</p></div>';
    });
    return;
}
require_once $autoloader;

// Load plugin classes
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesPlugin.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesAdmin.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesApi.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesShortcode.php';
require_once FLUXFILES_PLUGIN_DIR . 'includes/FluxFilesMediaButton.php';

// Boot
FluxFilesPlugin::instance();
