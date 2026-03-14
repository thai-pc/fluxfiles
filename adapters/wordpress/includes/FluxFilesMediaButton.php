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

        $sdkUrl = plugins_url('../../fluxfiles.js', FLUXFILES_PLUGIN_FILE);
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

        $config = wp_json_encode([
            'endpoint' => $endpoint,
            'token'    => $token,
            'disk'     => get_option('fluxfiles_disks', ['local'])[0] ?? 'local',
            'mode'     => 'picker',
        ]);

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
                    config.onSelect = function(file) {
                        insertFile(file);
                        closeModal();
                    };
                    FluxFiles.open(config);
                    opened = true;
                }
            }

            function closeModal() {
                modal.style.display = 'none';
            }

            function insertFile(file) {
                var url = file.url || '';
                var name = file.basename || file.path || '';
                var html = '';

                if (/\.(jpe?g|png|gif|webp|svg)$/i.test(name)) {
                    html = '<img src="' + url + '" alt="' + name + '" />';
                } else {
                    html = '<a href="' + url + '">' + name + '</a>';
                }

                // Classic editor
                if (typeof window.send_to_editor === 'function') {
                    window.send_to_editor(html);
                }
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
