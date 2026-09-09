<?php

defined('ABSPATH') || exit;

/**
 * Admin settings page for FluxFiles.
 */
class FluxFilesAdmin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'renderLicenseExpiryNotice']);
        add_action('wp_ajax_fluxfiles_dismiss_license_notice', [$this, 'handleDismissLicenseNotice']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            'FluxFiles Settings',
            'FluxFiles',
            'manage_options',
            'fluxfiles',
            [$this, 'renderSettingsPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_fluxfiles') {
            return;
        }

        wp_enqueue_style(
            'fluxfiles-admin',
            FLUXFILES_PLUGIN_URL . 'assets/admin.css',
            [],
            FLUXFILES_VERSION
        );
    }

    public function registerSettings(): void
    {
        // General section
        add_settings_section(
            'fluxfiles_general',
            'General Settings',
            null,
            'fluxfiles'
        );

        $this->addField('fluxfiles_secret', 'JWT Secret', 'fluxfiles_general', 'password');
        $this->addField('fluxfiles_max_upload', 'Max Upload Size (MB)', 'fluxfiles_general', 'number');
        $this->addField('fluxfiles_max_storage', 'Max Storage Quota (MB, 0 = unlimited)', 'fluxfiles_general', 'number');
        $this->addField('fluxfiles_ttl', 'Token TTL (seconds)', 'fluxfiles_general', 'number');

        register_setting('fluxfiles', 'fluxfiles_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('fluxfiles', 'fluxfiles_max_upload', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
        ]);
        register_setting('fluxfiles', 'fluxfiles_max_storage', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
        ]);
        register_setting('fluxfiles', 'fluxfiles_ttl', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        // Storage backend section
        add_settings_section(
            'fluxfiles_storage',
            'Storage Backend',
            function () {
                echo '<p>Where file metadata (titles, tags, folder index, trash, audit log) is stored.</p>';
            },
            'fluxfiles'
        );

        register_setting('fluxfiles', 'fluxfiles_storage_backend', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v) => $v === 'db' ? 'db' : 'json',
            'default' => 'json',
        ]);
        add_settings_field(
            'fluxfiles_storage_backend',
            'Storage backend',
            [$this, 'renderStorageBackendField'],
            'fluxfiles',
            'fluxfiles_storage'
        );

        // ── Licence ─────────────────────────────────────────────────────────
        // Without this a WordPress customer has no way to activate what they bought:
        // the core reads the key from the environment, which shared hosting does not
        // give them. The section shows the verified status back, because a key field
        // that swallows input and says nothing is indistinguishable from a broken one.
        add_settings_section(
            'fluxfiles_license',
            'Licence',
            [$this, 'renderLicenseStatus'],
            'fluxfiles'
        );
        register_setting('fluxfiles', 'fluxfiles_license_key', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeLicenseKey'],
        ]);
        add_settings_field(
            'fluxfiles_license_key',
            'Licence key',
            [$this, 'renderLicenseField'],
            'fluxfiles',
            'fluxfiles_license'
        );

        // Permissions section
        add_settings_section(
            'fluxfiles_permissions',
            'Default Permissions',
            function () {
                echo '<p>Default permissions applied when generating tokens for WordPress users.</p>';
            },
            'fluxfiles'
        );

        register_setting('fluxfiles', 'fluxfiles_default_perms', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizePerms'],
        ]);

        add_settings_field(
            'fluxfiles_default_perms',
            'Permissions',
            [$this, 'renderPermsField'],
            'fluxfiles',
            'fluxfiles_permissions'
        );

        register_setting('fluxfiles', 'fluxfiles_disks', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeDisks'],
        ]);

        add_settings_field(
            'fluxfiles_disks',
            'Enabled Disks',
            [$this, 'renderDisksField'],
            'fluxfiles',
            'fluxfiles_permissions'
        );

        register_setting('fluxfiles', 'fluxfiles_offload', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0',
            'default' => '1',
        ]);
        add_settings_field(
            'fluxfiles_offload',
            'Media offload (3-in-1)',
            [$this, 'renderOffloadField'],
            'fluxfiles',
            'fluxfiles_permissions'
        );

        register_setting('fluxfiles', 'fluxfiles_replace_picker', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0',
            'default' => '0',
        ]);
        add_settings_field(
            'fluxfiles_replace_picker',
            'Native picker (experimental)',
            [$this, 'renderReplacePickerField'],
            'fluxfiles',
            'fluxfiles_permissions'
        );

        register_setting('fluxfiles', 'fluxfiles_delete_storage', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0',
            'default' => '0',
        ]);
        add_settings_field(
            'fluxfiles_delete_storage',
            'Delete from storage',
            [$this, 'renderDeleteStorageField'],
            'fluxfiles',
            'fluxfiles_permissions'
        );

        // S3 section
        add_settings_section(
            'fluxfiles_s3',
            'Amazon S3',
            function () {
                echo '<p>Configure S3 storage. Leave bucket empty to disable.</p>';
            },
            'fluxfiles'
        );

        $this->addField('fluxfiles_s3_bucket', 'Bucket', 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_region', 'Region', 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_key', 'Access Key ID', 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_secret', 'Secret Access Key', 'fluxfiles_s3', 'password');
        $this->addField('fluxfiles_s3_endpoint', 'Endpoint (MinIO/Spaces — empty for AWS)', 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_visibility', 'Visibility (private | public)', 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_public_url', 'Public URL (CDN/custom domain)', 'fluxfiles_s3');

        foreach ([
            'fluxfiles_s3_bucket', 'fluxfiles_s3_region', 'fluxfiles_s3_key', 'fluxfiles_s3_secret',
            'fluxfiles_s3_endpoint', 'fluxfiles_s3_visibility', 'fluxfiles_s3_public_url',
        ] as $opt) {
            register_setting('fluxfiles', $opt, [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }

        // R2 section
        add_settings_section(
            'fluxfiles_r2',
            'Cloudflare R2',
            function () {
                echo '<p>Configure R2 storage. Leave bucket empty to disable.</p>';
            },
            'fluxfiles'
        );

        $this->addField('fluxfiles_r2_bucket', 'Bucket', 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_account_id', 'Account ID', 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_key', 'Access Key ID', 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_secret', 'Secret Access Key', 'fluxfiles_r2', 'password');
        $this->addField('fluxfiles_r2_visibility', 'Visibility (private | public)', 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_public_url', 'Public URL (r2.dev / custom domain)', 'fluxfiles_r2');

        foreach ([
            'fluxfiles_r2_bucket', 'fluxfiles_r2_account_id', 'fluxfiles_r2_key', 'fluxfiles_r2_secret',
            'fluxfiles_r2_visibility', 'fluxfiles_r2_public_url',
        ] as $opt) {
            register_setting('fluxfiles', $opt, [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }
    }

    public function renderSettingsPage(): void
    {
        require FLUXFILES_PLUGIN_DIR . 'templates/settings.php';
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    /**
     * A licence key is a long signed token, so it is stored verbatim — no
     * sanitize_text_field, which would strip characters and turn a valid key into a
     * silently invalid one. Only surrounding whitespace (from copy-paste) is removed.
     */
    public function sanitizeLicenseKey($value): string
    {
        return trim((string) $value);
    }

    /** Verified status, so pasting a key gives an answer instead of silence. */
    public function renderLicenseStatus(): void
    {
        if (!class_exists('\\FluxFiles\\LicenseManager')) {
            echo '<p>The FluxFiles core is not loaded, so the licence cannot be checked.</p>';
            return;
        }
        $key = FluxFilesPlugin::licenseKey();
        if ($key === '') {
            echo '<p>Running the free core. Paid modules stay inactive until a licence key is entered.</p>';
            return;
        }

        $lm = FluxFilesPlugin::license();
        $info = $lm->info();
        $status = (string) ($info['status'] ?? 'free');
        $edition = (string) ($info['edition'] ?? 'free');

        // 'free' with a key present means the key did not verify — say so plainly
        // rather than showing a reassuring edition name the customer does not have.
        if ($edition === 'free' || $status === 'free') {
            echo '<div class="notice notice-error inline"><p><strong>This licence key was not accepted.</strong> '
               . 'Check it was pasted whole, with no line breaks.</p></div>';
            return;
        }

        $modules = (array) ($info['modules'] ?? []);
        $expires = $info['expires'] ?? null;
        $class = in_array($status, ['expired', 'grace'], true) ? 'notice-warning' : 'notice-success';

        printf(
            '<div class="notice %s inline"><p><strong>%s</strong> — %s.%s%s</p></div>',
            esc_attr($class),
            esc_html(ucfirst($edition)),
            esc_html($status),
            $modules ? ' Unlocked: ' . esc_html(implode(', ', $modules)) . '.' : '',
            $expires ? ' Valid until ' . esc_html(gmdate('Y-m-d', (int) $expires)) . '.' : ''
        );
    }

    /**
     * Site-wide warning for an expiring/expired/perpetual licence — renderLicenseStatus()
     * above only shows on Settings → FluxFiles, so an operator who never opens that page
     * would otherwise find out the paid modules stopped working the hard way (a 402).
     */
    public function renderLicenseExpiryNotice(): void
    {
        if (!current_user_can('manage_options') || !class_exists('\\FluxFiles\\LicenseManager')) {
            return;
        }
        if (FluxFilesPlugin::licenseKey() === '') {
            return; // a pure free install has nothing to renew
        }

        $info = FluxFilesPlugin::license()->info();
        $status = (string) ($info['status'] ?? 'free');
        $daysLeft = $info['days_left'] ?? null;

        if (in_array($status, ['grace', 'expired', 'perpetual'], true)) {
            $bucket = $status;
        } elseif ($status === 'active' && $daysLeft !== null) {
            // The smallest configured threshold days_left has crossed, so the bucket
            // agrees with the vendor license-server's own reminder-bucket scheme
            // (e.g. days_left=25 -> active:30, days_left=6 -> active:7).
            $bucket = null;
            foreach ([30, 14, 7, 1] as $threshold) {
                if ($daysLeft <= $threshold) {
                    $bucket = 'active:' . $threshold;
                }
            }
            if ($bucket === null) {
                return; // more than 30 days left — nothing to warn about yet
            }
        } else {
            return; // 'free' (key did not verify) — renderLicenseStatus() already covers that
        }

        // Dismissal is keyed by bucket, not a boolean: dismissing "renews in 25 days"
        // must not silently swallow "expired" three weeks later.
        $dismissed = get_user_meta(get_current_user_id(), 'fluxfiles_license_notice_dismissed', true);
        if ($dismissed === $bucket) {
            return;
        }

        $edition = (string) ($info['edition'] ?? 'free');
        printf(
            '<div class="notice notice-warning is-dismissible fluxfiles-license-notice" data-bucket="%s" data-nonce="%s"><p>%s <a href="%s">%s</a></p></div>',
            esc_attr($bucket),
            esc_attr(wp_create_nonce('fluxfiles_dismiss_license_notice')),
            esc_html(sprintf(
                /* translators: 1: edition name, 2: licence status (active/grace/expired/perpetual) */
                __('Your FluxFiles %1$s licence is %2$s.', 'fluxfiles'),
                ucfirst($edition),
                $status
            )),
            esc_url(admin_url('options-general.php?page=fluxfiles')),
            esc_html__('View details', 'fluxfiles')
        );

        $this->enqueueDismissScript();
    }

    /**
     * WordPress's built-in `is-dismissible` handler (wp-admin/js/common.js) only fades
     * the notice out client-side — it never tells the server, so a worse status would
     * stay silently suppressed forever without this. A virtual (no-src) script handle
     * keeps the fix inline instead of shipping a whole new asset file for a few lines
     * of JS that only ever run when this specific notice is on screen.
     */
    private function enqueueDismissScript(): void
    {
        if (wp_script_is('fluxfiles-license-notice', 'enqueued')) {
            return;
        }
        wp_register_script('fluxfiles-license-notice', '', [], FLUXFILES_VERSION, true);
        wp_enqueue_script('fluxfiles-license-notice');
        wp_add_inline_script('fluxfiles-license-notice', <<<'JS'
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.fluxfiles-license-notice .notice-dismiss');
    if (!btn) {
        return;
    }
    var notice = btn.closest('.fluxfiles-license-notice');
    var body = new FormData();
    body.append('action', 'fluxfiles_dismiss_license_notice');
    body.append('bucket', notice.getAttribute('data-bucket') || '');
    body.append('nonce', notice.getAttribute('data-nonce') || '');
    fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body });
});
JS);
    }

    /** AJAX target for the inline dismiss script above — persists the dismissed bucket. */
    public function handleDismissLicenseNotice(): void
    {
        check_ajax_referer('fluxfiles_dismiss_license_notice', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        $bucket = sanitize_text_field((string) ($_POST['bucket'] ?? ''));
        if (preg_match('/^(active:(?:30|14|7|1)|grace|expired|perpetual)$/', $bucket) !== 1) {
            wp_send_json_error('invalid_bucket', 400);
        }
        update_user_meta(get_current_user_id(), 'fluxfiles_license_notice_dismissed', $bucket);
        wp_send_json_success();
    }

    /**
     * The key input. An env-provided key is shown as read-only rather than hidden:
     * an operator who set one and then sees an empty box will paste a second key and
     * wonder why nothing changes.
     */
    public function renderLicenseField(): void
    {
        $stored = (string) get_option('fluxfiles_license_key', '');
        $env = (string) (getenv('FLUXFILES_LICENSE_KEY') ?: ($_ENV['FLUXFILES_LICENSE_KEY'] ?? ''));

        if ($stored === '' && $env !== '') {
            printf(
                '<input type="text" class="large-text code" value="%s" readonly disabled />'
                . '<p class="description">Set by the FLUXFILES_LICENSE_KEY environment variable. '
                . 'Entering a key here would override it.</p>',
                esc_attr(substr($env, 0, 24) . '…')
            );
        }
        printf(
            '<textarea name="fluxfiles_license_key" rows="3" class="large-text code" '
            . 'placeholder="Paste the key from your purchase email">%s</textarea>'
            . '<p class="description">Verified offline — nothing is sent to us.</p>',
            esc_textarea($stored)
        );
    }

    private function addField(string $name, string $label, string $section, string $type = 'text'): void
    {
        add_settings_field(
            $name,
            $label,
            function () use ($name, $type) {
                $value = get_option($name, '');
                printf(
                    '<input type="%s" name="%s" value="%s" class="regular-text" />',
                    esc_attr($type),
                    esc_attr($name),
                    esc_attr($value)
                );
            },
            'fluxfiles',
            $section
        );
    }

    public function renderPermsField(): void
    {
        $current = get_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        $allPerms = ['read', 'write', 'delete'];

        foreach ($allPerms as $perm) {
            $checked = in_array($perm, $current, true) ? 'checked' : '';
            printf(
                '<label style="margin-right:15px"><input type="checkbox" name="fluxfiles_default_perms[]" value="%s" %s /> %s</label>',
                esc_attr($perm),
                $checked,
                esc_html(ucfirst($perm))
            );
        }
    }

    public function renderDisksField(): void
    {
        $current = get_option('fluxfiles_disks', ['local']);
        $allDisks = ['local', 's3', 'r2'];

        foreach ($allDisks as $disk) {
            $checked = in_array($disk, $current, true) ? 'checked' : '';
            printf(
                '<label style="margin-right:15px"><input type="checkbox" name="fluxfiles_disks[]" value="%s" %s /> %s</label>',
                esc_attr($disk),
                $checked,
                esc_html(strtoupper($disk))
            );
        }
    }

    public function renderOffloadField(): void
    {
        $on = get_option('fluxfiles_offload', '1') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_offload" value="0" />'
            . '<label><input type="checkbox" name="fluxfiles_offload" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            $on ? 'checked' : '',
            esc_html__('Register picked files as Media Library attachments served from your storage', 'fluxfiles'),
            esc_html__('One plugin instead of three: files picked from FluxFiles become real WP attachments (offloaded to S3/R2/SFTP, URLs rewritten) — folders + cloud + offload in one.', 'fluxfiles')
        );
    }

    public function renderStorageBackendField(): void
    {
        $current = get_option('fluxfiles_storage_backend', 'json');
        printf(
            '<select name="fluxfiles_storage_backend">'
            . '<option value="json" %s>%s</option>'
            . '<option value="db" %s>%s</option>'
            . '</select>'
            . '<p class="description">%s</p>',
            selected($current, 'json', false),
            esc_html__('JSON files (default)', 'fluxfiles'),
            selected($current, 'db', false),
            esc_html__('Database', 'fluxfiles'),
            esc_html__('Database mode stores metadata/search/trash/audit in your WordPress database (wp_fluxfiles_* tables) — created automatically on activation and kept up to date after plugin updates.', 'fluxfiles')
        );
    }

    public function renderReplacePickerField(): void
    {
        $on = get_option('fluxfiles_replace_picker', '0') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_replace_picker" value="0" />'
            . '<label><input type="checkbox" name="fluxfiles_replace_picker" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            $on ? 'checked' : '',
            esc_html__('Add a “From FluxFiles” button to the native WordPress media picker', 'fluxfiles'),
            esc_html__('Experimental: lets Featured Image, the core Image block and the Customizer pull from FluxFiles too. The FluxFiles button + Gutenberg block work without this. Test on staging first.', 'fluxfiles')
        );
    }

    public function renderDeleteStorageField(): void
    {
        $on = get_option('fluxfiles_delete_storage', '0') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_delete_storage" value="0" />'
            . '<label><input type="checkbox" name="fluxfiles_delete_storage" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            $on ? 'checked' : '',
            esc_html__('Also delete the file from FluxFiles storage when its attachment is deleted', 'fluxfiles'),
            esc_html__('Off by default: deleting an attachment leaves the file safe in your bucket. Turn on to keep storage in sync with the Media Library.', 'fluxfiles')
        );
    }

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    /**
     * @param mixed $input
     */
    public function sanitizePerms($input): array
    {
        if (!is_array($input)) {
            return ['read'];
        }
        return array_values(array_intersect($input, ['read', 'write', 'delete']));
    }

    /**
     * @param mixed $input
     */
    public function sanitizeDisks($input): array
    {
        if (!is_array($input)) {
            return ['local'];
        }
        return array_values(array_intersect($input, ['local', 's3', 'r2']));
    }
}
