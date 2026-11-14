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
        add_action('admin_menu', [$this, 'addManagerPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'renderNginxStorageNotice']);
        add_action('wp_ajax_fluxfiles_nginx_probe', [$this, 'ajaxNginxProbe']);
        add_action('wp_ajax_fluxfiles_test_disk', [$this, 'ajaxTestDisk']);
        add_action('wp_ajax_fluxfiles_dismiss_notice', [$this, 'ajaxDismissNotice']);
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

    /** Slug of the Media > FluxFiles page (see FluxFilesPlugin::managerUrl()). Kept stable: e-mails link to it. */
    public const MANAGER_SLUG = 'fluxfiles-manager';

    /** Media > FluxFiles: the file manager itself, for anyone who may upload files. */
    public function addManagerPage(): void
    {
        add_media_page(
            esc_html__('FluxFiles', 'fluxfiles'),
            esc_html__('FluxFiles', 'fluxfiles'),
            class_exists('FluxFilesApi') ? FluxFilesApi::requiredCapability() : 'upload_files',
            self::MANAGER_SLUG,
            [$this, 'renderManagerPage']
        );
    }

    /**
     * Same embed as the [fluxfiles] shortcode (so its one-manager-per-page guard and token
     * refresh apply), at full height of the admin screen.
     */
    public function renderManagerPage(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('FluxFiles', 'fluxfiles') . '</h1>';
        // The shortcode escapes its own output (container id, size) and prints the error notices itself.
        echo do_shortcode('[fluxfiles mode="browser" height="calc(100vh - 170px)"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
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
        wp_enqueue_style('fluxfiles-settings', FLUXFILES_PLUGIN_URL . 'assets/settings.css', ['fluxfiles-admin'], FLUXFILES_VERSION);
        wp_enqueue_script('fluxfiles-settings', FLUXFILES_PLUGIN_URL . 'assets/settings.js', [], FLUXFILES_VERSION, true);
        wp_enqueue_script('fluxfiles-secret-field', FLUXFILES_PLUGIN_URL . 'assets/secret-field.js', [], FLUXFILES_VERSION, true);
        wp_localize_script('fluxfiles-settings', 'FluxFilesSettings', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'testNonce' => wp_create_nonce('fluxfiles_test_disk'),
            'i18n'      => [
                'testing'   => __('Testing…', 'fluxfiles'),
                'saveFirst' => __('Save your changes first; the test uses the saved settings.', 'fluxfiles'),
                'copied'    => __('Copied', 'fluxfiles'),
                'unsaved'   => __('You have unsaved changes', 'fluxfiles'),
                'leave'     => __('You have unsaved changes. Leave anyway?', 'fluxfiles'),
            ],
        ]);
    }

    /**
     * AJAX: check that a cloud disk works with the SAVED settings by listing its root the
     * same way the file manager does. Admin only; reports the storage error verbatim.
     */
    public function ajaxTestDisk(): void
    {
        check_ajax_referer('fluxfiles_test_disk');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'fluxfiles')], 403);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
        $disk = isset($_POST['disk']) ? sanitize_key(wp_unslash($_POST['disk'])) : '';
        if (!in_array($disk, ['s3', 'r2', 'sftp'], true)) {
            wp_send_json_error(['message' => __('Unknown disk.', 'fluxfiles')], 400);
        }
        if (!self::diskIsConfigured($disk)) {
            wp_send_json_error(['message' => $disk === 'sftp'
                ? __('Fill in the host and save first.', 'fluxfiles')
                : __('Fill in the bucket and save first.', 'fluxfiles')]);
        }
        if (!in_array($disk, (array) get_option('fluxfiles_disks', ['local']), true)) {
            /* translators: %s: disk name, e.g. S3 */
            wp_send_json_error(['message' => sprintf(__('Tick %s under Access › Enabled disks and save, then test again.', 'fluxfiles'), strtoupper($disk))]);
        }
        $start = microtime(true);
        try {
            $req = new \WP_REST_Request('GET', '/fluxfiles/v1/api/fm/list');
            $req->set_header('authorization', 'Bearer ' . FluxFilesPlugin::tokenForCurrentUser());
            $req->set_param('disk', $disk);
            $req->set_param('path', '');
            $req->set_param('limit', 1);
            $res = rest_do_request($req);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($res->get_status() === 200) {
            /* translators: %d: milliseconds */
            wp_send_json_success(['message' => sprintf(__('Connected (%d ms).', 'fluxfiles'), $ms)]);
        }
        $data = $res->get_data();
        $msg = is_array($data) ? (string) ($data['error'] ?? $data['message'] ?? '') : '';
        wp_send_json_error(['message' => $msg !== '' ? $msg : sprintf('HTTP %d', $res->get_status())]);
    }

    /**
     * True when a disk has what it needs to connect: `local` always, SFTP a host, S3/R2 a
     * bucket. An enabled disk that is not configured would be offered and then fail.
     */
    public static function diskIsConfigured(string $disk): bool
    {
        if ($disk === 'local') {
            return true;
        }
        $key = $disk === 'sftp' ? 'host' : 'bucket';
        return trim((string) get_option('fluxfiles_' . $disk . '_' . $key, '')) !== '';
    }

    /**
     * Status for the header of the settings page.
     *
     * @return array<int,array{label:string,value:string,state:string,id?:string}>
     */
    public static function statusSummary(): array
    {
        $secret = (string) get_option('fluxfiles_secret', '');
        $disks = (array) get_option('fluxfiles_disks', ['local']);
        $out = [];
        $out[] = [
            'label' => __('JWT secret', 'fluxfiles'),
            'value' => strlen($secret) >= 32 ? __('Set', 'fluxfiles') : __('Missing', 'fluxfiles'),
            'state' => strlen($secret) >= 32 ? 'ok' : 'bad',
        ];
        $diskLabels = [];
        $diskState = 'ok';
        foreach ($disks as $d) {
            $ok = self::diskIsConfigured((string) $d);
            $diskLabels[] = strtoupper((string) $d) . ($ok ? '' : ' ⚠');
            if (!$ok) {
                $diskState = 'warn';
            }
        }
        $out[] = [
            'label' => __('Storage', 'fluxfiles'),
            'value' => $diskLabels ? implode(' · ', $diskLabels) : __('None enabled', 'fluxfiles'),
            'state' => $diskLabels ? $diskState : 'bad',
        ];
        $out[] = [
            'label' => __('Metadata', 'fluxfiles'),
            'value' => get_option('fluxfiles_storage_backend', 'json') === 'db' ? __('Database', 'fluxfiles') : __('JSON files', 'fluxfiles'),
            'state' => 'ok',
        ];
        if (in_array('local', $disks, true)) {
            if (self::isNginx()) {
                $ctx = self::nginxContext();
                $cached = $ctx ? get_transient($ctx['cacheKey']) : false;
                $okState = is_array($cached) && ($cached['state'] ?? '') === 'ok';
                $out[] = [
                    'label' => __('Server protection', 'fluxfiles'),
                    'value' => $ctx === null ? __('Not web-served', 'fluxfiles') : ($okState ? __('nginx rules verified', 'fluxfiles') : __('Checking nginx…', 'fluxfiles')),
                    'state' => $ctx === null || $okState ? 'ok' : 'pending',
                    'id'    => 'ff-status-nginx',
                ];
            } else {
                $out[] = [
                    'label' => __('Server protection', 'fluxfiles'),
                    'value' => __('.htaccess', 'fluxfiles'),
                    'state' => 'ok',
                ];
            }
        }
        return $out;
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
        $this->addField('fluxfiles_max_upload', esc_html__('Max Upload Size (MB)', 'fluxfiles'), 'fluxfiles_general', 'number', ['min' => 1, 'step' => 1]);
        $this->addField('fluxfiles_max_storage', esc_html__('Max Storage Quota (MB, 0 = unlimited)', 'fluxfiles'), 'fluxfiles_general', 'number', ['min' => 0, 'step' => 1]);
        $this->addField('fluxfiles_ttl', esc_html__('Token TTL (seconds)', 'fluxfiles'), 'fluxfiles_general', 'number', [
            'min' => 60, 'max' => 86400, 'step' => 60,
            'hint' => esc_html__('How long the file manager stays signed in before it asks WordPress for a fresh token. 3600 = 1 hour.', 'fluxfiles'),
        ]);

        register_setting('fluxfiles', 'fluxfiles_secret', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeJwtSecret'],
        ]);
        // A blank or 0 would mean "no uploads" / "tokens that expire instantly", so fall back to the
        // defaults instead of saving a value that silently breaks the plugin.
        register_setting('fluxfiles', 'fluxfiles_max_upload', [
            'type' => 'integer',
            'sanitize_callback' => static function ($v) { $n = absint($v); return $n > 0 ? $n : 10; },
        ]);
        register_setting('fluxfiles', 'fluxfiles_max_storage', [
            'type' => 'integer',
            // absint('-5') is 5, which would turn a typo into a real quota; a negative means "none".
            'sanitize_callback' => static function ($v) { return max(0, (int) $v); },
        ]);
        register_setting('fluxfiles', 'fluxfiles_ttl', [
            'type' => 'integer',
            'sanitize_callback' => static function ($v) { $n = absint($v); return $n === 0 ? 3600 : max(60, min($n, 86400)); },
        ]);

        // Storage backend section
        add_settings_section(
            'fluxfiles_storage',
            esc_html__('Storage', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Where file metadata (titles, tags, folder index, trash, audit log) is kept, and how local files are served.', 'fluxfiles') . '</p>';
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
            'fluxfiles_storage',
            ['label_for' => 'fluxfiles_storage_backend']
        );

        // Permissions section
        add_settings_section(
            'fluxfiles_permissions',
            esc_html__('Access', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('What WordPress users may do in the file manager, and which storage they see. Each user still gets no more than their WordPress role allows.', 'fluxfiles') . '</p>';
            },
            'fluxfiles'
        );
        add_settings_section(
            'fluxfiles_media',
            esc_html__('Media Library', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('How files picked from FluxFiles join the WordPress Media Library and editors.', 'fluxfiles') . '</p>';
            },
            'fluxfiles'
        );
        add_settings_section(
            'fluxfiles_danger',
            esc_html__('Danger zone', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Settings that permanently remove data. Leave them off unless you are sure.', 'fluxfiles') . '</p>';
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
            'fluxfiles_media'
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
            'fluxfiles_media'
        );

        register_setting('fluxfiles', 'fluxfiles_picker_multiple', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0',
            'default' => '0',
        ]);
        add_settings_field(
            'fluxfiles_picker_multiple',
            esc_html__('Pick several files', 'fluxfiles'),
            [$this, 'renderPickerMultipleField'],
            'fluxfiles',
            'fluxfiles_media'
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
            'fluxfiles_media'
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
            'fluxfiles_storage'
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
            'fluxfiles_danger'
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

        $this->addField('fluxfiles_s3_bucket', esc_html__('Bucket', 'fluxfiles'), 'fluxfiles_s3', 'text', ['placeholder' => 'my-bucket']);
        $this->addField('fluxfiles_s3_region', esc_html__('Region', 'fluxfiles'), 'fluxfiles_s3', 'text', ['placeholder' => 'us-east-1']);
        $this->addField('fluxfiles_s3_key', esc_html__('Access Key ID', 'fluxfiles'), 'fluxfiles_s3');
        $this->addField('fluxfiles_s3_secret', esc_html__('Secret Access Key', 'fluxfiles'), 'fluxfiles_s3', 'password');
        $this->addField('fluxfiles_s3_endpoint', esc_html__('Endpoint (MinIO/Spaces — empty for AWS)', 'fluxfiles'), 'fluxfiles_s3', 'url', ['placeholder' => 'https://minio.example.com']);
        $this->addField('fluxfiles_s3_visibility', esc_html__('Visibility', 'fluxfiles'), 'fluxfiles_s3', 'visibility');
        $this->addField('fluxfiles_s3_public_url', esc_html__('Public URL (CDN/custom domain)', 'fluxfiles'), 'fluxfiles_s3', 'url', ['placeholder' => 'https://cdn.example.com']);

        register_setting('fluxfiles', 'fluxfiles_s3_bucket', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_s3_region', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_s3_key', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_s3_secret', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeS3Secret']]);
        register_setting('fluxfiles', 'fluxfiles_s3_endpoint', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeUrlOption']]);
        register_setting('fluxfiles', 'fluxfiles_s3_visibility', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeVisibility']]);
        register_setting('fluxfiles', 'fluxfiles_s3_public_url', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeUrlOption']]);

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
        $this->addField('fluxfiles_r2_visibility', esc_html__('Visibility', 'fluxfiles'), 'fluxfiles_r2', 'visibility');
        $this->addField('fluxfiles_r2_public_url', esc_html__('Public URL (r2.dev / custom domain)', 'fluxfiles'), 'fluxfiles_r2', 'url', ['placeholder' => 'https://pub-xxxx.r2.dev']);

        register_setting('fluxfiles', 'fluxfiles_r2_bucket', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_r2_account_id', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_r2_key', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_r2_secret', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeR2Secret']]);
        register_setting('fluxfiles', 'fluxfiles_r2_visibility', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeVisibility']]);
        register_setting('fluxfiles', 'fluxfiles_r2_public_url', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeUrlOption']]);

        // SFTP section
        add_settings_section(
            'fluxfiles_sftp',
            esc_html__('SFTP', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Connect an SFTP server you operate. Leave host empty to disable. Use a private key where you can, and pin the host key below. The credentials are stored in this site\'s options table, like the S3 keys.', 'fluxfiles') . '</p>';
            },
            'fluxfiles'
        );

        $this->addField('fluxfiles_sftp_host', esc_html__('Host', 'fluxfiles'), 'fluxfiles_sftp', 'text', ['placeholder' => 'sftp.example.com', 'hint' => __('A host name or IP address, no scheme. It must resolve to a public address; for a server on a private network, list it in FLUXFILES_SSRF_ALLOW_HOSTS in wp-config.php.', 'fluxfiles')]);
        $this->addField('fluxfiles_sftp_port', esc_html__('Port', 'fluxfiles'), 'fluxfiles_sftp', 'number', ['placeholder' => '22', 'min' => 1, 'max' => 65535]);
        $this->addField('fluxfiles_sftp_username', esc_html__('Username', 'fluxfiles'), 'fluxfiles_sftp');
        $this->addField('fluxfiles_sftp_password', esc_html__('Password', 'fluxfiles'), 'fluxfiles_sftp', 'password', ['hint' => __('Ignored when a private key is set.', 'fluxfiles')]);
        $this->addField('fluxfiles_sftp_private_key', esc_html__('Private key (PEM)', 'fluxfiles'), 'fluxfiles_sftp', 'secretarea', ['placeholder' => '-----BEGIN OPENSSH PRIVATE KEY-----']);
        $this->addField('fluxfiles_sftp_private_key_passphrase', esc_html__('Private key passphrase', 'fluxfiles'), 'fluxfiles_sftp', 'password');
        $this->addField('fluxfiles_sftp_root', esc_html__('Root folder', 'fluxfiles'), 'fluxfiles_sftp', 'text', ['placeholder' => '/', 'hint' => __('The folder on the server FluxFiles starts in. Defaults to /.', 'fluxfiles')]);
        $this->addField('fluxfiles_sftp_host_fingerprint', esc_html__('Host key fingerprint', 'fluxfiles'), 'fluxfiles_sftp', 'text', ['placeholder' => 'aa:bb:cc:…', 'hint' => __('Recommended. Without it any host key is trusted, so someone on the network path can impersonate your server. Colon-hex format, comma-separated to allow several keys. See the SFTP section of docs/reference/CONFIG.md for the commands that print it.', 'fluxfiles')]);
        $this->addField('fluxfiles_sftp_require_host_key', esc_html__('Require host key', 'fluxfiles'), 'fluxfiles_sftp', 'switch', ['label' => __('Refuse to connect unless a fingerprint is set', 'fluxfiles')]);
        $this->addField('fluxfiles_sftp_strict_algorithms', esc_html__('Modern algorithms only', 'fluxfiles'), 'fluxfiles_sftp', 'switch', ['label' => __('Refuse legacy key exchange, ciphers and MACs', 'fluxfiles'), 'hint' => __('Older or embedded SFTP servers may fail the handshake with this on.', 'fluxfiles')]);

        register_setting('fluxfiles', 'fluxfiles_sftp_host', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeSftpHost']]);
        register_setting('fluxfiles', 'fluxfiles_sftp_port', ['type' => 'integer', 'sanitize_callback' => [self::class, 'sanitizeSftpPort'], 'default' => 22]);
        register_setting('fluxfiles', 'fluxfiles_sftp_username', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_sftp_password', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeSftpPassword']]);
        register_setting('fluxfiles', 'fluxfiles_sftp_private_key', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeSftpPrivateKey']]);
        register_setting('fluxfiles', 'fluxfiles_sftp_private_key_passphrase', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeSftpPassphrase']]);
        register_setting('fluxfiles', 'fluxfiles_sftp_root', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeSftpRoot']]);
        register_setting('fluxfiles', 'fluxfiles_sftp_host_fingerprint', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeSftpFingerprint']]);
        register_setting('fluxfiles', 'fluxfiles_sftp_require_host_key', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeSftpRequireHostKey'], 'default' => '0']);
        register_setting('fluxfiles', 'fluxfiles_sftp_strict_algorithms', ['type' => 'string', 'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0', 'default' => '0']);

        // AI section. Free: tagging and alt text use the administrator's own provider account
        // (bring your own key); nothing here needs a licence or a FluxFiles module.
        add_settings_section(
            'fluxfiles_ai',
            esc_html__('AI tagging', 'fluxfiles'),
            function () {
                echo '<p>' . esc_html__('Optional. Choose a provider and enter your own API key to get tags and alt text for images. FluxFiles sends a downscaled copy of an image (at most 1024 px), the prompt and your key to that provider only, and only when you tag an image or tag on upload is on. Nothing goes through a FluxFiles server. The providers and their terms are listed under "External services" in this plugin\'s readme on WordPress.org.', 'fluxfiles') . '</p>';
            },
            'fluxfiles'
        );

        $this->addField('fluxfiles_ai_provider', esc_html__('Provider', 'fluxfiles'), 'fluxfiles_ai', 'ai_provider');
        $this->addField('fluxfiles_ai_api_key', esc_html__('API key', 'fluxfiles'), 'fluxfiles_ai', 'password');
        $this->addField('fluxfiles_ai_model', esc_html__('Model (optional)', 'fluxfiles'), 'fluxfiles_ai', 'text', ['hint' => __('Leave empty for the provider\'s default vision model. The model must accept images.', 'fluxfiles')]);
        $this->addField('fluxfiles_ai_base_url', esc_html__('Base URL (optional)', 'fluxfiles'), 'fluxfiles_ai', 'url', ['placeholder' => 'https://llm.example.com/v1', 'hint' => __('Only for a self-hosted or unlisted OpenAI-compatible endpoint (required for "OpenAI-compatible endpoint"). It is checked like any outbound address: private and local hosts, including Ollama on localhost, must be listed in FLUXFILES_SSRF_ALLOW_HOSTS in wp-config.php.', 'fluxfiles')]);
        $this->addField('fluxfiles_ai_auto_tag', esc_html__('Tag on upload', 'fluxfiles'), 'fluxfiles_ai', 'switch', ['label' => __('Tag new images automatically when they are uploaded', 'fluxfiles'), 'hint' => __('Every uploaded image is sent to the provider. Off by default; you can still tag single images by hand.', 'fluxfiles')]);

        register_setting('fluxfiles', 'fluxfiles_ai_provider', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeAiProvider']]);
        register_setting('fluxfiles', 'fluxfiles_ai_api_key', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeAiApiKey']]);
        register_setting('fluxfiles', 'fluxfiles_ai_model', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeTextOption']]);
        register_setting('fluxfiles', 'fluxfiles_ai_base_url', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeAiBaseUrl']]);
        register_setting('fluxfiles', 'fluxfiles_ai_auto_tag', ['type' => 'string', 'sanitize_callback' => static fn ($v) => $v === '1' ? '1' : '0', 'default' => '0']);

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
     * where they manage plugins / FluxFiles. Private local storage does not hide it
     * (it only stops plain URLs; the notice then shows a plain deny rule).
     *
     * Once the rules are in place the notice goes away by itself: the page loads at once with
     * the warning, then a background request asks the server whether the folder is really
     * denied (see probeNginxProtection) and swaps the warning for a "verified" line. The
     * `fluxfiles_nginx_storage_notice` filter still hides it outright.
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
        $ctx = self::nginxContext();
        if ($ctx === null) {
            return;
        }
        $onSettings = ($page === 'options-general.php');
        if (!$onSettings && self::noticeDismissed('nginx')) {
            return;
        }
        $verified = esc_html__('nginx protection verified: the storage folder denies _fluxfiles/, dotfiles and PHP files. Nothing to do.', 'fluxfiles');

        // A recent successful check: show nothing (a confirmation on the settings page only).
        $cached = get_transient($ctx['cacheKey']);
        if (is_array($cached) && ($cached['state'] ?? '') === 'ok') {
            return; // the settings page shows the verified state in its status header
        }

        $private = $ctx['private'];
        printf(
            '<div id="fluxfiles-nginx-notice" class="notice notice-warning is-dismissible" data-fluxfiles-notice="nginx"><p><strong>FluxFiles:</strong> %s</p><pre style="white-space:pre-wrap">%s</pre><p>%s</p><p class="fluxfiles-nginx-status"><em>%s</em></p></div>',
            $private
                ? esc_html__('Private local storage is on, but this site runs on nginx, which ignores the .htaccess that denies the FluxFiles folder. Until you deny it in your server block, the files and the _fluxfiles/ bookkeeping (audit log with IP addresses, index, trash) can still be fetched by their plain URL. Add this rule (then reload nginx):', 'fluxfiles')
                : esc_html__('This site runs on nginx, which ignores the .htaccess protecting the FluxFiles uploads folder. Uploads there are served from the same origin as wp-admin: the folder must never execute PHP and must not serve HTML/SVG/XML as active content, and its _fluxfiles/ bookkeeping must not be public. Add rules like these to your server block (then reload nginx):', 'fluxfiles'),
            esc_html($ctx['snippet']),
            $private
                ? esc_html__('FluxFiles keeps serving files through signed links, so denying the folder does not break the file manager.', 'fluxfiles')
                : esc_html__('Alternatively turn on private local storage and deny the folder (the rule is shown here once it is on); files are then only served through signed links.', 'fluxfiles'),
            esc_html__('Checking whether your server already denies the folder…', 'fluxfiles')
        );
        // Ask the server in the background so a slow loopback never delays the page.
        $cfg = [
            'url'        => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('fluxfiles_nginx_probe'),
            'onSettings' => $onSettings,
            'verified'   => $verified,
            'chipOk'      => esc_html__('nginx rules verified', 'fluxfiles'),
            'chipMissing' => esc_html__('nginx rules missing', 'fluxfiles'),
            'chipUnknown' => esc_html__('Could not check', 'fluxfiles'),
            'missing'    => esc_html__('Still missing on this server:', 'fluxfiles'),
            'unknown'    => esc_html__('FluxFiles could not check the rules automatically (the server did not answer a request to itself). If you already added them, you can ignore this; hide it with the fluxfiles_nginx_storage_notice filter.', 'fluxfiles'),
        ];
        wp_enqueue_script('fluxfiles-nginx-notice', FLUXFILES_PLUGIN_URL . 'assets/nginx-notice.js', [], FLUXFILES_VERSION, true);
        wp_localize_script('fluxfiles-nginx-notice', 'FluxFilesNginx', $cfg);
        $this->enqueueNoticeDismiss();
    }

    /** Notices a user may dismiss (and have it remembered) outside the FluxFiles settings page. */
    private const DISMISSIBLE = ['nginx', 'unconfigured'];

    private static function noticeDismissed(string $id): bool
    {
        $done = get_user_meta(get_current_user_id(), 'fluxfiles_dismissed_notices', true);
        return is_array($done) && in_array(self::noticeKey($id), $done, true);
    }

    /**
     * What is stored for a dismissal. The nginx notice is worded differently once private
     * storage is on, so a dismissal only holds for the mode it was made in.
     */
    private static function noticeKey(string $id): string
    {
        if ($id === 'nginx') {
            return 'nginx:' . (get_option('fluxfiles_local_private', false) ? 'private' : 'public');
        }
        return $id;
    }

    private function enqueueNoticeDismiss(): void
    {
        wp_enqueue_script('fluxfiles-notice-dismiss', FLUXFILES_PLUGIN_URL . 'assets/notice-dismiss.js', [], FLUXFILES_VERSION, true);
        wp_localize_script('fluxfiles-notice-dismiss', 'FluxFilesNotice', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fluxfiles_dismiss_notice'),
        ]);
    }

    /** AJAX: remember that the current user dismissed one of our notices. */
    public function ajaxDismissNotice(): void
    {
        check_ajax_referer('fluxfiles_dismiss_notice');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }
        $id = isset($_POST['notice']) ? sanitize_key(wp_unslash($_POST['notice'])) : '';
        if (!in_array($id, self::DISMISSIBLE, true)) {
            wp_send_json_error(null, 400);
        }
        $done = get_user_meta(get_current_user_id(), 'fluxfiles_dismissed_notices', true);
        $done = is_array($done) ? $done : [];
        $key = self::noticeKey($id);
        if (!in_array($key, $done, true)) {
            $done[] = $key;
            update_user_meta(get_current_user_id(), 'fluxfiles_dismissed_notices', $done);
        }
        wp_send_json_success();
    }

    /** AJAX: run the protection probe now (fresh) and report. Admin only. */
    public function ajaxNginxProbe(): void
    {
        check_ajax_referer('fluxfiles_nginx_probe');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['state' => 'unknown'], 403);
        }
        $ctx = self::nginxContext();
        if ($ctx === null) {
            wp_send_json_success(['state' => 'ok', 'missing' => []]);
        }
        wp_send_json_success(self::probeNginxProtection($ctx['checkPath'], $ctx['baseUrl'], $ctx['probeDir'], $ctx['private'], true));
    }

    /**
     * Where the storage folder is served and which rule set applies. Null when nothing is
     * web-served from it (not the local disk, or outside the web root) or the notice is
     * filtered off.
     *
     * @return array{private:bool,baseUrl:string,probeDir:string,checkPath:string,snippet:string,cacheKey:string}|null
     */
    private static function nginxContext(): ?array
    {
        // Private local storage does NOT hide this notice: it only stops handing out plain
        // URLs. nginx still serves the folder (audit log, index and trash live in its
        // _fluxfiles/) until the operator denies it.
        if (!in_array('local', (array) get_option('fluxfiles_disks', ['local']), true)
            || !apply_filters('fluxfiles_nginx_storage_notice', true)) {
            return null;
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
                return null; // outside the web root: nothing is web-served
            }
            $baseUrl = rtrim((string) (function_exists('site_url') ? site_url() : ''), '/') . substr($base, strlen($abs));
        }
        $root = '/' . trim((string) wp_parse_url($baseUrl, PHP_URL_PATH), '/') . '/';
        // Multisite keeps each site's uploads under sites/<blog_id>/uploads/.
        $multisite = function_exists('is_multisite') && is_multisite();
        $dir = $multisite ? $root . 'sites/[0-9]+/uploads/' : $root . 'uploads/';
        $private = (bool) get_option('fluxfiles_local_private', false);
        // `fluxfiles_local_private` is per site, but on multisite the storage base is
        // shared by the whole network: deny only THIS site's folder (sites/<blog_id>/),
        // or the rule would 403 the files of every public site.
        $denyRoot = $multisite ? $root . 'sites/' . (int) get_current_blog_id() . '/' : $root;
        $snippet = $private
            ? "location ^~ {$denyRoot} {\n    deny all;\n}"
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
        $probeDir = $multisite ? $root . 'sites/' . (int) get_current_blog_id() . '/uploads/' : $dir;
        $checkPath = $private ? $denyRoot : $probeDir;
        return [
            'private'   => $private,
            'baseUrl'   => $baseUrl,
            'probeDir'  => $probeDir,
            'checkPath' => $checkPath,
            'snippet'   => $snippet,
            'cacheKey'  => 'fluxfiles_nginx_probe_' . md5($checkPath . ($private ? 'p' : 'o')),
        ];
    }

    /**
     * HTTP status per key for a map of key => URL, fetched concurrently when WP's Requests
     * library can (WP >= 6.2), else one by one. A transport failure yields null for that key.
     *
     * @param array<string,string> $urls
     * @return array<string,int|null>
     */
    private static function fetchStatusCodes(array $urls): array
    {
        $out = [];
        if (class_exists('\\WpOrg\\Requests\\Requests')) {
            $reqs = [];
            foreach ($urls as $k => $u) {
                $reqs[$k] = ['url' => $u, 'type' => 'GET', 'headers' => ['Cache-Control' => 'no-cache']];
            }
            try {
                $res = \WpOrg\Requests\Requests::request_multiple($reqs, ['timeout' => 15, 'connect_timeout' => 10, 'follow_redirects' => false, 'verify' => false]);
                foreach ($urls as $k => $u) {
                    $r = $res[$k] ?? null;
                    $out[$k] = (is_object($r) && isset($r->status_code)) ? (int) $r->status_code : null;
                }
                return $out;
            } catch (\Throwable $e) {
                // fall through to the sequential path
            }
        }
        foreach ($urls as $k => $u) {
            $res = wp_remote_get($u, ['timeout' => 4, 'redirection' => 0, 'sslverify' => false]);
            $out[$k] = is_wp_error($res) ? null : (int) wp_remote_retrieve_response_code($res);
        }
        return $out;
    }

    /**
     * Ask the web server whether the storage folder is really protected. Sends loopback GETs to
     * paths that do not exist: a `deny` rule answers 403 even for a missing file, an unprotected
     * nginx answers 404/200. Nothing is written to disk.
     *
     * @param string $checkPath URL path to probe under (public: the uploads dir; private: the root)
     * @return array{state:string,missing:string[]} state: ok | missing | unknown
     */
    public static function probeNginxProtection(string $checkPath, string $baseUrl, string $dirPath, bool $private, bool $fresh): array
    {
        $cacheKey = 'fluxfiles_nginx_probe_' . md5($checkPath . ($private ? 'p' : 'o'));
        if (!$fresh) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && isset($cached['state'])) {
                return $cached;
            }
        }
        $origin = '';
        $parts = wp_parse_url($baseUrl);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
        }
        $dirPath = '/' . ltrim($dirPath, '/');
        // Public mode: the three deny rules the snippet adds. Private mode: the blanket deny.
        $checks = $private
            ? [
                __('the folder is denied', 'fluxfiles')   => $checkPath . 'fx-probe.txt',
                __('_fluxfiles/ is denied', 'fluxfiles')  => $checkPath . '_fluxfiles/fx-probe.txt',
            ]
            : [
                __('_fluxfiles/ is denied', 'fluxfiles')  => $dirPath . '_fluxfiles/fx-probe.txt',
                __('dotfiles are denied', 'fluxfiles')    => $dirPath . '.fx-probe',
                __('PHP files are denied', 'fluxfiles')   => $dirPath . 'fx-probe.php',
            ];
        $result = apply_filters('fluxfiles_nginx_probe', null, $checks, $origin);
        if (!is_array($result)) {
            $missing = [];
            $unknown = false;
            if ($origin === '') {
                $unknown = true;
            } else {
                // All probes in parallel: one round trip instead of one per rule (a slow
                // loopback, e.g. .local name resolution, would otherwise stack up).
                $codes = self::fetchStatusCodes(array_map(static function ($path) use ($origin) { return $origin . $path; }, $checks));
                foreach ($checks as $label => $path) {
                    $code = $codes[$label] ?? null;
                    if ($code === null) {
                        $unknown = true;
                        break;
                    }
                    if ($code !== 403 && $code !== 401) {
                        $missing[] = $label;
                    }
                }
            }
            $result = $missing ? ['state' => 'missing', 'missing' => $missing]
                : ($unknown ? ['state' => 'unknown', 'missing' => []] : ['state' => 'ok', 'missing' => []]);
        }
        // Cache the "ok" briefly so wp-admin → Plugins does not fire requests on every load.
        set_transient($cacheKey, $result, $result['state'] === 'ok' ? 10 * MINUTE_IN_SECONDS : 30);
        return $result;
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
        $page = (string) ($GLOBALS['pagenow'] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the current admin screen slug to decide where to show a notice; nothing is processed
        $screen = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        $onSettings = ($page === 'options-general.php' && $screen === 'fluxfiles');
        if ($page !== 'plugins.php' && !$onSettings) {
            return;
        }
        if (!$onSettings && self::noticeDismissed('unconfigured')) {
            return;
        }
        $this->enqueueNoticeDismiss();
        printf(
            '<div class="notice notice-error is-dismissible" data-fluxfiles-notice="unconfigured"><p><strong>FluxFiles:</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('This site has no secret key yet, so the file manager cannot sign its tokens.', 'fluxfiles'),
            esc_url(admin_url('options-general.php?page=fluxfiles')),
            esc_html__('Open Settings → FluxFiles and save a secret.', 'fluxfiles')
        );
    }

    /** Sanitizer for an S3/R2 endpoint or public URL option: http(s) only, blank allowed. */
    public static function sanitizeUrlOption($v): string
    {
        $v = trim((string) $v);
        return $v === '' ? '' : esc_url_raw($v, ['http', 'https']);
    }

    /** Sanitizer for a visibility option: the two values the storage layer understands (blank -> private). */
    public static function sanitizeVisibility($v): string
    {
        return $v === 'public' ? 'public' : 'private';
    }

    /** Sanitizer for a plain S3/R2 text option. */
    public static function sanitizeTextOption($v): string
    {
        return sanitize_text_field((string) $v);
    }

    /** Provider choices on the AI tagging section: option value => label (all values are AiTagger providers). */
    public static function aiProviders(): array
    {
        return [
            ''           => __('None (AI tagging off)', 'fluxfiles'),
            'anthropic'  => __('Anthropic (Claude)', 'fluxfiles'),
            'openai'     => __('OpenAI', 'fluxfiles'),
            'gemini'     => __('Google Gemini', 'fluxfiles'),
            'openrouter' => __('OpenRouter', 'fluxfiles'),
            'groq'       => __('Groq', 'fluxfiles'),
            'mistral'    => __('Mistral AI', 'fluxfiles'),
            'xai'        => __('xAI (Grok)', 'fluxfiles'),
            'ollama'     => __('Ollama (your own server)', 'fluxfiles'),
            'compatible' => __('OpenAI-compatible endpoint (set the base URL)', 'fluxfiles'),
        ];
    }

    /** Sanitizer for the AI provider: only a name the bundled AiTagger understands (aliases such as `claude` stay valid). */
    public static function sanitizeAiProvider($v): string
    {
        $v = strtolower(trim(sanitize_text_field((string) $v)));
        if ($v === '') {
            return '';
        }
        $known = class_exists(\FluxFiles\AiTagger::class)
            ? \FluxFiles\AiTagger::supportedProviders()
            : array_keys(self::aiProviders());
        return in_array($v, $known, true) ? $v : '';
    }

    public function sanitizeAiApiKey($v): string
    {
        return $this->keepSecret('fluxfiles_ai_api_key', true)($v);
    }

    /**
     * AI base URL: http(s), blank allowed, and the same SSRF check as every other outbound
     * address (FLUXFILES_SSRF_ALLOW_HOSTS applies). A refused value keeps the stored one.
     */
    public function sanitizeAiBaseUrl($v): string
    {
        $url = self::sanitizeUrlOption($v);
        if ($url === '') {
            return '';
        }
        try {
            // assertSafeUrl() refuses a `localhost`-style name outright; an operator who listed
            // the endpoint in FLUXFILES_SSRF_ALLOW_HOSTS (a local Ollama) has opted in.
            $parts = (array) wp_parse_url($url);
            $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
            if ($host === '' || !\FluxFiles\SsrfGuard::isAllowlistedHost($host, isset($parts['port']) ? (int) $parts['port'] : null)) {
                \FluxFiles\SsrfGuard::assertSafeUrl($url);
            }
        } catch (\Throwable $e) {
            add_settings_error(
                'fluxfiles_ai_base_url',
                'fluxfiles_ai_base_url_blocked',
                sprintf(
                    /* translators: 1: the URL, 2: the reason */
                    esc_html__('The AI base URL %1$s was refused (%2$s); the previous one was kept. A private or local host must be listed in FLUXFILES_SSRF_ALLOW_HOSTS in wp-config.php.', 'fluxfiles'),
                    esc_html($url),
                    esc_html($e->getMessage())
                )
            );
            return (string) get_option('fluxfiles_ai_base_url', '');
        }
        return $url;
    }

    /** Sanitizer for the SFTP port: 1-65535, anything else is 22. */
    public static function sanitizeSftpPort($v): int
    {
        $p = (int) $v;
        return ($p >= 1 && $p <= 65535) ? $p : 22;
    }

    /** Sanitizer for the SFTP root folder: absolute path, blank is `/`. */
    public static function sanitizeSftpRoot($v): string
    {
        $v = trim(sanitize_text_field((string) $v));
        return $v === '' ? '/' : '/' . ltrim($v, '/');
    }

    /**
     * SFTP host: a bare host name or IP, checked with SsrfGuard::assertHostSafe() against the
     * submitted port (the disk build repeats the check on every connection). A refused or
     * malformed host keeps the stored one and shows why.
     */
    public function sanitizeSftpHost($v): string
    {
        $host = trim(sanitize_text_field((string) $v));
        if ($host === '') {
            return '';
        }
        $stored = (string) get_option('fluxfiles_sftp_host', '');
        $fail = static function (string $why) use ($host, $stored): string {
            add_settings_error(
                'fluxfiles_sftp_host',
                'fluxfiles_sftp_host_refused',
                sprintf(
                    /* translators: 1: the host, 2: the reason */
                    esc_html__('The SFTP host %1$s was refused (%2$s); the previous host was kept. A host on a private network must be listed in FLUXFILES_SSRF_ALLOW_HOSTS in wp-config.php.', 'fluxfiles'),
                    esc_html($host),
                    esc_html($why)
                )
            );
            return $stored;
        };
        if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9._-]+)$/', $host) !== 1) {
            return $fail(__('enter a host name or IP address only, without a scheme, port or path', 'fluxfiles'));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs inside options.php, which has verified the settings nonce
        $port = self::sanitizeSftpPort(isset($_POST['fluxfiles_sftp_port']) ? sanitize_text_field(wp_unslash($_POST['fluxfiles_sftp_port'])) : 22);
        try {
            \FluxFiles\SsrfGuard::assertHostSafe($host, $port);
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }
        return $host;
    }

    public function sanitizeSftpPassword($v): string
    {
        return $this->keepSecret('fluxfiles_sftp_password', true)($v);
    }

    public function sanitizeSftpPassphrase($v): string
    {
        return $this->keepSecret('fluxfiles_sftp_private_key_passphrase', true)($v);
    }

    /** SFTP private key: a PEM block (line endings normalised); anything else keeps the stored key. */
    public function sanitizeSftpPrivateKey($v): string
    {
        $new = trim(str_replace("\r\n", "\n", (string) $v));
        if ($new !== '' && strpos($new, '-----BEGIN ') !== 0) {
            add_settings_error(
                'fluxfiles_sftp_private_key',
                'fluxfiles_sftp_private_key_format',
                esc_html__('That does not look like a PEM private key (it should start with -----BEGIN ...); the stored key was kept.', 'fluxfiles')
            );
            return (string) get_option('fluxfiles_sftp_private_key', '');
        }
        return $this->keepSecret('fluxfiles_sftp_private_key', true)($new);
    }

    /**
     * SFTP host-key fingerprint(s): colon-hex, comma-separated. A `MD5:` prefix is dropped;
     * a malformed value keeps the stored one rather than silently disabling pinning.
     */
    public function sanitizeSftpFingerprint($v): string
    {
        $raw = trim(sanitize_text_field((string) $v));
        if ($raw === '') {
            return '';
        }
        $out = [];
        foreach (explode(',', $raw) as $fp) {
            $fp = preg_replace('/^md5:/i', '', trim($fp));
            if (preg_match('/^[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2})+$/', (string) $fp) !== 1) {
                add_settings_error(
                    'fluxfiles_sftp_host_fingerprint',
                    'fluxfiles_sftp_host_fingerprint_format',
                    esc_html__('The host key fingerprint must be colon-separated hex pairs (aa:bb:cc:…), several separated by commas; the previous value was kept.', 'fluxfiles')
                );
                return (string) get_option('fluxfiles_sftp_host_fingerprint', '');
            }
            $out[] = (string) $fp;
        }
        return implode(',', $out);
    }

    /** "Require host key": warns when switched on without a fingerprint (every connection would then be refused). */
    public function sanitizeSftpRequireHostKey($v): string
    {
        if ($v !== '1') {
            return '0';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs inside options.php, which has verified the settings nonce
        $submitted = isset($_POST['fluxfiles_sftp_host_fingerprint']) ? trim(sanitize_text_field(wp_unslash($_POST['fluxfiles_sftp_host_fingerprint']))) : '';
        if ($submitted === '') {
            add_settings_error(
                'fluxfiles_sftp_require_host_key',
                'fluxfiles_sftp_require_host_key_nofp',
                esc_html__('"Require host key" is on but no host key fingerprint is set, so the SFTP disk will refuse to connect until you add one.', 'fluxfiles'),
                'warning'
            );
        }
        return '1';
    }

    public function sanitizeJwtSecret($v): string
    {
        return $this->keepSecret('fluxfiles_secret', false, 32)($v);
    }

    public function sanitizeS3Secret($v): string
    {
        return $this->keepSecret('fluxfiles_s3_secret', true)($v);
    }

    public function sanitizeR2Secret($v): string
    {
        return $this->keepSecret('fluxfiles_r2_secret', true)($v);
    }

    /** @param array<string,mixed> $args min / max / step / placeholder / hint */
    private function addField(string $name, string $label, string $section, string $type = 'text', array $args = []): void
    {
        add_settings_field(
            $name,
            $label,
            function () use ($name, $type, $args) {
                $value = (string) get_option($name, '');
                if ($type === 'password') {
                    // Never echo a stored secret back into the page source: a blank
                    // submit keeps it (keepSecret()), a new value replaces it.
                    $hint = $value === ''
                        ? esc_html__('No secret is set.', 'fluxfiles')
                        : ($name === 'fluxfiles_secret' && strlen($value) >= 8
                            /* translators: %s: the last 4 characters of the stored secret. */
                            ? sprintf(esc_html__('A secret is set (ends in %s). Leave blank to keep it, or type a new one to replace it.', 'fluxfiles'), esc_html(substr($value, -4)))
                              . ($name === 'fluxfiles_secret' ? ' ' . esc_html__('At least 32 characters. Changing it signs everyone out of open file managers and invalidates links FluxFiles already signed (private-storage links, share links).', 'fluxfiles') : '')
                            : esc_html__('A secret is set. Leave blank to keep it, or type a new one to replace it.', 'fluxfiles'));
                    // The stored secret is never echoed (it would sit in the page source), so the eye
                    // reveals only what the admin is typing or pasting; "Generate" fills a fresh random one.
                    $gen = $name === 'fluxfiles_secret'
                        ? sprintf('<button type="button" class="button ff-icon-btn fluxfiles-secret-gen" data-target="%1$s" aria-label="%2$s" title="%2$s">%3$s</button>', esc_attr($name), esc_attr__('Generate a random secret', 'fluxfiles'), '<svg class="ff-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg>')
                        : '';
                    printf(
                        '<span class="fluxfiles-secret-row ff-input-group"><input type="password" id="%1$s" name="%1$s" value="" autocomplete="new-password" spellcheck="false" autocapitalize="off" class="regular-text" aria-describedby="%1$s-hint"%3$s /><button type="button" class="button ff-icon-btn fluxfiles-secret-eye" data-target="%1$s" aria-controls="%1$s" aria-pressed="false" aria-label="%4$s" title="%4$s" data-show="%4$s" data-hide="%5$s"><svg class="ff-ico ff-ico-on" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="ff-ico ff-ico-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-2.2 3.1M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7c1.9 0 3.6-.6 5.1-1.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M2 2l20 20"/></svg></button>%6$s</span><p class="description" id="%1$s-hint">%2$s</p>',
                        esc_attr($name),
                        $hint, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both branches are escaped above
                        $name === 'fluxfiles_secret' ? ' minlength="32"' : '',
                        esc_attr__('Show what you typed', 'fluxfiles'),
                        esc_attr__('Hide', 'fluxfiles'),
                        $gen // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above
                    );
                    if ($name !== 'fluxfiles_secret' && $value !== '') {
                        printf(
                            '<p><label><input type="checkbox" name="%1$s_clear" value="1" /> %2$s</label></p>',
                            esc_attr($name),
                            esc_html__('Remove the stored secret', 'fluxfiles')
                        );
                    }
                    if (!empty($args['hint'])) {
                        printf('<p class="description">%s</p>', wp_kses_post((string) $args['hint']));
                    }
                    return;
                }
                if ($type === 'secretarea') {
                    // Same write-only contract as the password inputs: the stored key is never
                    // printed back; a blank submit keeps it, the checkbox removes it.
                    printf(
                        '<textarea id="%1$s" name="%1$s" rows="5" cols="50" class="large-text code" autocomplete="off" spellcheck="false" aria-describedby="%1$s-hint"%2$s></textarea><p class="description" id="%1$s-hint">%3$s</p>',
                        esc_attr($name),
                        isset($args['placeholder']) ? ' placeholder="' . esc_attr((string) $args['placeholder']) . '"' : '',
                        $value === ''
                            ? esc_html__('No key is stored.', 'fluxfiles')
                            : esc_html__('A key is stored. Leave blank to keep it, or paste a new one to replace it.', 'fluxfiles')
                    );
                    if ($value !== '') {
                        printf(
                            '<p><label><input type="checkbox" name="%1$s_clear" value="1" /> %2$s</label></p>',
                            esc_attr($name),
                            esc_html__('Remove the stored key', 'fluxfiles')
                        );
                    }
                    return;
                }
                if ($type === 'switch') {
                    printf(
                        '<input type="hidden" name="%1$s" value="0" /><label class="ff-switch-label"><input type="checkbox" class="ff-switch" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
                        esc_attr($name),
                        $value === '1' ? ' checked' : '',
                        esc_html((string) ($args['label'] ?? ''))
                    );
                    if (!empty($args['hint'])) {
                        printf('<p class="description">%s</p>', wp_kses_post((string) $args['hint']));
                    }
                    return;
                }
                if ($type === 'ai_provider') {
                    $choices = self::aiProviders();
                    if ($value !== '' && !isset($choices[$value])) {
                        $choices[$value] = $value; // an alias set with wp-cli (claude, google, grok)
                    }
                    echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
                    foreach ($choices as $id => $label) {
                        printf('<option value="%1$s"%2$s>%3$s</option>', esc_attr((string) $id), selected($value, (string) $id, false), esc_html($label));
                    }
                    echo '</select>';
                    return;
                }
                if ($type === 'visibility') {
                    $cur = $value === 'public' ? 'public' : 'private';
                    printf(
                        '<select id="%1$s" name="%1$s"><option value="private"%2$s>%3$s</option><option value="public"%4$s>%5$s</option></select>',
                        esc_attr($name),
                        selected($cur, 'private', false),
                        esc_html__('Private (presigned links)', 'fluxfiles'),
                        selected($cur, 'public', false),
                        esc_html__('Public (direct object URLs)', 'fluxfiles')
                    );
                    return;
                }
                // Every attribute is escaped where it is printed (Plugin Check cannot follow a
                // pre-escaped string into printf()).
                $extra = [];
                foreach (['min', 'max', 'step', 'placeholder'] as $a) {
                    if (isset($args[$a])) {
                        $extra[$a] = (string) $args[$a];
                    }
                }
                if ($type === 'number') {
                    $extra['inputmode'] = 'numeric';
                }
                $hint = !empty($args['hint']) ? (string) $args['hint'] : '';
                if ($hint !== '') {
                    $extra['aria-describedby'] = $name . '-hint';
                }
                printf(
                    '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s"',
                    esc_attr($type),
                    esc_attr($name),
                    esc_attr($value),
                    esc_attr($type === 'number' ? 'small-text' : 'regular-text')
                );
                foreach ($extra as $attr => $attrValue) {
                    printf(' %s="%s"', esc_attr($attr), esc_attr($attrValue));
                }
                echo ' />';
                if ($hint !== '') {
                    printf('<p class="description" id="%s">%s</p>', esc_attr($name . '-hint'), wp_kses_post($hint));
                }
            },
            'fluxfiles',
            $section,
            ['label_for' => $name]
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
                '<label class="ff-chip"><input type="checkbox" name="fluxfiles_default_perms[]" value="%s" %s /> <span>%s</span></label>',
                esc_attr($perm),
                esc_attr($checked),
                esc_html(ucfirst($perm))
            );
        }
        echo '<p class="description">' . esc_html__('Audit opens the activity log and compliance tools; it is only ever given to administrators.', 'fluxfiles') . '</p>';
    }

    public function renderDisksField(): void
    {
        $current = get_option('fluxfiles_disks', ['local']);
        $allDisks = FluxFilesPlugin::DISKS;

        foreach ($allDisks as $disk) {
            $checked = in_array($disk, $current, true) ? 'checked' : '';
            printf(
                '<label class="ff-chip"><input type="checkbox" name="fluxfiles_disks[]" value="%s" %s /> <span>%s</span></label>',
                esc_attr($disk),
                esc_attr($checked),
                esc_html(strtoupper($disk))
            );
        }
        // An enabled cloud disk with no bucket (or SFTP with no host) would be offered to users and then fail on first use.
        $unconfigured = [];
        foreach (['s3', 'r2', 'sftp'] as $disk) {
            if (in_array($disk, (array) $current, true) && !self::diskIsConfigured($disk)) {
                $unconfigured[] = strtoupper($disk);
            }
        }
        if ($unconfigured) {
            printf(
                '<p class="description" style="color:#b32d2e">%s</p>',
                /* translators: %s: disk names, e.g. "S3, R2" */
                sprintf(esc_html__('%s is enabled but has no bucket or host yet. Fill in its section below, or untick it; otherwise it will fail when someone opens it.', 'fluxfiles'), esc_html(implode(', ', $unconfigured)))
            );
        }
    }

    public function renderOffloadField(): void
    {
        $on = get_option('fluxfiles_offload', '1') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_offload" value="0" />'
            . '<label class="ff-switch-label"><input type="checkbox" class="ff-switch" name="fluxfiles_offload" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            $on ? 'checked' : '',
            esc_html__('Register picked files as Media Library attachments served from your storage', 'fluxfiles'),
            esc_html__('Files picked from FluxFiles become real WordPress attachments served straight from your storage (URLs rewritten, nothing copied into uploads). Needs a stable public URL: the local disk, or S3/R2 set to Public or with a Public URL. Private buckets are refused, because their links expire.', 'fluxfiles')
        );
    }

    public function renderStorageBackendField(): void
    {
        $current = get_option('fluxfiles_storage_backend', 'json');
        printf(
            '<select id="fluxfiles_storage_backend" name="fluxfiles_storage_backend" aria-describedby="fluxfiles_storage_backend-hint">'
            . '<option value="json" %s>%s</option>'
            . '<option value="db" %s>%s</option>'
            . '</select>'
            . '<p class="description" id="fluxfiles_storage_backend-hint">%s</p>',
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
            . '<label class="ff-switch-label"><input type="checkbox" class="ff-switch" name="fluxfiles_replace_picker" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            $on ? 'checked' : '',
            esc_html__('Add a “From FluxFiles” button to the native WordPress media picker', 'fluxfiles'),
            esc_html__('Experimental: lets Featured Image, the core Image block and the Customizer pull from FluxFiles too. The FluxFiles button + Gutenberg block work without this. Test on staging first.', 'fluxfiles')
        );
    }

    public function renderPickerMultipleField(): void
    {
        $on = get_option('fluxfiles_picker_multiple', '0') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_picker_multiple" value="0" />'
            . '<label class="ff-switch-label"><input type="checkbox" class="ff-switch" name="fluxfiles_picker_multiple" value="1" %s /> %s</label>'
            . '<p class="description">%s</p>',
            $on ? 'checked' : '',
            esc_html__('Let the “FluxFiles” button insert several files at once', 'fluxfiles'),
            esc_html__('Applies to the “FluxFiles” button next to “Add Media” in the classic editor. The native picker follows WordPress itself: Gallery and “Add Media” take several files, Featured Image and the Image block take one.', 'fluxfiles')
        );
    }

    public function renderDeleteStorageField(): void
    {
        $on = get_option('fluxfiles_delete_storage', '0') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_delete_storage" value="0" />'
            . '<label class="ff-switch-label"><input type="checkbox" class="ff-switch" name="fluxfiles_delete_storage" value="1" %s /> %s</label>'
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
            . '<label class="ff-switch-label"><input type="checkbox" class="ff-switch" name="fluxfiles_local_private" value="1" %s /> %s</label>'
            . '<p class="description">%s</p><details class="ff-more"><summary>%s</summary><p class="description">%s</p></details>',
            $on ? 'checked' : '',
            esc_html__('Serve local files only through signed links', 'fluxfiles'),
            esc_html__('Files are streamed through short-lived signed links instead of plain URLs, so a link pasted into a post stops working.', 'fluxfiles'),
            esc_html__('Details', 'fluxfiles'),
            esc_html__('The file manager stops handing out plain URLs and streams files through FluxFiles on short-lived links, so a link saved in a post stops working. On Apache the uploads folder is then denied by its .htaccess. On nginx you must deny the folder yourself (a notice shows the rule). Files written before you switched this on keep their old permissions, and links to attachments created while the folder was public stop working too. Use an S3/R2 disk with a public URL for permanent links.', 'fluxfiles')
        );
    }

    public function renderDeleteDataOnUninstallField(): void
    {
        $on = get_option('fluxfiles_delete_data_on_uninstall', '0') === '1';
        printf(
            '<input type="hidden" name="fluxfiles_delete_data_on_uninstall" value="0" />'
            . '<label class="ff-switch-label"><input type="checkbox" class="ff-switch" name="fluxfiles_delete_data_on_uninstall" value="1" %s /> %s</label>'
            . '<p class="description">%s</p><details class="ff-more"><summary>%s</summary><p class="description"><strong>%s</strong> %s</p></details>',
            $on ? 'checked' : '',
            esc_html__('Delete all FluxFiles data when the plugin is deleted', 'fluxfiles'),
            esc_html__('Off: a reinstall finds everything again. On: metadata, trash, audit log and settings are permanently removed (the files themselves are never deleted).', 'fluxfiles'),
            esc_html__('What exactly is kept or removed', 'fluxfiles'),
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
        return array_values(array_intersect($input, FluxFilesPlugin::DISKS));
    }
}
