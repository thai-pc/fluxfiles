<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/support/Harness.php';
require_once dirname(__DIR__) . '/admin/src/Auth.php';
require_once dirname(__DIR__) . '/admin/src/View.php';
require_once dirname(__DIR__) . '/admin/src/Csrf.php';
require_once dirname(__DIR__) . '/LicenseMailer.php';
require_once dirname(__DIR__) . '/client/OpsText.php';
use FluxFiles\LicenseServer\Admin\{Auth, Totp, View, I18n, Svg, Csrf};
use FluxFiles\LicenseServer\Client\{Mask, OpsText};
use FluxFiles\LicenseServer\LicenseMailer;

$h = new Harness();
$h->test(
    'B1/B3 local instance hosts are exact and CSV GET requires same-origin evidence',
    function (): void {
        foreach (['prod.localhost', 'sbx.localhost'] as $host) {
            Harness::ok(Csrf::localHost($host));
            Harness::ok(Csrf::host($host . ':8471', $host));
        }
        foreach (
            ['prod.localhost.evil', 'localhost@evil', 'evil.example', 'prod.localhost/']
            as $host
        ) {
            Harness::ok(!Csrf::localHost($host), $host);
        }
        Harness::ok(!Csrf::host('sbx.localhost:8473', 'prod.localhost'));
        Harness::ok(
            Csrf::download([
                'HTTP_HOST' => 'prod.localhost:8471',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ]),
        );
        foreach (['cross-site', 'same-site', 'none', ''] as $site) {
            Harness::ok(
                !Csrf::download([
                    'HTTP_HOST' => 'prod.localhost:8471',
                    'HTTP_SEC_FETCH_SITE' => $site,
                ]),
            );
        }
        Harness::ok(!Csrf::download(['HTTP_HOST' => 'prod.localhost:8471']));
        Harness::ok(
            Csrf::download([
                'HTTP_HOST' => 'prod.localhost:8471',
                'HTTP_ORIGIN' => 'http://prod.localhost:8471',
            ]),
        );
    },
);
$h->test(
    'B1/B3 login never grants step-up and dummy password hashes use the provisioned algorithm/cost',
    function () use ($h): void {
        $a = new Auth($h->dir . '/auth.sqlite');
        $secret = Totp::secret();
        $hash = Auth::hashPassword('review-password-long');
        $a->db
            ->prepare('INSERT INTO users(name,pass_hash,totp_secret,created_at) VALUES(?,?,?,?)')
            ->execute(['owner', $hash, $secret, time()]);
        $a->create();
        $loginCode = Totp::code($secret, intdiv(time(), 30));
        Harness::ok($a->login('owner', 'review-password-long', $loginCode));
        Harness::eq(null, $a->session['stepup_at']);
        Harness::ok(!$a->stepup(''));
        Harness::ok(!$a->stepup($loginCode));
        $r = new ReflectionClass(Auth::class);
        $dummy = $r->getConstant(defined('PASSWORD_ARGON2ID') ? 'DUMMY_ARGON' : 'DUMMY_BCRYPT');
        Harness::eq(password_get_info($hash)['algoName'], password_get_info($dummy)['algoName']);
        Harness::eq(password_get_info($hash)['options'], password_get_info($dummy)['options']);
    },
);
$h->test(
    'B3 session garbage collection honours configured timeouts; null roles are read-only',
    function () use ($h): void {
        putenv('FLUXFILES_ADMIN_SESSION_IDLE=7200');
        putenv('FLUXFILES_ADMIN_SESSION_ABSOLUTE=86400');
        $a = new Auth($h->dir . '/gc.sqlite');
        $id = str_repeat('a', 64);
        $a->db
            ->prepare('INSERT INTO sessions VALUES(?,NULL,?,?,NULL,?,?,?)')
            ->execute([$id, time() - 20000, time() - 3600, 'csrf', 'vi', 'auto']);
        $a->create();
        $q = $a->db->prepare('SELECT COUNT(*) FROM sessions WHERE id_hash=?');
        $q->execute([$id]);
        Harness::eq(1, (int) $q->fetchColumn());
        $q->closeCursor();
        putenv('FLUXFILES_ADMIN_SESSION_IDLE=600');
        $a->create();
        $q->execute([$id]);
        Harness::eq(0, (int) $q->fetchColumn());
        putenv('FLUXFILES_ADMIN_SESSION_IDLE');
        putenv('FLUXFILES_ADMIN_SESSION_ABSOLUTE');
        foreach ([null, [], ['role' => null], ['role' => 'viewer']] as $session) {
            Harness::ok(!(new View(new I18n(), $session))->canWrite());
        }
        Harness::ok((new View(new I18n(), ['role' => 'admin']))->canWrite());
    },
);
$h->test(
    'B9 diagnostics preserve useful masked detail while redacting secrets, addresses and signed keys',
    function (): void {
        putenv('FLUXFILES_SMTP_PASS=review-smtp-secret');
        $key = str_repeat('a', 20) . '.' . str_repeat('b', 20) . '.' . str_repeat('c', 20);
        $method = new ReflectionMethod(LicenseMailer::class, 'diagnostic');
        $text = $method->invoke(
            null,
            'SMTP rejected buyer@example.test Bearer abcdef ' .
                $key .
                ' review-smtp-secret ' .
                base64_encode('review-smtp-secret'),
        );
        Harness::ok(str_contains($text, 'SMTP rejected'));
        Harness::ok(!str_contains($text, 'buyer@example.test'));
        Harness::ok(!str_contains($text, 'abcdef'));
        Harness::ok(!str_contains($text, 'review-smtp-secret'));
        Harness::ok(!preg_match(Mask::KEY, $text));
        Harness::ok(strlen($method->invoke(null, str_repeat('x', 1000))) <= 400);
        putenv('FLUXFILES_SMTP_PASS');
    },
);
$h->test(
    'B18/B21 dynamic System, Polar and timeline labels exist in both catalogues without duplicate keys',
    function (): void {
        $required = [
            'configured',
            'matches',
            'expected_configured',
            'queue_depth',
            'last_error',
            'rows',
            'count',
            'order_id',
            'checkout_id',
            'license_history',
            'mailed',
            'stage_errors',
            'health',
            'mail_jobs',
            'inbox',
            'weekly',
            'outbox',
            'commands',
            'counters',
            'licenses',
            'orders',
            'admin_audit',
            'ops_outbox',
            'first_at',
            'uptime',
            'uptime_heading',
        ];
        foreach (['vi', 'en'] as $lang) {
            $file = dirname(__DIR__) . '/admin/lang/' . $lang . '.php';
            $strings = require $file;
            foreach ($required as $key) {
                Harness::ok(isset($strings[$key]), $lang . ' ' . $key);
                Harness::ok($strings[$key] !== $key, $lang . ' ' . $key);
            }
            $tokens = token_get_all(file_get_contents($file));
            $keys = [];
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $j = $i + 1;
                while (
                    isset($tokens[$j]) &&
                    is_array($tokens[$j]) &&
                    $tokens[$j][0] === T_WHITESPACE
                ) {
                    $j++;
                }
                if (
                    isset($tokens[$j]) &&
                    is_array($tokens[$j]) &&
                    $tokens[$j][0] === T_DOUBLE_ARROW
                ) {
                    $key = substr($token[1], 1, -1);
                    Harness::ok(!isset($keys[$key]), 'duplicate ' . $key);
                    $keys[$key] = true;
                }
            }
        }
        $v = new View(new I18n('vi'), null);
        $html = $v->facts([
            'configured' => true,
            'matches' => false,
            'rows' => ['licenses' => 1],
            'stage_errors' => ['mail_jobs'],
        ]);
        Harness::ok(!str_contains($html, 'configured'));
        Harness::ok(!str_contains($html, 'mail_jobs'));
        Harness::ok(str_contains($html, 'Đã cấu hình'));
    },
);
$h->test(
    'B19/B20 empty guidance, major-unit tables, chart title/legend/y-axis and translated fallbacks',
    function (): void {
        $v = new View(new I18n('vi'), null);
        $empty = $v->table([], [], null, 'empty_ops_items');
        Harness::ok(str_contains($empty, 'Không còn mục cần xử lý'));
        Harness::ok(!str_contains($empty, 'về bảng điều khiển'));
        $rows = [['month' => '2025-01', 'plan' => 'pro', 'currency' => 'usd', 'net' => 41700]];
        $html = $v->table($rows);
        Harness::ok(str_contains($html, '417.00 USD'));
        Harness::ok(!str_contains($html, '>41700<'));
        Svg::labels(new I18n('vi'));
        $svg = Svg::stackedBar($rows, 'month', 'net', 'Doanh thu', 'Bảng số liệu');
        Harness::ok(str_contains($svg, 'chart-title'));
        Harness::ok(str_contains($svg, 'chart-legend'));
        Harness::ok(str_contains($svg, 'y-axis'));
        Harness::ok(str_contains($svg, 'Doanh thu thuần'));
        Harness::ok(str_contains($svg, '417.00 USD'));
        Harness::ok(!str_contains($svg, '<th>month</th>'));
    },
);
$h->test(
    'B22 default Vietnamese alerts/digest/suppression/report labels retain optional English',
    function (): void {
        putenv('FLUXFILES_ADMIN_LANG');
        foreach (
            [
                ['Alert digest: {count} alerts; open the admin UI', ['count' => 3]],
                [' (+{count} similar)', ['count' => 2]],
                ['FluxFiles weekly report {week}', ['week' => '2025-W01']],
                ['Health down · {target}', ['target' => 'demo']],
                [
                    'New order · {plan} · {email} · {order}',
                    ['plan' => 'pro', 'email' => 'j***@unknown.io', 'order' => 'x'],
                ],
            ]
            as [$template, $args]
        ) {
            Harness::ok(OpsText::text($template, $args) !== $template);
        }
        Harness::ok(
            str_contains(OpsText::text(' (+{count} similar)', ['count' => 2]), 'lần tương tự'),
        );
        putenv('FLUXFILES_ADMIN_LANG=en');
        Harness::eq(
            'Health down · demo',
            OpsText::text('Health down · {target}', ['target' => 'demo']),
        );
        putenv('FLUXFILES_ADMIN_LANG');
    },
);
$h->test(
    'B25 browser runner has configurable portable paths and CI installs its test dependency',
    function (): void {
        $browser = file_get_contents(__DIR__ . '/browser-admin.cjs');
        Harness::ok(!str_contains($browser, '/usr/local/bin/php'));
        Harness::ok(!str_contains($browser, '/private/tmp'));
        foreach (
            [
                'FLUXFILES_TEST_PLAYWRIGHT_MODULE',
                'FLUXFILES_TEST_PHP_BINARY',
                'FLUXFILES_TEST_OUTPUT_DIR',
            ]
            as $name
        ) {
            Harness::ok(str_contains($browser, $name));
        }
        $workflow = file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/license-admin.yml');
        Harness::ok(str_contains($workflow, 'playwright@1.60.0'));
        Harness::ok(str_contains($workflow, 'browser-admin.cjs'));
        Harness::ok(str_contains($workflow, 'collector-root-smoke.php'));
    },
);
$h->finish();
