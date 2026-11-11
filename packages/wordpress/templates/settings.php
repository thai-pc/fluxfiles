<?php
defined('ABSPATH') || exit;

/*
 * FluxFiles settings screen. One <form> (so one Save keeps working for every option, add-on
 * sections included); the tabs only show and hide its panels. Each tab lists settings
 * sections by id; a section registered by an add-on (FluxFiles Pro, via
 * `fluxfiles_admin_sections`) that no tab claims lands in the "Add-ons" tab.
 */

global $wp_settings_sections, $wp_settings_fields;

$fluxfiles_tabs = [
    'general' => ['label' => __('General', 'fluxfiles'), 'sections' => ['fluxfiles_general']],
    'access'  => ['label' => __('Access', 'fluxfiles'), 'sections' => ['fluxfiles_permissions']],
    'media'   => ['label' => __('Media Library', 'fluxfiles'), 'sections' => ['fluxfiles_media']],
    'storage' => ['label' => __('Storage', 'fluxfiles'), 'sections' => ['fluxfiles_storage']],
    'cloud'   => ['label' => __('Cloud (S3 / R2)', 'fluxfiles'), 'sections' => ['fluxfiles_s3', 'fluxfiles_r2']],
    'addons'  => ['label' => __('Add-ons', 'fluxfiles'), 'sections' => []],
    'advanced' => ['label' => __('Advanced', 'fluxfiles'), 'sections' => ['fluxfiles_danger']],
    'embed'   => ['label' => __('Embed', 'fluxfiles'), 'sections' => []],
];
$fluxfiles_all = isset($wp_settings_sections['fluxfiles']) ? (array) $wp_settings_sections['fluxfiles'] : [];
$fluxfiles_claimed = [];
foreach ($fluxfiles_tabs as $fluxfiles_tab) {
    $fluxfiles_claimed = array_merge($fluxfiles_claimed, $fluxfiles_tab['sections']);
}
foreach (array_keys($fluxfiles_all) as $fluxfiles_sid) {
    if (!in_array($fluxfiles_sid, $fluxfiles_claimed, true)) {
        $fluxfiles_tabs['addons']['sections'][] = $fluxfiles_sid;
    }
}
if (!$fluxfiles_tabs['addons']['sections']) {
    unset($fluxfiles_tabs['addons']);
}

$fluxfiles_render_section = static function (string $sid) use ($fluxfiles_all): void {
    if (!isset($fluxfiles_all[$sid])) {
        return;
    }
    $section = $fluxfiles_all[$sid];
    $danger = $sid === 'fluxfiles_danger' ? ' ff-card--danger' : '';
    echo '<section class="ff-card' . esc_attr($danger) . '" id="ff-section-' . esc_attr($sid) . '">';
    $test = in_array($sid, ['fluxfiles_s3', 'fluxfiles_r2'], true) ? substr($sid, 10) : '';
    echo '<header class="ff-card-head"><h2>' . esc_html(wp_strip_all_tags((string) $section['title'])) . '</h2>';
    if ($test !== '') {
        $configured = trim((string) get_option('fluxfiles_' . $test . '_bucket', '')) !== '';
        $enabled = in_array($test, (array) get_option('fluxfiles_disks', ['local']), true);
        $state = $configured ? ($enabled ? 'ok' : 'warn') : 'off';
        $text = $configured ? ($enabled ? __('Configured', 'fluxfiles') : __('Configured, not enabled', 'fluxfiles')) : __('Not configured', 'fluxfiles');
        echo '<span class="ff-badge" data-state="' . esc_attr($state) . '">' . esc_html($text) . '</span>';
    }
    echo '</header>';
    if (!empty($section['callback'])) {
        echo '<div class="ff-card-intro">';
        call_user_func($section['callback'], $section);
        echo '</div>';
    }
    echo '<table class="form-table" role="presentation">';
    do_settings_fields('fluxfiles', $sid);
    echo '</table>';
    if ($test !== '') {
        printf(
            '<div class="ff-test-row"><button type="button" class="button ff-test-disk" data-disk="%1$s">%2$s</button> <span class="ff-test-result" role="status" aria-live="polite"></span></div>',
            esc_attr($test),
            /* translators: %s: disk name, e.g. S3 */
            esc_html(sprintf(__('Test %s connection', 'fluxfiles'), strtoupper($test)))
        );
    }
    echo '</section>';
};
?>
<div class="wrap ff-settings">
    <header class="ff-hero">
        <div class="ff-hero-title">
            <span class="ff-logo" aria-hidden="true">
                <svg viewBox="0 0 32 32" width="40" height="40" fill="none" focusable="false"><rect width="32" height="32" rx="7" fill="#8957E5"/><path d="M8 10a2 2 0 012-2h4l2 2h6a2 2 0 012 2v10a2 2 0 01-2 2H10a2 2 0 01-2-2V10z" fill="#fff" fill-opacity="0.92"/><path d="M12 17l2.5-3 2 2 3.5-4" stroke="#8957E5" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <div>
                <h1><?php echo esc_html__('FluxFiles', 'fluxfiles'); ?></h1>
                <p class="ff-hero-sub">
                    <?php
                    /* translators: %s: plugin version */
                    echo esc_html(sprintf(__('File manager settings · version %s', 'fluxfiles'), FLUXFILES_VERSION));
                    ?>
                </p>
            </div>
        </div>
        <ul class="ff-status" aria-label="<?php echo esc_attr__('Status', 'fluxfiles'); ?>">
            <?php foreach (FluxFilesAdmin::statusSummary() as $fluxfiles_st) : ?>
                <li class="ff-status-item" data-state="<?php echo esc_attr($fluxfiles_st['state']); ?>"<?php echo !empty($fluxfiles_st['id']) ? ' id="' . esc_attr($fluxfiles_st['id']) . '"' : ''; ?>>
                    <span class="ff-dot" aria-hidden="true"></span>
                    <span class="ff-status-label"><?php echo esc_html($fluxfiles_st['label']); ?></span>
                    <span class="ff-status-value"><?php echo esc_html($fluxfiles_st['value']); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </header>

    <?php // WP moves admin notices (Settings saved, errors, the nginx warning) to just after this marker. ?>
    <hr class="wp-header-end" />

    <nav class="ff-tabs" role="tablist" aria-label="<?php echo esc_attr__('Settings sections', 'fluxfiles'); ?>">
        <?php $fluxfiles_first = true; foreach ($fluxfiles_tabs as $fluxfiles_key => $fluxfiles_tab) : ?>
            <button type="button" role="tab" class="ff-tab" id="ff-tab-<?php echo esc_attr($fluxfiles_key); ?>"
                aria-controls="ff-panel-<?php echo esc_attr($fluxfiles_key); ?>"
                aria-selected="<?php echo $fluxfiles_first ? 'true' : 'false'; ?>"
                tabindex="<?php echo $fluxfiles_first ? '0' : '-1'; ?>"
                data-tab="<?php echo esc_attr($fluxfiles_key); ?>"><?php echo esc_html($fluxfiles_tab['label']); ?></button>
        <?php $fluxfiles_first = false; endforeach; ?>
    </nav>

    <form method="post" action="options.php" class="ff-form" id="ff-settings-form">
        <?php settings_fields('fluxfiles'); ?>

        <?php $fluxfiles_first = true; foreach ($fluxfiles_tabs as $fluxfiles_key => $fluxfiles_tab) : ?>
            <div class="ff-panel" role="tabpanel" id="ff-panel-<?php echo esc_attr($fluxfiles_key); ?>"
                aria-labelledby="ff-tab-<?php echo esc_attr($fluxfiles_key); ?>"<?php echo $fluxfiles_first ? '' : ' hidden'; ?>>
                <?php
                foreach ($fluxfiles_tab['sections'] as $fluxfiles_sid) {
                    $fluxfiles_render_section($fluxfiles_sid);
                }
                if ($fluxfiles_key === 'embed') :
                    ?>
                    <section class="ff-card">
                        <header class="ff-card-head"><h2><?php echo esc_html__('Shortcode', 'fluxfiles'); ?></h2></header>
                        <div class="ff-card-intro"><p><?php echo wp_kses(__('Put the <code>[fluxfiles]</code> shortcode in any post or page (one per page). Visitors must be logged in.', 'fluxfiles'), ['code' => []]); ?></p></div>
                        <div class="ff-snippet"><code>[fluxfiles]</code><button type="button" class="button button-small ff-copy" data-copy="[fluxfiles]"><?php echo esc_html__('Copy', 'fluxfiles'); ?></button></div>
                        <div class="ff-snippet"><code>[fluxfiles disk="s3" mode="browser" height="500px"]</code><button type="button" class="button button-small ff-copy" data-copy='[fluxfiles disk="s3" mode="browser" height="500px"]'><?php echo esc_html__('Copy', 'fluxfiles'); ?></button></div>
                        <p class="description"><?php echo esc_html__('In the block editor, use the “FluxFiles Image” block or the “From FluxFiles” button in the media picker instead.', 'fluxfiles'); ?></p>
                    </section>
                    <section class="ff-card">
                        <header class="ff-card-head"><h2><?php echo esc_html__('In a theme template (PHP)', 'fluxfiles'); ?></h2></header>
                        <div class="ff-card-intro"><p><?php echo esc_html__('Mint a token for the logged-in user and open the file manager in a container:', 'fluxfiles'); ?></p></div>
<?php
$fluxfiles_php = <<<'PHPSNIPPET'
<?php
// In a theme template, before get_footer(). Logged-in users only: the token is theirs.
if (is_user_logged_in()) {
    wp_enqueue_script('fluxfiles-sdk', plugins_url('assets/fluxfiles.js', FLUXFILES_PLUGIN_FILE), [], FLUXFILES_VERSION, true);
    wp_add_inline_script('fluxfiles-sdk', 'FluxFiles.open(' . wp_json_encode([
        'endpoint'  => FluxFilesPlugin::apiEndpoint(),
        'token'     => FluxFilesPlugin::tokenForCurrentUser(),
        'disk'      => 'local',
        'mode'      => 'picker',
        'container' => '#my-file-manager',
    ]) . ');');
    echo '<div id="my-file-manager" style="height:600px"></div>';
}
PHPSNIPPET;
?>
                        <div class="ff-code"><button type="button" class="button button-small ff-copy" data-copy-target="ff-php-snippet"><?php echo esc_html__('Copy', 'fluxfiles'); ?></button><pre id="ff-php-snippet"><code><?php echo esc_html($fluxfiles_php); ?></code></pre></div>
                    </section>
                <?php endif; ?>
            </div>
        <?php $fluxfiles_first = false; endforeach; ?>

        <div class="ff-savebar">
            <span class="ff-dirty" role="status" aria-live="polite"></span>
            <?php submit_button(__('Save changes', 'fluxfiles'), 'primary', 'submit', false); ?>
        </div>
    </form>
</div>
