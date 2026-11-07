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
        add_action('admin_notices', [$this, 'renderUnconfiguredNotice']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            esc_html__('FluxFiles Settings', 'fluxfiles'),
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
            esc_html__('General Settings', 'fluxfiles'),
            null,
            'fluxfiles'
        );

        $this->addField('fluxfiles_secret', esc_html__('JWT Secret', 'fluxfiles'), 'fluxfiles_general', 'password');
        $this->addField('fluxfiles_max_upload', esc_html__('Max Upload Size (MB)', 'fluxfiles'), 'fluxfiles_general', 'number');
        $this->addField('fluxfiles_max_storage', esc_html__('Max Storage Quota (MB, 0 = unlimited)', 'fluxfiles'), 'fluxfiles_general', 'number');
        $this->addField('fluxfiles_ttl', esc_html__('Token TTL (seconds)', 'fluxfiles'), 'fluxfiles_general', 'number');

        register_setting('fluxfiles', 'fluxfiles_secret', [
            'type' => 'string',
            'sanitize_callback' => $this->keepSecret('fluxfiles_secret', false, 32),
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
            esc_html__('Storage Backend', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Where file metadata (titles, tags, folder index, trash, audit log) is stored.', 'fluxfiles') . '</p>';
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
            esc_html__('Storage backend', 'fluxfiles'),
            [$this, 'renderStorageBackendField'],
            'fluxfiles',
            'fluxfiles_storage'
        );

        // Permissions section
        add_settings_section(
            'fluxfiles_permissions',
            esc_html__('Default Permissions', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Default permissions applied when generating tokens for WordPress users.', 'fluxfiles') . '</p>';
            },
            'fluxfiles'
        );

        register_setting('fluxfiles', 'fluxfiles_default_perms', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizePerms'],
        ]);

        add_settings_field(
            'fluxfiles_default_perms',
            esc_html__('Permissions', 'fluxfiles'),
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
            esc_html__('Enabled Disks', 'fluxfiles'),
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
            esc_html__('Media offload (3-in-1)', 'fluxfiles'),
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
            esc_html__('Native picker (experimental)', 'fluxfiles'),
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
            esc_html__('Delete from storage', 'fluxfiles'),
            [$this, 'renderDeleteStorageField'],
            'fluxfiles',
            'fluxfiles_permissions'
        );

        register_setting('fluxfiles', 'fluxfiles_local_private', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0',
            'default' => '0',
        ]);
        add_settings_field(
            'fluxfiles_local_private',
            esc_html__('Private local storage', 'fluxfiles'),
            [$this, 'renderLocalPrivateField'],
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
            esc_html__('Amazon S3', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Configure S3 storage. Leave bucket empty to disable.', 'fluxfiles') . '</p>';
            },
            'fluxfiles'
        );

        $this->addField('fluxfiles_s3_bucket', esc_html__('Bucket', 'fluxfiles'), 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_region', esc_html__('Region', 'fluxfiles'), 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_key', esc_html__('Access Key ID', 'fluxfiles'), 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_secret', esc_html__('Secret Access Key', 'fluxfiles'), 'fluxfiles_s3', 'password');
        $this->addField('fluxfiles_s3_endpoint', esc_html__('Endpoint (MinIO/Spaces — empty for AWS)', 'fluxfiles'), 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_visibility', esc_html__('Visibility (private | public)', 'fluxfiles'), 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_public_url', esc_html__('Public URL (CDN/custom domain)', 'fluxfiles'), 'fluxfiles_s3');

        foreach ([
            'fluxfiles_s3_bucket', 'fluxfiles_s3_region', 'fluxfiles_s3_key', 'fluxfiles_s3_secret',
            'fluxfiles_s3_endpoint', 'fluxfiles_s3_visibility', 'fluxfiles_s3_public_url',
        ] as $opt) {
            register_setting('fluxfiles', $opt, [
                'type' => 'string',
                'sanitize_callback' => $opt === 'fluxfiles_s3_secret' ? $this->keepSecret($opt, true) : 'sanitize_text_field',
            ]);
        }

        // R2 section
        add_settings_section(
            'fluxfiles_r2',
            esc_html__('Cloudflare R2', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Configure R2 storage. Leave bucket empty to disable.', 'fluxfiles') . '</p>';
            },
            'fluxfiles'
        );

        $this->addField('fluxfiles_r2_bucket', esc_html__('Bucket', 'fluxfiles'), 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_account_id', esc_html__('Account ID', 'fluxfiles'), 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_key', esc_html__('Access Key ID', 'fluxfiles'), 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_secret', esc_html__('Secret Access Key', 'fluxfiles'), 'fluxfiles_r2', 'password');
        $this->addField('fluxfiles_r2_visibility', esc_html__('Visibility (private | public)', 'fluxfiles'), 'fluxfiles_r2');
        $this->addField('fluxfiles_r2_public_url', esc_html__('Public URL (r2.dev / custom domain)', 'fluxfiles'), 'fluxfiles_r2');

        foreach ([
            'fluxfiles_r2_bucket', 'fluxfiles_r2_account_id', 'fluxfiles_r2_key', 'fluxfiles_r2_secret',
            'fluxfiles_r2_visibility', 'fluxfiles_r2_public_url',
        ] as $opt) {
            register_setting('fluxfiles', $opt, [
                'type' => 'string',
                'sanitize_callback' => $opt === 'fluxfiles_r2_secret' ? $this->keepSecret($opt, true) : 'sanitize_text_field',
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
        // Private local storage does NOT hide this notice: it only stops handing out plain
        // URLs. nginx still serves the folder (audit log, index and trash live in its
        // _fluxfiles/) until the operator denies it.
        if (!in_array('local', (array) get_option('fluxfiles_disks', ['local']), true)
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
        $private = (bool) get_option('fluxfiles_local_private', false);
        $snippet = $private
            ? "location ^~ {$root} {\n    deny all;\n}"
            : "location ^~ {$root} {\n"
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
            $private
                ? esc_html__('Private local storage is on, but this site runs on nginx, which ignores the .htaccess that denies the FluxFiles folder. Until you deny it in your server block, the files and the _fluxfiles/ bookkeeping (audit log with IP addresses, index, trash) can still be fetched by their plain URL. Add this rule (then reload nginx):', 'fluxfiles')
                : esc_html__('This site runs on nginx, which ignores the .htaccess protecting the FluxFiles uploads folder. Uploads there are served from the same origin as wp-admin: the folder must never execute PHP and must not serve HTML/SVG/XML as active content, and its _fluxfiles/ bookkeeping must not be public. Add rules like these to your server block (then reload nginx):', 'fluxfiles'),
            esc_html($snippet),
            $private
                ? esc_html__('FluxFiles keeps serving files through signed links, so denying the folder does not break the file manager.', 'fluxfiles')
                : esc_html__('Alternatively turn on private local storage and deny the folder (the rule is shown here once it is on); files are then only served through signed links.', 'fluxfiles')
        );
    }


    /**
     * A site with no JWT secret cannot mint a single token ("Unable to generate
     * token"). initSite() normally runs on activation / first request, so this is
     * the last resort (an emptied or deleted option): tell the admin where to fix it.
     */
    public function renderUnconfiguredNotice(): void
    {
        if (!current_user_can('manage_options') || (string) get_option('fluxfiles_secret', '') !== '') {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>FluxFiles:</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('This site has no secret key yet, so the file manager cannot sign its tokens.', 'fluxfiles'),
            esc_url(admin_url('options-general.php?page=fluxfiles')),
            esc_html__('Open Settings → FluxFiles and save a secret.', 'fluxfiles')
        );
    }

    private function addField(string $name, string $label, string $section, string $type = 'text'): void
    {
        add_settings_field(
            $name,
            $label,
            function () use ($name, $type) {
                $value = (string) get_option($name, '');
                if ($type === 'password') {
                    // Never echo a stored secret back into the page source: a blank
                    // submit keeps it (keepSecret()), a new value replaces it.
                    $hint = $value === ''
                        ? esc_html__('No secret is set.', 'fluxfiles')
                        : ($name === 'fluxfiles_secret' && strlen($value) >= 8
                            /* translators: %s: the last 4 characters of the stored secret. */
                            ? sprintf(esc_html__('A secret is set (ends in %s). Leave blank to keep it, or type a new one to replace it.', 'fluxfiles'), esc_html(substr($value, -4)))
                            : esc_html__('A secret is set. Leave blank to keep it, or type a new one to replace it.', 'fluxfiles'));
                    printf(
                        '<input type="password" name="%s" value="" autocomplete="new-password" class="regular-text" /><p class="description">%s</p>',
                        esc_attr($name),
                        $hint // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both branches are escaped above
                    );
                    if ($name !== 'fluxfiles_secret' && $value !== '') {
                        printf(
                            '<p><label><input type="checkbox" name="%1$s_clear" value="1" /> %2$s</label></p>',
                            esc_attr($name),
                            esc_html__('Remove the stored secret', 'fluxfiles')
                        );
                    }
                    return;
                }
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

    public function renderLocalPrivateField(): void
    {
        $on = (bool) get_option('fluxfiles_local_private', false);
        printf(
            '<input type="hidden" name="fluxfiles_local_private" value="0" />'
            . '<label><input type="checkbox" name="fluxfiles_local_private" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            $on ? 'checked' : '',
            esc_html__('Serve local files only through signed links', 'fluxfiles'),
            esc_html__('The file manager stops handing out plain URLs and streams files through FluxFiles on short-lived links, so a link saved in a post stops working. On Apache the uploads folder is then denied by its .htaccess. On nginx you must deny the folder yourself (a notice shows the rule). Files written before you switched this on keep their old permissions. Use an S3/R2 disk with a public URL for permanent links.', 'fluxfiles')
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
            esc_html__('Off by default: deleting the plugin from the Plugins screen then removes only the settings and rate-limit counters, and KEEPS the files in the local FluxFiles storage folder together with their metadata, trash, audit log and legal-hold tables, the secret key (the audit log key) and the settings that decide where and how that data is read (storage backend, storage location, private mode), so a reinstall picks everything up again. When on, all of that is permanently removed, including the trash and the generated variants. The files in the storage folder are never deleted, whoever put them there: remove the folder by hand if you no longer need them. From a custom location set with the FLUXFILES_STORAGE_DIR constant or the fluxfiles_storage_base filter only the rate-limit counters are deleted, and nothing from S3, R2 or SFTP.', 'fluxfiles')
        );
    }

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    /**
     * Sanitizer for a write-only secret field: a blank submit keeps the stored
     * value (the form never echoes it), anything else replaces it. S3/R2 secrets
     * can be removed with the companion `<option>_clear` checkbox; the JWT secret
     * can not be blanked (every token would fail).
     */
    private function keepSecret(string $option, bool $clearable, int $minBytes = 0): callable
    {
        return static function ($value) use ($option, $clearable, $minBytes): string {
            // trim only: sanitize_text_field() would strip %xx octets and <...> from a
            // secret and silently change it.
            $new = trim((string) $value);
            if ($new !== '' && strlen($new) < $minBytes) {
                add_settings_error(
                    $option,
                    $option . '_short',
                    /* translators: %d: minimum number of characters */
                    sprintf(esc_html__('The secret must be at least %d characters long; the stored one was kept.', 'fluxfiles'), $minBytes)
                );
                return (string) get_option($option, '');
            }
            if ($new !== '') {
                return $new;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs inside options.php, which has verified the settings nonce
            if ($clearable && isset($_POST[$option . '_clear']) && $_POST[$option . '_clear'] === '1') {
                return '';
            }
            return (string) get_option($option, '');
        };
    }

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
