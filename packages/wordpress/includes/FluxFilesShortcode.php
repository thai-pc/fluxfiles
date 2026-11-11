<?php

defined('ABSPATH') || exit;

/**
 * [fluxfiles] shortcode for embedding the file manager in posts/pages.
 *
 * Usage:
 *   [fluxfiles]
 *   [fluxfiles disk="s3" mode="browser" height="500px"]
 */
class FluxFilesShortcode
{
    public function __construct()
    {
        add_shortcode('fluxfiles', [$this, 'render']);
    }

    /**
     * @param array|string $atts
     */
    public function render($atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p class="fluxfiles-login-required">' . esc_html__('Please log in to use the file manager.', 'fluxfiles') . '</p>';
        }

        $atts = shortcode_atts([
            'disk'     => 'local',
            'mode'     => 'picker',
            'width'    => '100%',
            'height'   => '600px',
            'multiple' => '0',
        ], $atts, 'fluxfiles');

        try {
            $token = FluxFilesPlugin::tokenForCurrentUser();
        } catch (\Throwable $e) {
            return '<p class="fluxfiles-error">' . esc_html(self::tokenFailureMessage()) . '</p>';
        }

        $endpoint    = FluxFilesPlugin::apiEndpoint();
        $containerId = 'fluxfiles-' . wp_unique_id();
        $basePath    = FluxFilesPlugin::basePath();
        $sdkUrl      = plugins_url('assets/fluxfiles.js', FLUXFILES_PLUGIN_FILE);

        // Enqueue the SDK
        wp_enqueue_script('fluxfiles-sdk', $sdkUrl, [], FLUXFILES_VERSION, true);

        $disk   = esc_attr($atts['disk']);
        $mode   = esc_attr($atts['mode']);
        $width  = esc_attr($atts['width']);
        $height = esc_attr($atts['height']);

        $locale = substr(get_locale(), 0, 2);

        // The SDK drives ONE iframe per page (a module-level singleton: open() closes
        // the previous one), so a second [fluxfiles] would silently replace the first.
        // The first container to load wins; later ones show this notice instead.
        $dupJson = wp_json_encode(__('FluxFiles: only one file manager can be embedded per page, so this one was not loaded.', 'fluxfiles'));

        // Offer every enabled disk; start on the one the shortcode names when it is enabled.
        $disks = FluxFilesPlugin::enabledDisks();
        $startDisk = in_array((string) $atts['disk'], $disks, true) ? (string) $atts['disk'] : $disks[0];
        $configJson = wp_json_encode([
            'endpoint'  => $endpoint,
            'token'     => $token,
            'disk'      => $startDisk,
            'disks'     => $disks,
            'mode'      => $atts['mode'],
            'multiple'  => filter_var($atts['multiple'], FILTER_VALIDATE_BOOLEAN),
            'container' => "#{$containerId}",
            'locale'    => $locale,
        ]);

        // Same-origin REST URL + nonce the default onTokenRefresh hook hits to
        // re-mint a JWT from the WP session after the embedded one expires, so
        // the iframe's "Try again" recovers without a full page reload.
        $tokenUrl   = esc_url_raw(rest_url('fluxfiles/v1/api/fm/token'));
        $tokenUrlJs = wp_json_encode($tokenUrl);
        $nonceJs    = wp_json_encode(wp_create_nonce('wp_rest'));

        // The loader is attached to the SDK script (enqueued above, in the footer) and
        // runs right after it, so the page itself carries only the container div.
        // A render whose output is discarded (excerpt, SEO scan) still queues this script, so
        // it no-ops unless its container is really in the page, else it would tear down the real embed.
        $js = "document.addEventListener('DOMContentLoaded', function () {\n"
            . '    if (!document.querySelector(' . wp_json_encode("#{$containerId}") . ") || typeof FluxFiles === 'undefined') { return; }\n"
            . '    var box = document.querySelector(' . wp_json_encode("#{$containerId}") . ");\n"
            . "    if (window.fluxfilesShortcodeOpen) {\n"
            . '        box.className = \'fluxfiles-error\'; box.style.height = \'auto\'; box.textContent = ' . $dupJson . ";\n"
            . "        return;\n"
            . "    }\n"
            . "    window.fluxfilesShortcodeOpen = true;\n"
            . '    FluxFiles.open(Object.assign(' . $configJson . ", {\n"
            . "        onTokenRefresh: function () {\n"
            . '            return fetch(' . $tokenUrlJs . ", {\n"
            . "                credentials: 'same-origin',\n"
            . "                headers: { 'X-WP-Nonce': " . $nonceJs . ", 'Accept': 'application/json' }\n"
            . "            })\n"
            . "                .then(function (r) { return r.ok ? r.json() : null; })\n"
            . "                .then(function (j) { return (j && j.data && j.data.token) ? j.data.token : null; })\n"
            . "                .catch(function () { return null; });\n"
            . "        }\n"
            . "    }));\n"
            . '});';
        wp_add_inline_script('fluxfiles-sdk', $js, 'after');

        return sprintf(
            '<div id="%s" style="width:%s;height:%s"></div>',
            esc_attr($containerId),
            $width,
            $height
        );
    }

    /**
     * Why no token could be minted, worded for who is looking: a visitor who may
     * not use the file manager, an admin who has to fix the setup, or an editor who
     * has to ask one.
     */
    private static function tokenFailureMessage(): string
    {
        $cap = class_exists('FluxFilesApi') ? FluxFilesApi::requiredCapability() : 'upload_files';
        if (!current_user_can($cap)) {
            return __('FluxFiles: your account is not allowed to use the file manager.', 'fluxfiles');
        }
        if (current_user_can('manage_options')) {
            return __('FluxFiles: unable to generate a token. Check the secret key in Settings → FluxFiles.', 'fluxfiles');
        }
        return __('FluxFiles: the file manager is not available right now. Please contact the site administrator.', 'fluxfiles');
    }
}
