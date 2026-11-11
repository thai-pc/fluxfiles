<?php

defined('ABSPATH') || exit;

/**
 * Adds a "FluxFiles" button to the WordPress post editor media toolbar,
 * allowing users to pick files from FluxFiles and insert them into content.
 */
class FluxFilesMediaButton
{
    public function __construct()
    {
        add_action('media_buttons', [$this, 'addButton']);
        add_action('admin_footer', [$this, 'renderModal']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    /**
     * Only load on post editor screens.
     */
    private function isEditorScreen(): bool
    {
        $screen = get_current_screen();
        return $screen && ($screen->base === 'post' || $screen->base === 'page');
    }

    public function addButton(): void
    {
        if (!$this->isEditorScreen()) {
            return;
        }

        printf(
            '<button type="button" class="button fluxfiles-media-btn" id="fluxfiles-media-btn">'
            // The FluxFiles logo's square folder with an "F", as a monochrome line icon
            // (the same glyph as the editor adapters' toolbar buttons), sized like the
            // 20px dashicon it replaces.
            . '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" style="vertical-align:text-bottom;margin:0 4px 1px 0;">'
            . '<path d="M3 5a2 2 0 012-2h5l2 2h7a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M10 16.5v-7h4.5M10 13h3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '%s</button>',
            esc_html__('FluxFiles', 'fluxfiles')
        );
    }

    public function enqueueScripts(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $sdkUrl = plugins_url('assets/fluxfiles.js', FLUXFILES_PLUGIN_FILE);
        wp_enqueue_script('fluxfiles-sdk', $sdkUrl, [], FLUXFILES_VERSION, true);
        // Which picked URL may be saved into a post (shared with the block).
        wp_enqueue_script(
            'fluxfiles-picker-url',
            plugins_url('assets/picker-url.js', FLUXFILES_PLUGIN_FILE),
            ['wp-i18n'],
            FLUXFILES_VERSION,
            true
        );
        // Picker modal logic; renderModal() hands it the per-user config inline.
        wp_enqueue_script(
            'fluxfiles-media-button',
            plugins_url('assets/media-button.js', FLUXFILES_PLUGIN_FILE),
            ['fluxfiles-sdk', 'fluxfiles-picker-url'],
            FLUXFILES_VERSION,
            true
        );

        foreach (['fluxfiles-picker-url', 'fluxfiles-media-button'] as $handle) {
            wp_set_script_translations($handle, 'fluxfiles');
        }

        wp_enqueue_style(
            'fluxfiles-admin',
            FLUXFILES_PLUGIN_URL . 'assets/admin.css',
            [],
            FLUXFILES_VERSION
        );
    }

    public function renderModal(): void
    {
        if (!$this->isEditorScreen()) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        try {
            $token = FluxFilesPlugin::tokenForCurrentUser();
        } catch (\Throwable) {
            return;
        }

        $endpoint = FluxFilesPlugin::apiEndpoint();

        $locale = substr(get_locale(), 0, 2);

        $config = [
            'endpoint' => $endpoint,
            'token'    => $token,
            'disk'     => FluxFilesPlugin::enabledDisks()[0],
            'disks'    => FluxFilesPlugin::enabledDisks(),
            'mode'     => 'picker',
            // onSelect already inserts each file in an array, so multi-select just
            // works when enabled. Off by default to keep single-insert behaviour.
            'multiple' => (bool) get_option('fluxfiles_picker_multiple', false),
            'locale'   => $locale,
        ];

        // assets/media-button.js reads this. tokenUrl + nonce drive the default
        // onTokenRefresh hook (re-mint a JWT from the WP session when the embedded one
        // expires, no reload). attachUrl is the attachment-bridge endpoint: the pick is
        // registered as a WP attachment (served from your bucket) so it becomes a real
        // Media Library item, not just raw HTML.
        wp_add_inline_script(
            'fluxfiles-media-button',
            'window.fluxfilesMediaButton = ' . wp_json_encode([
                'config'    => $config,
                'tokenUrl'  => esc_url_raw(rest_url('fluxfiles/v1/api/fm/token')),
                'nonce'     => wp_create_nonce('wp_rest'),
                'attachUrl' => esc_url_raw(rest_url('fluxfiles/v1/api/fm/attach')),
            ]) . ';',
            'before'
        );

        ?>
        <div id="fluxfiles-modal" class="fluxfiles-modal" style="display:none;">
            <div class="fluxfiles-modal-overlay"></div>
            <div class="fluxfiles-modal-content">
                <div class="fluxfiles-modal-header">
                    <h2>FluxFiles — Select File</h2>
                    <button type="button" class="fluxfiles-modal-close">&times;</button>
                </div>
                <div id="fluxfiles-modal-body" class="fluxfiles-modal-body"></div>
            </div>
        </div>
        <?php
    }
}
