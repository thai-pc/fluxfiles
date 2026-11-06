<?php

defined('ABSPATH') || exit;

/**
 * Admin settings page for FluxFiles.
 */
class FluxFilesAdmin
{
    /** Permissions an operator may grant on the settings screen (the token ceiling). */
    public const PERMS = ['read', 'write', 'delete', 'audit'];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'renderNginxStorageNotice']);
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

        register_setting('fluxfiles', 'fluxfiles_delete_data_on_uninstall', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0',
            'default' => '0',
        ]);
        add_settings_field(
            'fluxfiles_delete_data_on_uninstall',
            esc_html__('Delete data on uninstall', 'fluxfiles'),
            [$this, 'renderDeleteDataOnUninstallField'],
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

        // Extension point: add-ons (FluxFiles Pro) register their own sections and
        // settings on this page; $group is the settings_fields()/register_setting group.
        do_action('fluxfiles_admin_sections', 'fluxfiles', 'fluxfiles');
    }

    public function renderSettingsPage(): void
    {
        require FLUXFILES_PLUGIN_DIR . 'templates/settings.php';
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    /**
     * True when this site runs on nginx, which never reads the .htaccess that
     * FluxFilesPlugin::protectStorage() writes. Uses WP's own `$is_nginx` global
     * (wp-includes/vars.php) with a SERVER_SOFTWARE fallback.
     */
    public static function isNginx(): bool
    {
        if (!empty($GLOBALS['is_nginx'])) {
            return true;
        }
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
        return stripos($software, 'nginx') !== false;
    }

    /**
     * nginx + a public local disk: the uploads dir under wp-content/ is served by
     * nginx raw, in the SAME origin as wp-admin, with none of the .htaccess rules
     * (no PHP execution, HTML/SVG served inert, `_fluxfiles/` denied). The plugin
     * cannot write nginx config, so the operator has to — say so on the screens
     * where they manage plugins / FluxFiles. Hidden once local storage is private,
     * or via `add_filter('fluxfiles_nginx_storage_notice', '__return_false')` after
     * adding the rules.
     */
    public function renderNginxStorageNotice(): void
    {
        if (!current_user_can('manage_options') || !self::isNginx()) {
            return;
        }
        $page = (string) ($GLOBALS['pagenow'] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the current admin screen slug to decide where to show a notice; nothing is processed
        $screen = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== 'plugins.php' && !($page === 'options-general.php' && $screen === 'fluxfiles')) {
            return;
        }
        if ((bool) get_option('fluxfiles_local_private', false)
            || !in_array('local', (array) get_option('fluxfiles_disks', ['local']), true)
            || !apply_filters('fluxfiles_nginx_storage_notice', true)) {
            return;
        }
        // Same rules as README.md "Storage protection (Apache / nginx)", rooted at
        // this site's actual content path.
        $baseUrl = FluxFilesStorage::url(FluxFilesStorage::base());
        if ($baseUrl === '') {
            // No URL mapping (e.g. uploads/wp-content moved elsewhere), but a base
            // under ABSPATH is still served by nginx from the document root.
            $abs = rtrim(str_replace('\\', '/', (string) (defined('ABSPATH') ? ABSPATH : '')), '/');
            $base = FluxFilesStorage::base();
            if ($abs === '' || strpos($base . '/', $abs . '/') !== 0) {
                return; // outside the web root: nothing is web-served
            }
            $baseUrl = rtrim((string) (function_exists('site_url') ? site_url() : ''), '/') . substr($base, strlen($abs));
        }
        $root = '/' . trim((string) wp_parse_url($baseUrl, PHP_URL_PATH), '/') . '/';
        // Multisite keeps each site's uploads under sites/<blog_id>/uploads/.
        $multisite = function_exists('is_multisite') && is_multisite();
        $dir = $multisite ? $root . 'sites/[0-9]+/uploads/' : $root . 'uploads/';
        $snippet = "location ^~ {$root} {\n"
            . '    location ' . ($multisite ? '~ ^' : '^~ ') . $dir . " {\n"
            . "        allow all;\n"
            . "        add_header X-Content-Type-Options \"nosniff\" always;\n"
            . "        add_header Content-Security-Policy \"sandbox\" always;\n"
            . "        location ~* /_fluxfiles/ { deny all; }\n"
            . "        location ~ /\\. { deny all; }\n"
            . "        location ~* \\.(php[0-9]?|pht|phtml?|phar|phps|pgif|shtml?|stm|cgi|pl|py)$ { deny all; }\n"
            . "        location ~* \\.(html?|xhtml|xml|svgz?)$ {\n"
            . "            add_header X-Content-Type-Options \"nosniff\" always;\n"
            . "            add_header Content-Security-Policy \"sandbox\" always;\n"
            . "            add_header Content-Disposition \"attachment\" always;\n"
            . "        }\n"
            . "    }\n"
            . "    deny all;\n"
            . "}";
        printf(
            '<div class="notice notice-warning"><p><strong>FluxFiles:</strong> %s</p><pre style="white-space:pre-wrap">%s</pre><p>%s</p></div>',
            esc_html__('This site runs on nginx, which ignores the .htaccess protecting the FluxFiles uploads folder. Uploads there are served from the same origin as wp-admin: the folder must never execute PHP and must not serve HTML/SVG/XML as active content, and its _fluxfiles/ bookkeeping must not be public. Add rules like these to your server block (then reload nginx):', 'fluxfiles'),
            esc_html($snippet),
            esc_html__('Alternatively turn on private local storage so files are only served through signed links.', 'fluxfiles')
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
        // `audit` is the permission the compliance features check (Activity log, audit
        // export/verify, fixity, legal hold, retention). The capability-derived role
        // only hands it to admin-level users, and only when it is ticked here — this
        // option is the ceiling every embed token is intersected with.
        $allPerms = self::PERMS;

        foreach ($allPerms as $perm) {
            $checked = in_array($perm, $current, true) ? 'checked' : '';
            printf(
                '<label style="margin-right:15px"><input type="checkbox" name="fluxfiles_default_perms[]" value="%s" %s /> %s</label>',
                esc_attr($perm),
                esc_attr($checked),
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
                esc_attr($checked),
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

    public function renderDeleteDataOnUninstallField(): void
    {
        $on = get_option('fluxfiles_delete_data_on_uninstall', '0') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_delete_data_on_uninstall" value="0" />'
            . '<label><input type="checkbox" name="fluxfiles_delete_data_on_uninstall" value="1" %s /> %s</label>'
            . '<p class="description"><strong>%s</strong> %s</p>',
            $on ? 'checked' : '',
            esc_html__('Delete all FluxFiles data when the plugin is deleted', 'fluxfiles'),
            esc_html__('Warning:', 'fluxfiles'),
            esc_html__('Off by default: deleting the plugin from the Plugins screen then removes only the settings and rate-limit counters, and KEEPS the files in the local FluxFiles storage folder together with their metadata, trash, audit log and legal-hold tables, the secret key (the audit log key) and the settings that decide where and how that data is read (storage backend, storage location, private mode), so a reinstall picks everything up again. When on, all of that is permanently removed, including the trash; of the folder only the files FluxFiles wrote are deleted. Nothing is deleted from a custom location set with the FLUXFILES_STORAGE_DIR constant or the fluxfiles_storage_base filter, nor from S3, R2 or SFTP.', 'fluxfiles')
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
        return array_values(array_intersect($input, self::PERMS));
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
