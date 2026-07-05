<?php

defined('ABSPATH') || exit;

/**
 * Native media-modal integration (experimental) — adds a "From FluxFiles" button to
 * WordPress's own media picker (`wp.media`), so Featured Image, the core Image block,
 * the Customizer and third-party pickers can pull from FluxFiles too. On select it
 * registers the file as a WP attachment (bridge) and hands it back to the frame's
 * selection so WP's normal flow continues.
 *
 * OPT-IN (Settings → FluxFiles → "Replace native picker", default off) because
 * extending wp.media across every context is inherently fragile; the FluxFiles button
 * + Gutenberg block work regardless of this toggle.
 */
class FluxFilesMediaIntegration
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('customize_controls_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (!apply_filters('fluxfiles_replace_picker', get_option('fluxfiles_replace_picker', '0') === '1')) {
            return;
        }
        if (!is_user_logged_in() || !current_user_can('upload_files')) {
            return;
        }

        // The native media JS must be present for us to extend it.
        wp_enqueue_media();
        wp_enqueue_script(
            'fluxfiles-sdk',
            plugins_url('assets/fluxfiles.js', FLUXFILES_PLUGIN_FILE),
            [],
            FLUXFILES_VERSION,
            true
        );
        wp_enqueue_script(
            'fluxfiles-media-integration',
            plugins_url('assets/media-integration.js', FLUXFILES_PLUGIN_FILE),
            ['media-views', 'fluxfiles-sdk'],
            FLUXFILES_VERSION,
            true
        );
        wp_enqueue_style('fluxfiles-admin', FLUXFILES_PLUGIN_URL . 'assets/admin.css', [], FLUXFILES_VERSION);

        $token = '';
        try {
            $token = FluxFilesPlugin::tokenForCurrentUser();
        } catch (\Throwable) {
            // no token → the integration self-disables (shows nothing)
        }

        wp_localize_script('fluxfiles-media-integration', 'FluxFilesMediaCfg', [
            'endpoint'  => FluxFilesPlugin::apiEndpoint(),
            'token'     => $token,
            'disk'      => get_option('fluxfiles_disks', ['local'])[0] ?? 'local',
            'locale'    => substr(get_locale(), 0, 2),
            'attachUrl' => esc_url_raw(rest_url('fluxfiles/v1/api/fm/attach')),
            'tokenUrl'  => esc_url_raw(rest_url('fluxfiles/v1/api/fm/token')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'label'     => __('From FluxFiles', 'fluxfiles'),
        ]);
    }
}
