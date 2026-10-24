<?php
/**
 * Plugin Name: FluxFiles live-rig token overrides
 * The plugin has NO settings UI for paid-module claims: the supported way to turn
 * them on is the `fluxfiles_token_overrides` filter (docs/guides/ACTIVATE.md). This
 * mu-plugin is exactly that filter, fed from a JSON option so a test can retune it
 * with wp-cli (`fluxfiles_e2e_overrides`). Rate limits are raised the same way the
 * wp-env rig does it — the suite makes far more than 10 writes/min.
 */
add_filter('fluxfiles_token_overrides', static function ($o) {
    $o = (array) $o + ['rate_read' => 100000, 'rate_write' => 100000];
    $extra = json_decode((string) get_option('fluxfiles_e2e_overrides', '{}'), true);
    return is_array($extra) ? array_merge($o, $extra) : $o;
});
