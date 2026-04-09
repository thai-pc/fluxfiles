<?php
/**
 * Plugin Name: FluxFiles
 * Plugin URI:  https://github.com/thai-pc/fluxfiles
 * Description: Multi-storage file manager with Local/S3/R2 support, image optimization, and full-text search.
 * Version:     1.22.0
 * Author:      thai-pc
 * Author URI:  https://github.com/thai-pc
 * License:     MIT
 * Requires PHP: 8.1
 * Requires at least: 6.0
 *
 * @package FluxFiles
 */

defined('ABSPATH') || exit;

define('FLUXFILES_VERSION', '1.22.0');
define('FLUXFILES_PLUGIN_FILE', __FILE__);
define('FLUXFILES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FLUXFILES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require the FluxFiles core autoloader (plugin-local vendor, monorepo packages/core, or legacy repo root)
$fluxfilesAutoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 1) . '/core/vendor/autoload.php',
    dirname(__DIR__, 2) . '/packages/core/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];
$autoloader = null;
foreach ($fluxfilesAutoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoloader = $candidate;
        break;
    }
}
if ($autoloader === null) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>FluxFiles:</strong> Missing <code>vendor/</code>. Install a release ZIP that includes dependencies, ';
        echo 'or run <code>composer install --no-dev</code> in this plugin folder (maintainers), ';
        echo 'or use a monorepo checkout with <code>composer install -d packages/core</code> next to <code>packages/wordpress</code>.';
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
