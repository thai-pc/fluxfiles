<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/support/Harness.php';
require_once dirname(__DIR__) . '/admin/src/View.php';
require_once dirname(__DIR__) . '/admin/src/Auth.php';
require_once dirname(__DIR__) . '/Plans.php';
require_once dirname(__DIR__) . '/client/OpsText.php';
require_once dirname(__DIR__) . '/collector/LogCollector.php';

use FluxFiles\LicenseServer\{LicenseStore, LicenseSigner, LicenseIssuer};
use FluxFiles\LicenseServer\Admin\{View, I18n, Svg};
use FluxFiles\LicenseServer\Client\{Mask, OpsText};
use FluxFiles\LicenseServer\Collector\LogCollector;

$h = new Harness();
$s = new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);
$issuer = new LicenseIssuer(new LicenseSigner($h->secret), $s);
$h->test(
    'R2 immediate revoke falls back to licence expiry with null or absent period end',
    function () use ($s, $issuer): void {
        foreach (['null', 'absent'] as $shape) {
            foreach ([24, 72] as $hours) {
                $sid = 'immediate-' . $shape . '-' . $hours;
                $row = $issuer->issue([
                    'email' => $sid . '@example.test',
                    'plan' => 'pro-monthly',
                    'gateway' => 'polar',
                    'order_id' => $sid,
                    'subscription_id' => $sid,
                ])['record'];
                $s->pdo()
                    ->prepare('UPDATE licenses SET expires=? WHERE jti=?')
                    ->execute([time() + $hours * 3600, $row['jti']]);
                $data = [
                    'id' => $sid,
                    'status' => 'revoked',
                    'ended_at' => gmdate('c'),
                    'modified_at' => gmdate('c'),
                ];
                if ($shape === 'null') {
                    $data['current_period_end'] = null;
                }
                Harness::ok(
                    $s->subscriptionEvent(
                        ['type' => 'subscription.revoked', 'data' => $data],
                        $sid,
                    ),
                );
                Harness::eq(
                    $hours > 48 ? 'revoked' : 'active',
                    $s->findByJti($row['jti'])['status'],
                );
            }
        }
    },
);
$h->test(
    'R3 exact templates preserve buyer, product, target and nested argument text',
    function () use ($s): void {
        putenv('FLUXFILES_ADMIN_LANG=vi');
        $target = 'DOWNLOADS-api';
        Harness::eq(
            'Dịch vụ ngừng hoạt động · DOWNLOADS-api',
            OpsText::text('Health down · {target}', ['target' => $target]),
        );
        $message = OpsText::text('New order · {plan} · {email} · {order}', [
            'plan' => 'unknown-DOWN',
            'email' => 'j***@unknown.io',
            'order' => '{plan}',
        ]);
        Harness::eq('Đơn hàng mới · unknown-DOWN · j***@unknown.io · {plan}', $message);
        $s->enqueueAlert('test', 'crit', 'opaque-r2', $message);
        Harness::eq(
            $message,
            $s
                ->pdo()
                ->query("SELECT text FROM ops_outbox WHERE dedup_key='opaque-r2'")
                ->fetchColumn(),
        );
        $s->opsItem('test', $message);
        Harness::eq(
            $message,
            $s->pdo()->query("SELECT summary FROM ops_items WHERE kind='test'")->fetchColumn(),
        );
        Harness::eq('Health down · DOWNLOADS-api', OpsText::text('Health down · DOWNLOADS-api'));
        putenv('FLUXFILES_ADMIN_LANG');
    },
);
$h->test(
    'R4 missing core dependencies fail existing suites on CI instead of silently skipping',
    function () use ($h): void {
        $folder = $h->dir . '/checkout/services/license-server/tests';
        mkdir($folder, 0700, true);
        foreach (['test-license-server.php', 'test-renewal-http.php'] as $file) {
            copy(__DIR__ . '/' . $file, $folder . '/' . $file);
            $p = proc_open(
                [PHP_BINARY, $folder . '/' . $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['PATH' => (string) getenv('PATH'), 'CI' => 'true'],
            );
            $message = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            Harness::eq(1, proc_close($p), $file);
            Harness::ok(str_contains($message, 'composer install -d packages/core'));
        }
        $workflow = file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/license-admin.yml');
        Harness::ok(str_contains($workflow, 'actions/cache@v4'));
        Harness::ok(str_contains($workflow, 'composer install --working-dir=packages/core'));
    },
);
$h->test(
    'R7 diagnostic masking removes Polar, Resend and arbitrary Bearer tokens while retaining context',
    function (): void {
        $raw =
            'Connection rejected polar_oat_top_secret re_other_secret Bearer opaque.secret+/==; buyer@unknown.io';
        $clean = Mask::clean($raw);
        foreach (
            ['polar_oat_top_secret', 're_other_secret', 'opaque.secret+/==', 'buyer@unknown.io']
            as $secret
        ) {
            Harness::ok(!str_contains($clean, $secret), $secret);
        }
        Harness::ok(str_contains($clean, 'Connection rejected'));
        Harness::ok(str_contains($clean, 'b***@unknown.io'));
    },
);
$h->test(
    'R8 missing System facts explain next steps and zero MRR has distinct axis labels',
    function (): void {
        $v = new View(new I18n('vi'), null);
        ob_start();
        $v->render('system', [
            'title' => 'system',
            'data' => ['build' => null, 'mail_tls' => null, 'heartbeat' => null],
        ]);
        $html = ob_get_clean();
        foreach (
            ['Chưa có mã bản dựng', 'Chưa kiểm tra TLS', 'Chưa nhận báo cáo worker']
            as $label
        ) {
            Harness::ok(str_contains($html, $label), $label);
        }
        Svg::labels(new I18n('vi'));
        $chart = Svg::line(
            [['month' => '2026-01', 'currency' => 'usd', 'mrr' => 0]],
            'month',
            'mrr',
            'MRR',
        );
        preg_match_all('/class="axis-label y-axis"[^>]*>([^<]+)</', $chart, $labels);
        Harness::eq(count($labels[1]), count(array_unique($labels[1])));
        Harness::eq(
            1,
            count(array_filter($labels[1], static fn($label): bool => $label === '0.00 USD')),
        );
    },
);
$h->test(
    'R8 licence detail distinguishes empty installs and issuance action from column label',
    function () use ($s, $issuer): void {
        $row = $issuer->issue([
            'email' => 'detail-r2@example.test',
            'plan' => 'pro',
            'gateway' => 'manual',
        ])['record'];
        $v = new View(new I18n('vi'), null);
        ob_start();
        $v->render('detail', [
            'title' => 'licences',
            'data' => $s->licenceDetail($row['jti']) + ['customer_note' => ''],
        ]);
        $html = ob_get_clean();
        Harness::ok(str_contains($html, 'Chưa có cài đặt trong nhóm này'));
        Harness::ok(str_contains($html, 'Đã cấp'));
        Harness::ok(str_contains($html, 'Ngày cấp'));
    },
);
$h->test(
    'Collector cleaner accepts latest valid daily counts and ignores hostile or overlong lines',
    function (): void {
        $day = '2026-11-08';
        $parser = new LogCollector($day, new DateTimeZone('Asia/Ho_Chi_Minh'));
        $stream = fopen('php://temp', 'w+');
        fwrite(
            $stream,
            json_encode(['day' => $day, 'sandboxes_live' => 2, 'uploads_bytes' => 100]) . "\n",
        );
        fwrite($stream, "cleaner sandboxes_live=3 uploads_bytes=200\n");
        fwrite(
            $stream,
            json_encode(['day' => '2026-11-07', 'sandboxes_live' => 999, 'uploads_bytes' => 999]) .
                "\n",
        );
        fwrite(
            $stream,
            json_encode(['day' => $day, 'sandboxes_live' => -1, 'uploads_bytes' => 999]) . "\n",
        );
        fwrite($stream, str_repeat('x', 20000) . "sandboxes_live=999 uploads_bytes=999\n");
        fwrite($stream, "not json buyer@example.test Bearer raw-token\n");
        rewind($stream);
        Harness::eq(['sandboxes_live' => 3, 'uploads_bytes' => 200], $parser->cleaner($stream));
        fclose($stream);
    },
);
$h->finish();
