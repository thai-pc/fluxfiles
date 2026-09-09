<?php

/**
 * WordPress admin-notice regression test for the license-expiry feature
 * (LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md §4/§B): FluxFilesAdmin's
 * `admin_notices` hook must escalate past a stale dismissal, and the AJAX
 * dismiss handler must reject a bucket string it doesn't recognise.
 *
 * This is a SEPARATE process from test-wp-smoke.php on purpose: it stubs its
 * own minimal `FluxFilesPlugin` (returning a controllable fake license info)
 * instead of requiring the real `includes/FluxFilesPlugin.php`, which would
 * make `FluxFilesAdmin::renderLicenseExpiryNotice()` un-mockable (PHP cannot
 * override a real class's static methods without an extension). Loading both
 * the real and the fake `FluxFilesPlugin` in one process would also be a fatal
 * "cannot redeclare" error.
 *
 * Usage: php packages/wordpress/tests/test-wp-license-notice.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

// ── Minimal WordPress shims ────────────────────────────────────────────────
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');   // FluxFilesAdmin.php guards with `defined('ABSPATH') || exit`
}
if (!defined('FLUXFILES_VERSION')) {
    define('FLUXFILES_VERSION', '0.0.0-test');   // used by enqueueDismissScript()'s wp_register_script() call
}
if (!function_exists('add_action')) { function add_action(...$a) {} }

$GLOBALS['WP_CAN_MANAGE'] = true;
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return $GLOBALS['WP_CAN_MANAGE']; }
}

$GLOBALS['WP_CURRENT_USER_ID'] = 1;
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return $GLOBALS['WP_CURRENT_USER_ID']; }
}

$GLOBALS['WP_USER_META'] = [];
if (!function_exists('get_user_meta')) {
    function get_user_meta($userId, $key, $single = false) { return $GLOBALS['WP_USER_META'][$userId][$key] ?? ''; }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($userId, $key, $value) { $GLOBALS['WP_USER_META'][$userId][$key] = $value; return true; }
}

if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($s) { return (string) $s; } }
if (!function_exists('esc_textarea')) { function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('admin_url')) { function admin_url($path = '') { return 'http://wp.test/wp-admin/' . ltrim($path, '/'); } }
if (!function_exists('__')) { function __($s, $d = 'default') { return $s; } }
if (!function_exists('esc_html__')) { function esc_html__($s, $d = 'default') { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($action = -1) { return 'test-nonce'; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }

$GLOBALS['WP_SCRIPTS_ENQUEUED'] = [];
if (!function_exists('wp_script_is')) { function wp_script_is($h, $list = 'enqueued') { return in_array($h, $GLOBALS['WP_SCRIPTS_ENQUEUED'], true); } }
if (!function_exists('wp_register_script')) { function wp_register_script(...$a) { return true; } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script($h) { $GLOBALS['WP_SCRIPTS_ENQUEUED'][] = $h; } }
if (!function_exists('wp_add_inline_script')) { function wp_add_inline_script(...$a) { return true; } }

$GLOBALS['WP_AJAX_REFERER_OK'] = true;
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return $GLOBALS['WP_AJAX_REFERER_OK']; }
}

/**
 * Real wp_send_json_error()/wp_send_json_success() call wp_die() (process exit) after
 * emitting JSON — this halt exception mirrors that control-flow break so a test can
 * assert what WOULD have been sent, and that nothing after the halt point ran.
 */
class WpJsonHalt extends \Exception
{
    public function __construct(public $data, public int $statusCode, public bool $success)
    {
        parent::__construct('wp_send_json halt');
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $statusCode = null) { throw new WpJsonHalt($data, (int) ($statusCode ?? 200), false); }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $statusCode = null) { throw new WpJsonHalt($data, (int) ($statusCode ?? 200), true); }
}

// class_exists('\FluxFiles\LicenseManager') gates renderLicenseExpiryNotice(); load the
// real core class for that check (cheap, already the established pattern in this repo's
// other WP/Laravel smoke tests) — nothing about LicenseManager itself is stubbed here.
$coreAutoload = getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php';
require_once $coreAutoload;

/**
 * Deliberately NOT the real includes/FluxFilesPlugin.php — see the file header. Only
 * the two static entry points FluxFilesAdmin actually calls are stubbed, each backed by
 * a controllable global so a test can drive any license status/day-count.
 */
class FluxFilesPlugin
{
    public static string $testKey = 'test-license-key';
    /** @var array<string,mixed> */
    public static array $testInfo = [];

    public static function licenseKey(): string { return self::$testKey; }

    public static function license()
    {
        return new class(self::$testInfo) {
            public function __construct(private array $info) {}
            public function info(): array { return $this->info; }
        };
    }
}

require_once __DIR__ . '/../includes/FluxFilesAdmin.php';

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles WordPress License-Expiry Notice Test\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

function resetLicenseNoticeState(): void
{
    $GLOBALS['WP_USER_META'] = [];
    $GLOBALS['WP_CURRENT_USER_ID'] = 1;
    $GLOBALS['WP_CAN_MANAGE'] = true;
    FluxFilesPlugin::$testKey = 'test-license-key';
    FluxFilesPlugin::$testInfo = [];
}

function renderNotice(array $info): string
{
    FluxFilesPlugin::$testInfo = $info;
    $admin = new FluxFilesAdmin();
    ob_start();
    $admin->renderLicenseExpiryNotice();
    return (string) ob_get_clean();
}

// ── Trigger rules (§4) ───────────────────────────────────────────────────────

test('no licence key configured -> silent, regardless of status', function () {
    resetLicenseNoticeState();
    FluxFilesPlugin::$testKey = ''; // pure free install
    $out = renderNotice(['edition' => 'pro', 'status' => 'expired', 'days_left' => null]);
    assertTrue($out === '', 'a free install has nothing to renew');
});

test('a key that did not verify (status=free) -> silent', function () {
    resetLicenseNoticeState();
    $out = renderNotice(['edition' => 'free', 'status' => 'free', 'days_left' => null]);
    assertTrue($out === '', 'renderLicenseStatus() on the settings screen covers a bad key, not this notice');
});

test('active with more than 30 days left -> silent', function () {
    resetLicenseNoticeState();
    $out = renderNotice(['edition' => 'pro', 'status' => 'active', 'days_left' => 45]);
    assertTrue($out === '', 'nothing to warn about yet');
});

test('active with 25 days left -> rendered, bucketed as active:30', function () {
    resetLicenseNoticeState();
    $out = renderNotice(['edition' => 'pro', 'status' => 'active', 'days_left' => 25]);
    assertTrue(str_contains($out, 'data-bucket="active:30"'), 'crossed the 30-day threshold');
    assertTrue(str_contains($out, 'notice-warning'), 'uses the dismissible warning markup');
});

test('grace/expired/perpetual statuses render with the status name as the bucket', function () {
    resetLicenseNoticeState();
    assertTrue(str_contains(renderNotice(['edition' => 'pro', 'status' => 'grace', 'days_left' => -2]), 'data-bucket="grace"'), 'grace bucket');
    resetLicenseNoticeState();
    assertTrue(str_contains(renderNotice(['edition' => 'pro', 'status' => 'expired', 'days_left' => -20]), 'data-bucket="expired"'), 'expired bucket');
    resetLicenseNoticeState();
    assertTrue(str_contains(renderNotice(['edition' => 'studio', 'status' => 'perpetual', 'days_left' => -20]), 'data-bucket="perpetual"'), 'perpetual bucket');
});

test('a non-manage_options user never sees the notice', function () {
    resetLicenseNoticeState();
    $GLOBALS['WP_CAN_MANAGE'] = false;
    $out = renderNotice(['edition' => 'pro', 'status' => 'expired', 'days_left' => null]);
    assertTrue($out === '', 'capability gate matches every other admin-only surface in the plugin');
});

// ── Dismissal-bucket regression (§4) ─────────────────────────────────────────
// A plain boolean dismissal would pass every test above but silently swallow a
// WORSE state forever — this is the one behavior the design doc most needs a
// regression test for.

test('dismissing active:30 suppresses the notice while still in that same bucket', function () {
    resetLicenseNoticeState();
    $GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] = 'active:30';
    $out = renderNotice(['edition' => 'pro', 'status' => 'active', 'days_left' => 25]);
    assertTrue($out === '', 'still within the dismissed bucket, stays silent');
});

test('dismissing active:30 does NOT suppress the notice once it escalates to active:7', function () {
    resetLicenseNoticeState();
    $GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] = 'active:30';
    $out = renderNotice(['edition' => 'pro', 'status' => 'active', 'days_left' => 6]);
    assertTrue(str_contains($out, 'data-bucket="active:7"'), 'a worse active bucket un-suppresses the notice despite the earlier dismissal');
});

test('dismissing an active:* bucket does NOT suppress the notice once status escalates to expired', function () {
    resetLicenseNoticeState();
    $GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] = 'active:30';
    $out = renderNotice(['edition' => 'pro', 'status' => 'expired', 'days_left' => null]);
    assertTrue(str_contains($out, 'data-bucket="expired"'), 'status escalating past active un-suppresses despite the earlier dismissal');
});

test('dismissing the exact current bucket (e.g. "expired") suppresses it', function () {
    resetLicenseNoticeState();
    $GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] = 'expired';
    $out = renderNotice(['edition' => 'pro', 'status' => 'expired', 'days_left' => null]);
    assertTrue($out === '', 'an exact-bucket dismissal is honoured');
});

test('dismissal is per-user: another user still sees the notice', function () {
    resetLicenseNoticeState();
    $GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] = 'active:30';
    $GLOBALS['WP_CURRENT_USER_ID'] = 2;
    $out = renderNotice(['edition' => 'pro', 'status' => 'active', 'days_left' => 25]);
    assertTrue(str_contains($out, 'data-bucket="active:30"'), 'user 2 never dismissed anything');
});

// ── Dismiss AJAX handler: bucket allowlist validation (post-review fix) ─────

test('handleDismissLicenseNotice(): a bogus/attacker-controlled bucket is rejected with 400, never stored', function () {
    resetLicenseNoticeState();
    $_POST = ['bucket' => "active:30'; DROP TABLE wp_usermeta; --", 'nonce' => 'test-nonce'];
    $admin = new FluxFilesAdmin();
    try {
        $admin->handleDismissLicenseNotice();
        throw new \RuntimeException('expected handleDismissLicenseNotice() to halt via wp_send_json_error()');
    } catch (WpJsonHalt $halt) {
        assertEqual(400, $halt->statusCode, 'rejected with 400');
        assertEqual(false, $halt->success);
        assertEqual('invalid_bucket', $halt->data);
    }
    assertTrue(empty($GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] ?? null), 'the bogus bucket is never persisted to user-meta');
});

test('handleDismissLicenseNotice(): an arbitrary string outside the known bucket shapes is rejected', function () {
    resetLicenseNoticeState();
    $_POST = ['bucket' => 'active:9999', 'nonce' => 'test-nonce'];
    $admin = new FluxFilesAdmin();
    try {
        $admin->handleDismissLicenseNotice();
        throw new \RuntimeException('expected a halt');
    } catch (WpJsonHalt $halt) {
        assertEqual(400, $halt->statusCode);
    }
    assertTrue(empty($GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] ?? null), 'not a real threshold bucket, never stored');
});

test('handleDismissLicenseNotice(): a real bucket (active:7) is accepted and stored', function () {
    resetLicenseNoticeState();
    $_POST = ['bucket' => 'active:7', 'nonce' => 'test-nonce'];
    $admin = new FluxFilesAdmin();
    try {
        $admin->handleDismissLicenseNotice();
        throw new \RuntimeException('expected a halt');
    } catch (WpJsonHalt $halt) {
        assertEqual(true, $halt->success, 'wp_send_json_success() is reached for a valid bucket');
    }
    assertEqual('active:7', $GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] ?? null, 'the valid bucket is persisted');
});

foreach (['grace', 'expired', 'perpetual'] as $stateBucket) {
    test("handleDismissLicenseNotice(): the state-only bucket \"{$stateBucket}\" is accepted", function () use ($stateBucket) {
        resetLicenseNoticeState();
        $_POST = ['bucket' => $stateBucket, 'nonce' => 'test-nonce'];
        $admin = new FluxFilesAdmin();
        try {
            $admin->handleDismissLicenseNotice();
            throw new \RuntimeException('expected a halt');
        } catch (WpJsonHalt $halt) {
            assertEqual(true, $halt->success);
        }
        assertEqual($stateBucket, $GLOBALS['WP_USER_META'][1]['fluxfiles_license_notice_dismissed'] ?? null);
    });
}

test('handleDismissLicenseNotice(): a non-manage_options caller is rejected with 403 before the bucket is even checked', function () {
    resetLicenseNoticeState();
    $GLOBALS['WP_CAN_MANAGE'] = false;
    $_POST = ['bucket' => 'active:7', 'nonce' => 'test-nonce'];
    $admin = new FluxFilesAdmin();
    try {
        $admin->handleDismissLicenseNotice();
        throw new \RuntimeException('expected a halt');
    } catch (WpJsonHalt $halt) {
        assertEqual(403, $halt->statusCode);
        assertEqual(false, $halt->success);
    }
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
