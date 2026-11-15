<?php

defined('ABSPATH') || exit;

use FluxFiles\DiskManager;

/**
 * Tools > Site Health: two ASYNC tests (WordPress 5.6+ REST test API) that watch the parts of
 * FluxFiles that break without anyone touching the site: a cloud disk whose credentials were
 * revoked, an AI model the provider retired (docs/design/CONNECTION-TEST-DESIGN.md §8).
 *
 * Both are cheap by construction because Site Health runs on every visit to its screen and
 * from WordPress' weekly cron: the storage test only LISTS one item (never writes), the AI
 * test only LOOKS UP the model at the provider (never sends an image, so it costs nothing).
 * Results are cached in transients and dropped when the relevant settings change.
 *
 * It also builds the DiskManager the Cloud tab's "Test connection" runs on, so the two share
 * one place that turns the SAVED options into a disk, with no token involved.
 */
class FluxFilesSiteHealth
{
    public const NS = 'fluxfiles/v1';
    public const STORAGE_TRANSIENT = 'fluxfiles_health_storage';
    public const AI_TRANSIENT = 'fluxfiles_health_ai';
    public const STORAGE_TTL = HOUR_IN_SECONDS;
    public const AI_TTL = 12 * HOUR_IN_SECONDS;
    /** A failure that may pass on its own (network, rate limit, ...) is re-checked sooner. */
    public const AI_TTL_TRANSIENT = 15 * MINUTE_IN_SECONDS;

    /** AI codes that need the operator to change something. */
    private const AI_CRITICAL = ['ai_auth', 'ai_model_not_found', 'ai_no_vision', 'ai_blocked_host', 'ai_not_configured'];
    /** AI codes that are likely to clear without a settings change: cached briefly. */
    private const AI_TRANSIENT_CODES = ['ai_quota', 'ai_rate_limited', 'ai_network', 'ai_unavailable', 'ai_bad_response', 'ai_provider_error'];

    /** Test seam: builds the DiskManager from a `[id => config]` map. Null = the real one. */
    public static ?\Closure $diskManagerFactory = null;

    public static function register(): void
    {
        add_filter('site_status_tests', [self::class, 'addTests']);
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            add_action($hook, [self::class, 'onOptionChange']);
        }
    }

    /**
     * A DiskManager over the SAVED options for one disk (whether or not it is ticked under
     * "Enabled disks"). No token is minted, so nothing here can be replayed or widened.
     *
     * @param array<string,mixed> $config
     */
    public static function diskManagerFor(string $id, array $config): DiskManager
    {
        if (self::$diskManagerFactory !== null) {
            return (self::$diskManagerFactory)([$id => $config]);
        }
        return new DiskManager([$id => $config]);
    }

    /** @param array<string,array<string,mixed>> $tests */
    public static function addTests(array $tests): array
    {
        $tests['async']['fluxfiles_storage'] = [
            'label'             => __('FluxFiles storage', 'fluxfiles'),
            'test'              => rest_url(self::NS . '/health/storage'),
            'has_rest'          => true,
            'async_direct_test' => [self::class, 'testStorage'],
        ];
        if (trim((string) get_option('fluxfiles_ai_provider', '')) !== '') {
            $tests['async']['fluxfiles_ai'] = [
                'label'             => __('FluxFiles AI', 'fluxfiles'),
                'test'              => rest_url(self::NS . '/health/ai'),
                'has_rest'          => true,
                'async_direct_test' => [self::class, 'testAi'],
            ];
        }
        return $tests;
    }

    public static function registerRoutes(): void
    {
        foreach (['storage' => 'testStorage', 'ai' => 'testAi'] as $name => $method) {
            register_rest_route(self::NS, '/health/' . $name, [
                'methods'             => 'GET',
                'callback'            => [self::class, $method],
                'permission_callback' => [self::class, 'canView'],
            ]);
        }
    }

    public static function canView(): bool
    {
        return current_user_can('view_site_health_checks');
    }

    /** Drop the cached verdict when the settings it was computed from change. */
    public static function onOptionChange($option): void
    {
        $option = (string) $option;
        if (strncmp($option, 'fluxfiles_', 10) !== 0) {
            return;
        }
        if ($option === 'fluxfiles_disks' || preg_match('/^fluxfiles_(s3|r2|sftp)_/', $option) === 1) {
            delete_transient(self::STORAGE_TRANSIENT);
        }
        if (in_array($option, ['fluxfiles_ai_provider', 'fluxfiles_ai_api_key', 'fluxfiles_ai_model', 'fluxfiles_ai_base_url'], true)) {
            delete_transient(self::AI_TRANSIENT);
        }
    }

    // ---------------------------------------------------------------- storage

    /** @return array<string,mixed> a Site Health result */
    public static function testStorage(): array
    {
        $r = get_transient(self::STORAGE_TRANSIENT);
        if (!is_array($r) || !isset($r['status'], $r['disks'])) {
            $r = self::probeStorage();
            set_transient(self::STORAGE_TRANSIENT, $r, self::STORAGE_TTL);
        }
        return self::storageResult($r);
    }

    /**
     * One read-only list per enabled non-local disk.
     *
     * @return array{status:string,disks:array<string,array{state:string,message:string}>}
     */
    public static function probeStorage(): array
    {
        $configs = FluxFilesPlugin::diskConfigs();
        $disks = [];
        foreach ((array) get_option('fluxfiles_disks', ['local']) as $id) {
            $id = (string) $id;
            if ($id === 'local' || !in_array($id, ['s3', 'r2', 'sftp'], true)) {
                continue;
            }
            if (!isset($configs[$id])) {
                $disks[$id] = ['state' => 'missing', 'message' => ''];
                continue;
            }
            try {
                self::listOne($id, $configs[$id]);
                $disks[$id] = ['state' => 'ok', 'message' => ''];
            } catch (\Throwable $e) {
                $disks[$id] = ['state' => 'fail', 'message' => self::errorMessage($e)];
            }
        }
        $states = array_column($disks, 'state');
        $status = in_array('fail', $states, true) ? 'critical' : (in_array('missing', $states, true) ? 'recommended' : 'good');
        return ['status' => $status, 'disks' => $disks];
    }

    /** List one item: S3 `MaxKeys=1`, SFTP connects and lists the root. Never writes. */
    private static function listOne(string $id, array $config): void
    {
        $dm = self::diskManagerFor($id, $config);
        if (($config['driver'] ?? '') === 's3') {
            $dm->s3Client($id)->listObjectsV2(['Bucket' => (string) ($config['bucket'] ?? ''), 'MaxKeys' => 1]);
            return;
        }
        foreach ($dm->disk($id)->listContents('', false) as $item) {
            break;
        }
    }

    /** First useful line of an error, capped (the provider/SDK text may be long). */
    private static function errorMessage(\Throwable $e): string
    {
        if (method_exists($e, 'getAwsErrorCode') && (string) $e->getAwsErrorCode() !== '') {
            $m = (string) $e->getAwsErrorCode();
            $detail = method_exists($e, 'getAwsErrorMessage') ? trim(explode("\n", (string) $e->getAwsErrorMessage())[0]) : '';
            if ($detail !== '') {
                $m .= ': ' . $detail;
            }
        } else {
            $root = $e;
            while ($root->getPrevious() !== null) {
                $root = $root->getPrevious();
            }
            $m = trim(explode("\n", $root->getMessage())[0]);
            if ($m === '') {
                $m = (new \ReflectionClass($e))->getShortName();
            }
        }
        return strlen($m) > 200 ? substr($m, 0, 197) . '...' : $m;
    }

    /** @param array{status:string,disks:array<string,array{state:string,message:string}>} $r */
    private static function storageResult(array $r): array
    {
        $link = self::link('cloud', __('Open the Cloud settings', 'fluxfiles'));
        $names = ['s3' => 'S3', 'r2' => 'R2', 'sftp' => 'SFTP'];
        $items = '';
        foreach ($r['disks'] as $id => $d) {
            $name = $names[$id] ?? strtoupper((string) $id);
            if ($d['state'] === 'ok') {
                /* translators: %s: disk name, e.g. S3 */
                $line = sprintf(__('%s is reachable.', 'fluxfiles'), $name);
            } elseif ($d['state'] === 'missing') {
                /* translators: %s: disk name, e.g. S3 */
                $line = sprintf(__('%s is enabled but has no bucket or host saved.', 'fluxfiles'), $name);
            } else {
                /* translators: 1: disk name, e.g. S3, 2: the error */
                $line = sprintf(__('%1$s could not be listed: %2$s', 'fluxfiles'), $name, $d['message']);
            }
            $items .= '<li>' . esc_html($line) . '</li>';
        }
        if ($items === '') {
            $desc = '<p>' . esc_html__('Only local storage is enabled; there is no cloud disk to check.', 'fluxfiles') . '</p>';
        } elseif ($r['status'] === 'good') {
            $desc = '<p>' . esc_html__('Every enabled cloud disk answered a read-only listing.', 'fluxfiles') . '</p><ul>' . $items . '</ul>';
        } else {
            $desc = '<p>' . esc_html__('A cloud disk that is enabled for the file manager does not work, so users will see errors when they open it. Check the credentials, bucket or host, then run the connection test.', 'fluxfiles') . '</p><ul>' . $items . '</ul>';
        }
        return self::result('fluxfiles_storage', $r['status'] === 'good' ? __('Cloud storage is reachable', 'fluxfiles') : __('Cloud storage has a problem', 'fluxfiles'), $r['status'], $desc, $r['status'] === 'good' ? '' : $link);
    }

    // --------------------------------------------------------------------- ai

    /** @return array<string,mixed> a Site Health result */
    public static function testAi(): array
    {
        $r = get_transient(self::AI_TRANSIENT);
        if (!is_array($r) || !isset($r['status'])) {
            $r = self::probeAi();
            set_transient(self::AI_TRANSIENT, $r, $r['ttl']);
        }
        return self::aiResult($r);
    }

    /**
     * The free model lookup (never a vision call). Keeps only what is needed to render: no
     * key, no base URL, no provider text.
     *
     * @return array<string,mixed>
     */
    public static function probeAi(): array
    {
        $tagger = FluxFilesApi::savedAiTagger();
        if ($tagger === null) {
            return ['status' => 'good', 'state' => 'none', 'ttl' => self::AI_TTL];
        }
        $c = $tagger->checkModel();
        $base = [
            'provider'   => (string) ($c['provider'] ?? ''),
            'model'      => (string) ($c['model'] ?? ''),
            'retires_at' => isset($c['retires_at']) ? (string) $c['retires_at'] : '',
        ];
        if (!empty($c['ok'])) {
            if (($c['warning'] ?? null) === 'ai_model_deprecated') {
                return $base + ['status' => 'recommended', 'state' => 'deprecated', 'ttl' => self::AI_TTL];
            }
            return $base + ['status' => 'good', 'state' => !empty($c['verified']) ? 'ok' : 'unverified', 'ttl' => self::AI_TTL];
        }
        $code = (string) ($c['code'] ?? '');
        return $base + [
            'status' => in_array($code, self::AI_CRITICAL, true) ? 'critical' : 'recommended',
            'state'  => 'error',
            'code'   => $code,
            'hint'   => (string) ($c['hint'] ?? ''),
            'ttl'    => in_array($code, self::AI_TRANSIENT_CODES, true) || $code === '' ? self::AI_TTL_TRANSIENT : self::AI_TTL,
        ];
    }

    /** @param array<string,mixed> $r */
    private static function aiResult(array $r): array
    {
        $link = self::link('ai', __('Open the AI settings', 'fluxfiles'));
        $who = trim(($r['provider'] ?? '') . ' ' . ($r['model'] ?? ''));
        $state = (string) ($r['state'] ?? 'ok');
        if ($state === 'none') {
            $desc = '<p>' . esc_html__('No AI provider is configured.', 'fluxfiles') . '</p>';
        } elseif ($state === 'error') {
            $labels = FluxFilesAdmin::aiErrorLabels();
            $desc = '<p>' . esc_html($labels[$r['code'] ?? ''] ?? __('The AI check failed.', 'fluxfiles')) . '</p>';
            if (!empty($r['hint'])) {
                // The hint is English, like the rest of core's hints.
                $desc .= '<p>' . esc_html((string) $r['hint']) . '</p>';
            }
        } elseif ($state === 'deprecated') {
            $when = !empty($r['retires_at'])
                /* translators: %s: a date */
                ? ' ' . sprintf(__('It retires on %s.', 'fluxfiles'), $r['retires_at'])
                : '';
            /* translators: %s: provider and model */
            $desc = '<p>' . esc_html(sprintf(__('The AI model (%s) still works but is deprecated.', 'fluxfiles'), $who) . $when . ' ' . __('Pick a newer model before it is switched off.', 'fluxfiles')) . '</p>';
        } elseif ($state === 'unverified') {
            /* translators: %s: provider and model */
            $desc = '<p>' . esc_html(sprintf(__('The AI provider (%s) was reachable, but it cannot confirm the model ahead of time. Use "Test connection" on the AI tab to run a real check.', 'fluxfiles'), $who)) . '</p>';
        } else {
            /* translators: %s: provider and model */
            $desc = '<p>' . esc_html(sprintf(__('The configured AI model (%s) exists and accepts images.', 'fluxfiles'), $who)) . '</p>';
        }
        $label = $r['status'] === 'good' ? __('AI model is available', 'fluxfiles')
            : ($r['status'] === 'critical' ? __('AI model cannot be used', 'fluxfiles') : __('AI model needs attention', 'fluxfiles'));
        return self::result('fluxfiles_ai', $label, (string) $r['status'], $desc, $r['status'] === 'good' ? '' : $link);
    }

    // ----------------------------------------------------------------- shared

    /** @return string `<a>` to a settings tab (escaped) */
    private static function link(string $tab, string $label): string
    {
        return sprintf('<a href="%s">%s</a>', esc_url(admin_url('options-general.php?page=fluxfiles#' . $tab)), esc_html($label));
    }

    /**
     * @param string $description already escaped HTML
     * @param string $actions     already escaped HTML
     * @return array<string,mixed>
     */
    private static function result(string $test, string $label, string $status, string $description, string $actions): array
    {
        $color = ['good' => 'green', 'recommended' => 'orange', 'critical' => 'red'][$status] ?? 'blue';
        return [
            'label'       => $label,
            'status'      => $status,
            'badge'       => ['label' => 'FluxFiles', 'color' => $color],
            'description' => $description,
            'actions'     => $actions !== '' ? '<p>' . $actions . '</p>' : '',
            'test'        => $test,
        ];
    }
}
