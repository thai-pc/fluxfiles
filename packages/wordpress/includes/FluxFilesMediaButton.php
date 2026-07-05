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
            . '<span class="dashicons dashicons-open-folder" style="vertical-align:text-bottom;margin-right:3px;"></span>'
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

        $config = wp_json_encode([
            'endpoint' => $endpoint,
            'token'    => $token,
            'disk'     => get_option('fluxfiles_disks', ['local'])[0] ?? 'local',
            'mode'     => 'picker',
            // onSelect already inserts each file in an array, so multi-select just
            // works when enabled. Off by default to keep single-insert behaviour.
            'multiple' => (bool) get_option('fluxfiles_picker_multiple', false),
            'locale'   => $locale,
        ]);

        // Same-origin REST URL + nonce for the default onTokenRefresh hook (re-mint
        // a JWT from the WP session when the embedded one expires → no reload).
        $tokenUrlJs = wp_json_encode(esc_url_raw(rest_url('fluxfiles/v1/api/fm/token')));
        $nonceJs    = wp_json_encode(wp_create_nonce('wp_rest'));
        // Attachment-bridge endpoint: register the pick as a WP attachment (served from
        // your bucket) so it becomes a real Media Library item, not just raw HTML.
        $attachUrlJs = wp_json_encode(esc_url_raw(rest_url('fluxfiles/v1/api/fm/attach')));

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
        <script>
        (function() {
            var btn = document.getElementById('fluxfiles-media-btn');
            var modal = document.getElementById('fluxfiles-modal');
            var overlay = modal.querySelector('.fluxfiles-modal-overlay');
            var closeBtn = modal.querySelector('.fluxfiles-modal-close');
            var body = document.getElementById('fluxfiles-modal-body');
            var config = <?php echo $config; ?>;
            var opened = false;

            function openModal() {
                modal.style.display = 'flex';
                if (!opened) {
                    config.container = '#fluxfiles-modal-body';
                    config.onTokenRefresh = function () {
                        return fetch(<?php echo $tokenUrlJs; ?>, {
                            credentials: 'same-origin',
                            headers: { 'X-WP-Nonce': <?php echo $nonceJs; ?>, 'Accept': 'application/json' }
                        })
                            .then(function (r) { return r.ok ? r.json() : null; })
                            .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })
                            .catch(function () { return null; });
                    };
                    config.onSelect = function(file) {
                        if (Array.isArray(file)) {
                            file.forEach(function(f) { insertFile(f); });
                        } else {
                            insertFile(file);
                        }
                        closeModal();
                    };
                    FluxFiles.open(config);
                    opened = true;
                }
            }

            function closeModal() {
                modal.style.display = 'none';
            }

            function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

            function emit(file, attachId) {
                var url = file.url || '';
                var name = file.basename || file.name || file.path || file.key || '';
                var alt = (file.meta && (file.meta.alt || file.meta.title)) || name;
                var isImg = /\.(jpe?g|png|gif|webp|avif|svg)$/i.test(name);
                var html;
                if (isImg) {
                    // A real attachment reference (wp-image-<id>) so WP treats it like a
                    // library image; falls back to a bare <img> if the bridge is off.
                    var cls = attachId ? ' class="wp-image-' + attachId + '"' : '';
                    html = '<img src="' + esc(url) + '" alt="' + esc(alt) + '"' + cls + ' />';
                } else {
                    html = '<a href="' + esc(url) + '">' + esc(name) + '</a>';
                }
                if (typeof window.send_to_editor === 'function') {
                    window.send_to_editor(html);
                }
            }

            function insertFile(file) {
                // Register the pick as a WP attachment (served from your bucket), then
                // insert it. If the bridge fails/returns nothing, still insert the URL.
                fetch(<?php echo $attachUrlJs; ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo $nonceJs; ?> },
                    body: JSON.stringify({
                        url: file.url, key: file.key || file.path, disk: file.disk || config.disk,
                        name: file.basename || file.name, mime: file.mime || file.type,
                        width: file.width || (file.meta && file.meta.width) || 0,
                        height: file.height || (file.meta && file.meta.height) || 0,
                        alt: (file.meta && (file.meta.alt || file.meta.title)) || '',
                        caption: (file.meta && file.meta.caption) || ''
                    })
                })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (j) { emit(file, j && j.data && j.data.id); })
                    .catch(function () { emit(file, 0); });
            }

            if (btn) btn.addEventListener('click', function(e) {
                e.preventDefault();
                openModal();
            });
            if (overlay) overlay.addEventListener('click', closeModal);
            if (closeBtn) closeBtn.addEventListener('click', closeModal);

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display !== 'none') {
                    closeModal();
                }
            });
        })();
        </script>
        <?php
    }
}
