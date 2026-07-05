<?php

defined('ABSPATH') || exit;

/**
 * Gutenberg block `fluxfiles/image` — pick an image from FluxFiles inside the block
 * editor. On select it registers a WP attachment (via the attachment bridge) and
 * inserts an <img> served from your bucket. Registered inline (no build step) using
 * wp.blocks + wp.element, matching the plugin's no-bundler convention.
 */
class FluxFilesBlock
{
    public function __construct()
    {
        add_action('init', [$this, 'register']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
    }

    public function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        // Dynamic render is not needed — the block saves static HTML — but registering
        // server-side keeps the block available even if editor assets fail to load.
        register_block_type('fluxfiles/image', [
            'api_version' => 2,
            'editor_script' => 'fluxfiles-block',
        ]);
    }

    public function enqueueEditorAssets(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        wp_enqueue_script(
            'fluxfiles-sdk',
            plugins_url('assets/fluxfiles.js', FLUXFILES_PLUGIN_FILE),
            [],
            FLUXFILES_VERSION,
            true
        );
        wp_enqueue_script(
            'fluxfiles-block',
            plugins_url('assets/block.js', FLUXFILES_PLUGIN_FILE),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'fluxfiles-sdk'],
            FLUXFILES_VERSION,
            true
        );
        wp_enqueue_style('fluxfiles-admin', FLUXFILES_PLUGIN_URL . 'assets/admin.css', [], FLUXFILES_VERSION);

        $token = '';
        try {
            $token = FluxFilesPlugin::tokenForCurrentUser();
        } catch (\Throwable) {
            // No token → the block shows a config notice instead of the picker.
        }

        wp_localize_script('fluxfiles-block', 'FluxFilesBlockCfg', [
            'endpoint'  => FluxFilesPlugin::apiEndpoint(),
            'token'     => $token,
            'disk'      => get_option('fluxfiles_disks', ['local'])[0] ?? 'local',
            'locale'    => substr(get_locale(), 0, 2),
            'attachUrl' => esc_url_raw(rest_url('fluxfiles/v1/api/fm/attach')),
            'tokenUrl'  => esc_url_raw(rest_url('fluxfiles/v1/api/fm/token')),
            'nonce'     => wp_create_nonce('wp_rest'),
        ]);
    }
}
