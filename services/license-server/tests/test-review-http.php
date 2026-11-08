<?php

declare(strict_types=1);

require_once __DIR__ . '/support/Harness.php';
require_once dirname(__DIR__) . '/admin/src/Access.php';
use FluxFiles\LicenseServer\{LicenseStore};
use FluxFiles\LicenseServer\Admin\Access;
use FluxFiles\LicenseServer\Client\Mask;

$h = new Harness();
$api = $h->api();
$s = new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);
$db = $s->pdo();
$h->test(
    'B6 paid Polar envelopes above 64 KiB issue normally, storage/replay remain bounded',
    function () use ($h, $api, $db): void {
        $event = [
            'type' => 'order.paid',
            'data' => [
                'id' => 'large-order',
                'status' => 'paid',
                'product_id' => 'pro',
                'customer' => ['email' => 'large@example.test'],
                'metadata' => ['padding' => str_repeat('x', 70000)],
            ],
        ];
        $r = $h->hook($api, $event, 'large-paid');
        Harness::eq(200, $r['code']);
        Harness::eq(true, $r['body']['issued']);
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM licenses WHERE order_id='large-order'")
                ->fetchColumn(),
        );
        $event['data']['id'] = 'large-unmapped';
        $event['data']['product_id'] = 'unmapped';
        Harness::eq(503, $h->hook($api, $event, 'large-unmapped')['code']);
        Harness::eq(
            null,
            $db
                ->query("SELECT body FROM webhook_events WHERE webhook_id='large-unmapped'")
                ->fetchColumn(),
        );
        $event['data']['metadata']['padding'] = str_repeat('x', 1048576);
        Harness::eq(400, $h->hook($api, $event, 'too-large')['code']);
    },
);
$h->test(
    'B2 hostile key-shaped customer cells are redacted, counted and cannot poison CSV',
    function () use ($h, $api, $db): void {
        $keyShape = str_repeat('a', 20) . '.' . str_repeat('b', 20) . '.' . str_repeat('c', 20);
        $event = [
            'type' => 'order.paid',
            'data' => [
                'id' => 'csv-hostile',
                'status' => 'paid',
                'product_id' => 'pro',
                'currency' => 'usd',
                'net_amount' => 41700,
                'total_amount' => 41700,
                'created_at' => gmdate('c'),
                'customer' => ['email' => 'csv-hostile@example.test', 'name' => $keyShape],
            ],
        ];
        Harness::eq(200, $h->hook($api, $event, 'csv-hostile')['code']);
        $r = $h->call($api, 'GET', '/admin/export/orders.csv');
        Harness::eq(200, $r['code']);
        Harness::ok(str_contains($r['raw'], '[redacted]'));
        Harness::ok(str_contains($r['raw'], '417.00'));
        Harness::ok(!preg_match(Mask::KEY, $r['raw']));
        $meta = json_decode(
            $db
                ->query(
                    "SELECT meta FROM admin_audit WHERE action='export.orders' ORDER BY id DESC LIMIT 1",
                )
                ->fetchColumn(),
            true,
        );
        Harness::eq(1, $meta['redactions']);
    },
);
$h->test(
    'B3 key reveal is API-rate-limited and burst refusal is audited and alerts without leaking a key',
    function () use ($h, $api, $db): void {
        $jti = $h->call($api, 'POST', '/issue', [
            'email' => 'reveal-review@example.test',
            'plan' => 'pro',
        ])['body']['record']['jti'];
        for ($i = 0; $i < 10; $i++) {
            Harness::eq(200, $h->call($api, 'POST', '/admin/licences/' . $jti . '/reveal')['code']);
        }
        $r = $h->call($api, 'POST', '/admin/licences/' . $jti . '/reveal');
        Harness::eq(429, $r['code']);
        Harness::ok(!preg_match(Mask::KEY, $r['raw']));
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM ops_outbox WHERE kind='key.reveal_burst'")
                ->fetchColumn(),
        );
        Harness::eq(
            'rate_limited',
            $db
                ->query(
                    "SELECT result FROM admin_audit WHERE action='key.reveal' ORDER BY id DESC LIMIT 1",
                )
                ->fetchColumn(),
        );
    },
);
$h->test(
    'A2 backfill applies refunded fixture at modified_at, is idempotent and cleans dry-run copy on failure',
    function () use ($h, $api, $db): void {
        $jti = $h->call($api, 'POST', '/issue', [
            'email' => 'backfill-refund@example.test',
            'plan' => 'pro',
            'order_id' => 'refund-backfill',
        ])['body']['record']['jti'];
        $db->prepare("UPDATE licenses SET gateway='polar' WHERE jti=?")->execute([$jti]);
        $fake = $h->dir . '/refund-provider.php';
        file_put_contents(
            $fake,
            '<?php header("Content-Type: application/json");echo json_encode(["id"=>"refund-backfill","status"=>"refunded","currency"=>"usd","refunded_amount"=>1100,"refunded_tax_amount"=>100,"net_amount"=>1000,"total_amount"=>1100,"created_at"=>"2025-04-01T00:00:00Z","modified_at"=>"2025-05-01T00:00:00Z"]);',
        );
        $provider = $h->start($fake, ['PATH' => (string) getenv('PATH')]);
        mkdir($h->dir . '/tmp');
        $run = static function (bool $apply, int $port) use ($h): array {
            $args = [
                PHP_BINARY,
                dirname(__DIR__) . '/backfill-orders.php',
                '--api-base=http://127.0.0.1:' . $port,
                '--delay-ms=0',
            ];
            if ($apply) {
                $args[] = '--apply';
            }
            $p = proc_open(
                $args,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['TMPDIR' => $h->dir . '/tmp', 'POLAR_TOKEN' => 'fake-token'] + $h->env,
            );
            $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            foreach ($pipes as $f) {
                fclose($f);
            }
            return [proc_close($p), $raw];
        };
        $out = $run(false, 1);
        Harness::eq(1, $out[0]);
        Harness::eq([], glob($h->dir . '/tmp/ff-orders-*'));
        $out = $run(true, $provider);
        Harness::eq(0, $out[0], $out[1]);
        Harness::eq(
            strtotime('2025-05-01 UTC'),
            (int) $db
                ->query("SELECT refunded_at FROM orders WHERE order_id='refund-backfill'")
                ->fetchColumn(),
        );
        Harness::eq(0, $run(true, $provider)[0]);
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM order_refunds WHERE order_id='refund-backfill'")
                ->fetchColumn(),
        );
    },
);
$h->test(
    'B11/B12 subscription events join the licence timeline and reminder cron expires old PII',
    function () use ($h, $api, $db): void {
        $h->hook(
            $api,
            [
                'type' => 'order.paid',
                'data' => [
                    'id' => 'timeline-order',
                    'product_id' => 'pm',
                    'status' => 'paid',
                    'subscription_id' => 'timeline-sub',
                    'customer' => ['email' => 'timeline@example.test'],
                ],
            ],
            'timeline-paid',
        );
        $jti = $db
            ->query("SELECT jti FROM licenses WHERE subscription_id='timeline-sub'")
            ->fetchColumn();
        $h->hook(
            $api,
            [
                'type' => 'subscription.canceled',
                'data' => ['id' => 'timeline-sub', 'modified_at' => gmdate('c')],
            ],
            'timeline-cancel',
        );
        $r = $h->call($api, 'GET', '/admin/licences/' . $jti);
        $events = array_filter(
            $r['body']['timeline'],
            static fn(array $r): bool => ($r['item']['webhook_id'] ?? '') === 'timeline-cancel',
        );
        Harness::eq(1, count($events));
        $db->exec(
            "UPDATE webhook_events SET body=X'00',body_expires_at=1 WHERE webhook_id='timeline-cancel'",
        );
        $p = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/send-renewal-reminders.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $h->env,
        );
        foreach ($pipes as $f) {
            stream_get_contents($f);
            fclose($f);
        }
        Harness::eq(0, proc_close($p));
        Harness::eq(
            null,
            $db
                ->query("SELECT body FROM webhook_events WHERE webhook_id='timeline-cancel'")
                ->fetchColumn(),
        );
    },
);
$h->test(
    'B3 JWKS download aborts over 64 KiB while reading and accepts a bounded valid fixture',
    function () use ($h): void {
        $fake = $h->dir . '/keys-provider.php';
        file_put_contents(
            $fake,
            '<?php header("Content-Type: application/json");echo is_file(__DIR__."/large-keys")?json_encode(["keys"=>[["kid"=>"x"]],"padding"=>str_repeat("x",70000)]):json_encode(["keys"=>[["kid"=>"x"]]]);',
        );
        $port = $h->start($fake, ['PATH' => (string) getenv('PATH')]);
        Harness::ok(Access::downloadKeys('http://127.0.0.1:' . $port) !== null);
        file_put_contents($h->dir . '/large-keys', '1');
        Harness::eq(null, Access::downloadKeys('http://127.0.0.1:' . $port));
    },
);
$h->test(
    'B15 report preparation exceptions clear lease into failed, and mail failures back off',
    function () use ($h, $db): void {
        $api = $h->api([
            'FLUXFILES_OPS_EMAIL' => 'ops@example.test',
            'FLUXFILES_MAIL_TRANSPORT' => 'resend',
            'FLUXFILES_RESEND_API_KEY' => 'fake',
            'FLUXFILES_RESEND_API_BASE' => 'http://127.0.0.1:1',
        ]);
        $week = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))
            ->modify('monday this week')
            ->modify('-2 weeks')
            ->format('o-\WW');
        $db->exec('ALTER TABLE install_pings RENAME TO hidden_pings');
        try {
            $r = $h->call($api, 'POST', '/admin/ops/report/weekly', ['week' => $week], 'ops');
            Harness::eq(200, $r['code']);
            Harness::eq(false, $r['body']['ok']);
        } finally {
            $db->exec('ALTER TABLE hidden_pings RENAME TO install_pings');
        }
        $q = $db->prepare('SELECT value FROM ops_state WHERE key=?');
        $q->execute(['weekly:' . $week]);
        $state = json_decode($q->fetchColumn(), true);
        $q->closeCursor();
        Harness::eq('failed', $state['status']);
        Harness::ok($state['next_at'] >= time() + 55);
        $r = $h->call($api, 'POST', '/admin/ops/report/weekly', ['week' => $week], 'ops');
        Harness::ok(isset($r['body']['retry_at']));
        Harness::eq(
            0,
            (int) $db->query("SELECT COUNT(*) FROM mail_log WHERE kind='report'")->fetchColumn(),
        );
        $state['next_at'] = 1;
        $db->prepare('UPDATE ops_state SET value=? WHERE key=?')->execute([
            json_encode($state),
            'weekly:' . $week,
        ]);
        $r = $h->call($api, 'POST', '/admin/ops/report/weekly', ['week' => $week], 'ops');
        Harness::eq(false, $r['body']['ok']);
        $q->execute(['weekly:' . $week]);
        $state = json_decode($q->fetchColumn(), true);
        Harness::eq(2, $state['attempts']);
        Harness::ok($state['next_at'] >= time() + 115);
    },
);
$h->test(
    'B22 weekly report subject/body and five-line digest default to Vietnamese on the wire',
    function () use ($h, $db): void {
        $file = $h->dir . '/vi-report-provider.php';
        file_put_contents(
            $file,
            '<?php file_put_contents(__DIR__."/vi-report.json",file_get_contents("php://input"));header("Content-Type: application/json");echo "{\"id\":\"vi-report\"}";',
        );
        $port = $h->start($file, ['PATH' => (string) getenv('PATH')]);
        $api = $h->api([
            'FLUXFILES_OPS_EMAIL' => 'ops@example.test',
            'FLUXFILES_MAIL_TRANSPORT' => 'resend',
            'FLUXFILES_RESEND_API_KEY' => 'fake',
            'FLUXFILES_RESEND_API_BASE' => 'http://127.0.0.1:' . $port,
        ]);
        $week = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))
            ->modify('monday this week')
            ->modify('-3 weeks')
            ->format('o-\WW');
        $r = $h->call($api, 'POST', '/admin/ops/report/weekly', ['week' => $week], 'ops');
        Harness::eq(true, $r['body']['ok']);
        $wire = json_decode(file_get_contents($h->dir . '/vi-report.json'), true);
        Harness::ok(str_contains($wire['subject'], 'Báo cáo tuần FluxFiles'));
        Harness::ok(str_contains($wire['text'], 'Sao lưu thành công gần nhất'));
        $digest = $db
            ->query(
                "SELECT text FROM ops_outbox WHERE kind='report.weekly' ORDER BY id DESC LIMIT 1",
            )
            ->fetchColumn();
        Harness::ok(str_contains($digest, 'Khách hàng mới:'));
        Harness::eq(5, count(explode("\n", $digest)));
    },
);
$h->test(
    'B1/B3 UI host allowlist, host-only cookies and CSV same-origin guard are enforced over HTTP',
    function () use ($h, $api): void {
        require_once dirname(__DIR__) . '/admin/src/Auth.php';
        $a = new \FluxFiles\LicenseServer\Admin\Auth($h->dir . '/csv-admin.sqlite');
        $a->db
            ->prepare('INSERT INTO users(name,pass_hash,totp_secret,created_at) VALUES(?,?,?,?)')
            ->execute(['owner', 'unused', 'unused', time()]);
        $token = bin2hex(random_bytes(32));
        $a->db
            ->prepare('INSERT INTO sessions VALUES(?,?,?,?,?,?,?,?)')
            ->execute([hash('sha256', $token), 1, time(), time(), time(), 'csrf', 'vi', 'auto']);
        $ui = $h->start(dirname(__DIR__) . '/admin/public/index.php', [
            'PATH' => (string) getenv('PATH'),
            'FLUXFILES_ADMIN_DB' => $h->dir . '/csv-admin.sqlite',
            'FLUXFILES_ADMIN_API' => 'http://127.0.0.1:' . $api,
            'FLUXFILES_ADMIN_UI_TOKEN' => 'ui-secret',
            'FLUXFILES_ADMIN_HOSTS' => 'prod.localhost',
        ]);
        $headers = ['Host: prod.localhost', 'Cookie: fxadm=' . $token];
        Harness::eq(400, $h->request($ui, 'GET', '/login', null, ['Host: sbx.localhost'])['code']);
        $login = $h->request($ui, 'GET', '/login', null, ['Host: prod.localhost']);
        Harness::eq(200, $login['code']);
        Harness::ok(stripos($login['headers'], 'Domain=') === false);
        Harness::eq(403, $h->request($ui, 'GET', '/export/orders.csv', null, $headers)['code']);
        Harness::eq(
            403,
            $h->request(
                $ui,
                'GET',
                '/export/orders.csv',
                null,
                array_merge($headers, ['Sec-Fetch-Site: cross-site']),
            )['code'],
        );
        $r = $h->request(
            $ui,
            'GET',
            '/export/orders.csv',
            null,
            array_merge($headers, ['Sec-Fetch-Site: same-origin']),
        );
        Harness::eq(200, $r['code']);
        foreach (
            [
                'Content-Security-Policy:',
                'Cache-Control: no-store',
                'Content-Disposition: attachment',
            ]
            as $header
        ) {
            Harness::ok(stripos($r['headers'], $header) !== false);
        }
    },
);
$h->test('B3 HTTPS logout expires the host-only session with Secure still set', function () use (
    $h,
): void {
    $file = $h->dir . '/logout.php';
    file_put_contents(
        $file,
        '<?php require ' .
            var_export(dirname(__DIR__) . '/admin/src/Auth.php', true) .
            ';$_SERVER["HTTPS"]="on";$a=new \FluxFiles\LicenseServer\Admin\Auth(__DIR__."/logout.sqlite");$a->create();$a->logout();',
    );
    $port = $h->start($file, ['PATH' => (string) getenv('PATH')]);
    $r = $h->request($port, 'GET', '/');
    Harness::eq(200, $r['code']);
    preg_match_all('/^Set-Cookie: (.+)$/mi', $r['headers'], $cookies);
    Harness::eq(2, count($cookies[1]));
    Harness::ok(stripos($cookies[1][1], 'secure') !== false);
    Harness::ok(stripos($cookies[1][1], 'expires=') !== false);
});
$h->finish();
