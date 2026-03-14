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

        foreach (['fluxfiles_s3_bucket', 'fluxfiles_s3_region', 'fluxfiles_s3_key', 'fluxfiles_s3_secret'] as $opt) {
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

        foreach (['fluxfiles_r2_bucket', 'fluxfiles_r2_account_id', 'fluxfiles_r2_key', 'fluxfiles_r2_secret'] as $opt) {
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

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    public function sanitizePerms(mixed $input): array
    {
        if (!is_array($input)) {
            return ['read'];
        }
        return array_values(array_intersect($input, ['read', 'write', 'delete']));
    }

    public function sanitizeDisks(mixed $input): array
    {
        if (!is_array($input)) {
            return ['local'];
        }
        return array_values(array_intersect($input, ['local', 's3', 'r2']));
    }
}
