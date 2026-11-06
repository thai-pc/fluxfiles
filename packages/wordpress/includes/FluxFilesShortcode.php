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
            return '<p class="fluxfiles-login-required">Please log in to use the file manager.</p>';
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
            return '<p class="fluxfiles-error">FluxFiles: Unable to generate token.</p>';
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

        $configJson = wp_json_encode([
            'endpoint'  => $endpoint,
            'token'     => $token,
            'disk'      => $atts['disk'],
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
}
