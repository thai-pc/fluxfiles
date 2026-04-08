<?php

defined('ABSPATH') || exit;

/**
 * Main plugin class — singleton that wires all hooks.
 */
class FluxFilesPlugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(FLUXFILES_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(FLUXFILES_PLUGIN_FILE, [$this, 'deactivate']);

        // Admin
        if (is_admin()) {
            new FluxFilesAdmin();
            new FluxFilesMediaButton();
        }

        // REST API
        add_action('rest_api_init', [FluxFilesApi::class, 'registerRoutes']);

        // Shortcode
        new FluxFilesShortcode();
    }

    /**
     * Plugin activation — create storage directory and set default options.
     */
    public function activate(): void
    {
        $storagePath = self::storagePath();

        if (!is_dir($storagePath)) {
            wp_mkdir_p($storagePath);
        }
        if (!is_dir($storagePath . '/uploads')) {
            wp_mkdir_p($storagePath . '/uploads');
        }

        // Set default options if not already set
        if (get_option('fluxfiles_secret') === false) {
            update_option('fluxfiles_secret', wp_generate_password(32, false));
        }
        if (get_option('fluxfiles_disks') === false) {
            update_option('fluxfiles_disks', ['local']);
        }
        if (get_option('fluxfiles_default_perms') === false) {
            update_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        }
        if (get_option('fluxfiles_max_upload') === false) {
            update_option('fluxfiles_max_upload', 10);
        }
        if (get_option('fluxfiles_max_storage') === false) {
            update_option('fluxfiles_max_storage', 0);
        }
        if (get_option('fluxfiles_ttl') === false) {
            update_option('fluxfiles_ttl', 3600);
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate(): void
    {
        flush_rewrite_rules();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the FluxFiles storage directory path.
     */
    public static function storagePath(): string
    {
        return WP_CONTENT_DIR . '/fluxfiles';
    }

    /**
     * Get the FluxFiles base installation path (project root).
     */
    public static function basePath(): string
    {
        return dirname(FLUXFILES_PLUGIN_DIR, 2);
    }

    /**
     * Get disk configurations.
     */
    public static function diskConfigs(): array
    {
        $storagePath = self::storagePath();

        $disks = [
            'local' => [
                'driver' => 'local',
                'root'   => $storagePath . '/uploads',
                'url'    => content_url('fluxfiles/uploads'),
            ],
        ];

        // S3
        $s3Bucket = get_option('fluxfiles_s3_bucket', '');
        if (!empty($s3Bucket)) {
            $disks['s3'] = [
                'driver' => 's3',
                'region' => get_option('fluxfiles_s3_region', 'ap-southeast-1'),
                'bucket' => $s3Bucket,
                'key'    => get_option('fluxfiles_s3_key', ''),
                'secret' => get_option('fluxfiles_s3_secret', ''),
            ];
        }

        // R2
        $r2Bucket = get_option('fluxfiles_r2_bucket', '');
        if (!empty($r2Bucket)) {
            $accountId = get_option('fluxfiles_r2_account_id', '');
            $disks['r2'] = [
                'driver'   => 's3',
                'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
                'region'   => 'auto',
                'bucket'   => $r2Bucket,
                'key'      => get_option('fluxfiles_r2_key', ''),
                'secret'   => get_option('fluxfiles_r2_secret', ''),
            ];
        }

        return $disks;
    }

    /**
     * Generate a JWT token for a WordPress user.
     */
    public static function generateToken(
        int $userId,
        array $overrides = []
    ): string {
        $secret = get_option('fluxfiles_secret', '');

        if (empty($secret)) {
            throw new \RuntimeException('FluxFiles secret is not configured.');
        }

        $defaultPerms  = get_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        $defaultDisks  = get_option('fluxfiles_disks', ['local']);
        $maxUpload     = (int) get_option('fluxfiles_max_upload', 10);
        $maxStorage    = (int) get_option('fluxfiles_max_storage', 0);
        $ttl           = (int) get_option('fluxfiles_ttl', 3600);

        $now = time();

        $payload = [
            'sub'         => (string) $userId,
            'iat'         => $now,
            'exp'         => $now + ($overrides['ttl'] ?? $ttl),
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? $defaultPerms,
            'disks'       => $overrides['disks'] ?? $defaultDisks,
            'prefix'      => $overrides['prefix'] ?? '',
            'max_upload'  => $overrides['max_upload'] ?? $maxUpload,
            'allowed_ext' => $overrides['allowed_ext'] ?? null,
            'max_storage' => $overrides['max_storage'] ?? $maxStorage,
        ];

        if (!empty($overrides['owner_only'])) {
            $payload['owner_only'] = true;
        }

        return \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Generate a BYOB (Bring Your Own Bucket) JWT token.
     *
     * @param int   $userId    WordPress user ID
     * @param array $byobDisks Map of disk name => S3 config array
     * @param array $overrides Optional overrides
     */
    public static function generateByobToken(
        int $userId,
        array $byobDisks,
        array $overrides = []
    ): string {
        $secret = get_option('fluxfiles_secret', '');

        if (empty($secret)) {
            throw new \RuntimeException('FluxFiles secret is not configured.');
        }

        $defaultPerms = get_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        $defaultDisks = get_option('fluxfiles_disks', ['local']);
        $maxUpload    = (int) get_option('fluxfiles_max_upload', 10);
        $maxStorage   = (int) get_option('fluxfiles_max_storage', 0);
        $ttl          = (int) get_option('fluxfiles_ttl', 3600);

        $now = time();

        // Encrypt BYOB disk configs
        $encryptedDisks = [];
        foreach ($byobDisks as $name => $config) {
            \FluxFiles\CredentialEncryptor::validate($name, $config);
            $encryptedDisks[$name] = \FluxFiles\CredentialEncryptor::encrypt($config, $secret);
        }

        $serverDisks = $overrides['disks'] ?? $defaultDisks;
        $allDisks = array_merge($serverDisks, array_keys($byobDisks));

        $payload = [
            'sub'         => (string) $userId,
            'iat'         => $now,
            'exp'         => $now + min($overrides['ttl'] ?? $ttl, 1800), // cap at 1800s for BYOB
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? $defaultPerms,
            'disks'       => $allDisks,
            'prefix'      => $overrides['prefix'] ?? '',
            'max_upload'  => $overrides['max_upload'] ?? $maxUpload,
            'allowed_ext' => $overrides['allowed_ext'] ?? null,
            'max_storage' => $overrides['max_storage'] ?? $maxStorage,
            'byob_disks'  => $encryptedDisks,
        ];

        if (!empty($overrides['owner_only'])) {
            $payload['owner_only'] = true;
        }

        return \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Generate a token for the current logged-in user.
     */
    public static function tokenForCurrentUser(array $overrides = []): string
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            throw new \RuntimeException('No authenticated WordPress user.');
        }

        return self::generateToken($userId, $overrides);
    }

    /**
     * Get the FluxFiles REST API endpoint base URL.
     */
    public static function apiEndpoint(): string
    {
        return rest_url('fluxfiles/v1');
    }
}
