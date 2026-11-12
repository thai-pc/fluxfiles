<?php
/**
 * Plugin Name: FluxFiles live-rig token overrides + FluxFiles Pro stand-in
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

// Since the WP.org split the free plugin verifies no licence itself: core asks the
// `fluxfiles_license` filter, which FluxFiles Pro answers. This stands in for Pro so all
// 13 modules can be driven through the WP proxy (LicenseManager: copied from the staged
// core by stage.sh, since the free zip doesn't ship it).
add_filter('fluxfiles_license', static function ($l) {
    static $lm = null;
    if (!class_exists('\FluxFiles\LicenseManager', false)) {
        require_once '/wpfixtures/LicenseManager.php';
    }
    return $lm ??= new \FluxFiles\LicenseManager((string) get_option('fluxfiles_license_key', ''));
});

// Edition presets also live in FluxFiles Pro now (`fluxfiles_edition_presets`); this is
// the enterprise row the free plugin used to ship (pre-1a7fa0a), for the 13-module drive.
add_filter('fluxfiles_edition_presets', static function ($p) {
    $p = is_array($p) ? $p : [];
    $p['enterprise'] = ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true, 'allow_versioning' => true,
        'allow_webhooks' => true, 'allow_ai_vision' => true, 'allow_ocr' => true, 'allow_virus_scan' => true, 'allow_c2pa' => true,
        'allow_backup' => true, 'allow_audit_export' => true, 'allow_dlp_scan' => true, 'allow_legal_hold' => true];
    return $p;
});

// …and module discovery: Pro registers its modules/ dir through `fluxfiles_module_paths`.
// The rig installs all 13 into the plugin's vendor/fluxfiles/<id>/ (install-modules.php).
add_filter('fluxfiles_module_paths', static function ($p) {
    $p = is_array($p) ? $p : [];
    $p[] = WP_PLUGIN_DIR . '/fluxfiles/vendor/fluxfiles/';
    return $p;
});
