<?php

declare(strict_types=1);

require_once __DIR__ . '/support/Harness.php';
require_once dirname(__DIR__) . '/MachineAuth.php';
use FluxFiles\LicenseServer\Client\Mask;
use FluxFiles\LicenseServer\LicenseStore;

$h = new Harness();
$port = $h->api();
$db = null;
$jti = '';
$key = '';
$h->test('Four tokens, actor attribution, method and instance separation', function () use (
    $h,
    $port,
): void {
    Harness::eq(200, $h->call($port, 'GET', '/admin/meta')['code']);
    foreach (
        [
            '/issue',
            '/revoke',
            '/redeliver',
            '/admin/licences/x/reveal',
            '/licenses',
            '/update-status',
            '/admin/stats',
        ]
        as $path
    ) {
        Harness::eq(
            403,
            $h->call(
                $port,
                str_starts_with($path, '/admin/stats') ? 'GET' : 'POST',
                $path,
                [],
                'ops',
            )['code'],
            $path,
        );
    }
    Harness::eq(
        401,
        $h->call($port, 'POST', '/update-status', ['jti' => str_repeat('a', 24)])['code'],
    );
    Harness::eq(401, $h->call($port, 'GET', '/admin/stats', [], 'status')['code']);
    $sbx = $h->api([
        'FLUXFILES_LICENSE_INSTANCE' => 'sandbox',
        'FLUXFILES_LICENSE_DB' => $h->dir . '/sbx.sqlite',
        'FLUXFILES_LICENSE_UI_TOKEN' => 'other-ui',
        'FLUXFILES_LICENSE_OPS_TOKEN' => 'other-ops',
        'FLUXFILES_LICENSE_ADMIN_TOKEN' => 'other-admin',
        'FLUXFILES_UPDATE_STATUS_TOKEN' => 'other-status',
    ]);
    Harness::eq(401, $h->call($sbx, 'GET', '/admin/meta')['code']);
});
$h->test(
    'Manual issue persists flags and delivery, omits keys for UI; lazy signer for reads',
    function () use ($h, $port, &$db, &$jti, &$key): void {
        $r = $h->call($port, 'POST', '/issue', [
            'email' => 'a@example.test',
            'customer' => '<img src=x onerror=alert(1)>',
            'plan' => 'pro',
            'is_test' => true,
            'note' => 'operator',
            'deliver' => 'manual',
        ]);
        Harness::eq(200, $r['code']);
        Harness::ok(!preg_match(Mask::KEY, $r['raw']));
        $jti = $r['body']['record']['jti'];
        $db = $h->db();
        $row = $db->query('SELECT * FROM licenses')->fetch();
        $key = $row['license_key'];
        Harness::eq(1, (int) $row['is_test']);
        Harness::eq('operator', $row['note']);
        Harness::ok((int) $row['mailed_at'] > 0);
        Harness::eq(
            'ui:owner',
            $db->query("SELECT actor FROM admin_audit WHERE action='licence.issue'")->fetchColumn(),
        );
        $lazy = $h->api([
            'FLUXFILES_LICENSE_PRIVATE_KEY' => '',
            'FLUXFILES_LICENSE_ROLE' => 'status',
        ]);
        Harness::eq(
            200,
            $h->call($lazy, 'POST', '/update-status', ['jti' => $jti], 'status')['code'],
        );
        Harness::eq(404, $h->call($lazy, 'GET', '/admin/meta')['code']);
    },
);
$h->test(
    'Mail delivery records provider ID and manual backlog is never redelivered',
    function () use ($h, $port, &$db): void {
        $fake = $h->dir . '/resend.php';
        file_put_contents(
            $fake,
            '<?php header("Content-Type: application/json");echo json_encode(["id"=>"provider-mail-1"]);',
        );
        $mail = $h->start($fake, ['PATH' => (string) getenv('PATH')]);
        $sender = $h->api([
            'FLUXFILES_MAIL_TRANSPORT' => 'resend',
            'FLUXFILES_RESEND_API_KEY' => 'fake-resend-token',
            'FLUXFILES_RESEND_API_BASE' => 'http://127.0.0.1:' . $mail,
        ]);
        $r = $h->call($sender, 'POST', '/issue', [
            'email' => 'delivery@example.test',
            'plan' => 'pro',
            'deliver' => 'mail',
        ]);
        Harness::eq(200, $r['code']);
        Harness::eq(true, $r['body']['mailed']);
        $log = $db->query("SELECT * FROM mail_log WHERE provider_id='provider-mail-1'")->fetch();
        Harness::eq('accepted', $log['status']);
        Harness::eq('licence', $log['kind']);
        Harness::eq('d***@example.test', $log['recipient']);
        $h->call($port, 'POST', '/issue', [
            'email' => 'manual-only@example.test',
            'plan' => 'pro',
            'deliver' => 'none',
        ]);
        $out = $h->call($port, 'POST', '/redeliver', ['limit' => 10]);
        Harness::eq(0, $out['body']['sent']);
        Harness::eq(0, $out['body']['remaining']);
    },
);
$h->test(
    'No keys in reads, audited POST reveal and lookup, CLI ignores spoofed actor',
    function () use ($h, $port, &$jti, &$key, &$db): void {
        foreach (
            [
                '/admin/licences?test=all',
                '/admin/licences/' . $jti,
                '/admin/stats',
                '/admin/system',
                '/admin/audit',
            ]
            as $path
        ) {
            $r = $h->call($port, 'GET', $path);
            Harness::eq(200, $r['code'], $path);
            Harness::ok(!preg_match(Mask::KEY, $r['raw']), $path);
        }
        $r = $h->call($port, 'POST', "/admin/licences/$jti/reveal", ['reason' => $key]);
        Harness::eq($key, $r['body']['license_key']);
        Harness::ok(
            !str_contains(
                $db
                    ->query("SELECT reason FROM admin_audit WHERE action='key.reveal'")
                    ->fetchColumn(),
                $key,
            ),
        );
        Harness::eq(
            $jti,
            $h->call($port, 'POST', '/admin/lookup-key', ['license_key' => $key])['body']['jti'],
        );
        Harness::eq(404, $h->call($port, 'GET', '/admin/lookup-key')['code']);
        $h->call($port, 'POST', "/admin/licences/$jti/reveal", [], 'admin');
        Harness::eq(
            'cli',
            $db->query('SELECT actor FROM admin_audit ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
    },
);
$h->test(
    'Archive refuses active and real licences; cascade preview and history match',
    function () use ($h, $port, &$jti, &$db): void {
        Harness::eq(
            400,
            $h->call($port, 'POST', '/admin/licences/archive', ['jtis' => [$jti]])['code'],
        );
        $preview = $h->call($port, 'GET', "/admin/licences/$jti/cascade-preview");
        Harness::eq($jti, $preview['body']['affected'][0]['jti']);
        $h->call($port, 'POST', '/revoke', ['jti' => $jti]);
        Harness::eq(
            200,
            $h->call($port, 'POST', '/admin/licences/archive', ['jtis' => [$jti]])['code'],
        );
        $p = $h->call($port, 'POST', '/update-status', ['jti' => $jti], 'status');
        Harness::eq(false, $p['body']['active']);
        Harness::eq(
            'ui:owner',
            $db
                ->query(
                    "SELECT actor FROM license_history WHERE kind='status' ORDER BY id DESC LIMIT 1",
                )
                ->fetchColumn(),
        );
        $real = $h->call($port, 'POST', '/issue', [
            'email' => 'real@example.test',
            'plan' => 'pro',
        ])['body']['record']['jti'];
        $h->call($port, 'POST', '/revoke', ['jti' => $real]);
        Harness::eq(
            400,
            $h->call($port, 'POST', '/admin/licences/archive', ['jtis' => [$real]])['code'],
        );
    },
);
$order = [
    'type' => 'order.paid',
    'data' => [
        'id' => 'ord_paid',
        'product_id' => 'pm',
        'status' => 'paid',
        'checkout_id' => 'co-private-claim',
        'subscription_id' => 'sub_a',
        'billing_reason' => 'subscription_create',
        'customer' => ['email' => 'paid@example.test', 'name' => 'Buyer'],
        'currency' => 'usd',
        'subtotal_amount' => 2000,
        'discount_amount' => 800,
        'net_amount' => 1200,
        'tax_amount' => 120,
        'total_amount' => 1320,
        'discount' => ['code' => 'FOUNDING40'],
        'created_at' => gmdate('c'),
    ],
];
$h->test(
    'Verified webhook upsert, orders despite unmapped products, failure body retention, bad-signature aggregation',
    function () use ($h, $port, &$db, $order): void {
        $r = $h->hook($port, $order);
        Harness::eq(200, $r['code']);
        Harness::ok($r['body']['issued'] ?? false, json_encode($r['body']));
        $r = $h->hook($port, $order);
        Harness::eq(false, $r['body']['issued']);
        Harness::eq(
            2,
            (int) $db
                ->query("SELECT attempts FROM webhook_events WHERE webhook_id='msg-1'")
                ->fetchColumn(),
        );
        $o = $db->query("SELECT * FROM orders WHERE order_id='ord_paid'")->fetch();
        Harness::eq(1200, (int) $o['net_amount']);
        Harness::eq('FOUNDING40', $o['discount_code']);
        Harness::ok($o['jti'] !== null);
        $bad = $order;
        $bad['data']['id'] = 'ord_unmapped';
        $bad['data']['product_id'] = 'unmapped';
        Harness::eq(503, $h->hook($port, $bad, 'msg-unmapped')['code']);
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM orders WHERE order_id='ord_unmapped'")
                ->fetchColumn(),
        );
        Harness::ok(
            $db
                ->query("SELECT body FROM webhook_events WHERE webhook_id='msg-unmapped'")
                ->fetchColumn() !== null,
        );
        for ($i = 0; $i < 2; $i++) {
            Harness::eq(401, $h->request($port, 'POST', '/webhook/polar', $order)['code']);
        }
        $bad = $db->query("SELECT * FROM webhook_events WHERE verify!='ok'")->fetch();
        Harness::eq(2, (int) $bad['attempts']);
        Harness::eq(null, $bad['body']);
        Harness::eq(null, $bad['order_id']);
    },
);
$h->test(
    'Partial and full refund update accounting without paid retry erasing refund',
    function () use ($h, $port, &$db, $order): void {
        $refund = $order;
        $refund['type'] = 'order.refunded';
        $refund['data']['status'] = 'partially_refunded';
        $refund['data']['refunded_amount'] = 220;
        $refund['data']['refunded_tax_amount'] = 20;
        Harness::eq(200, $h->hook($port, $refund, 'refund-partial')['code']);
        Harness::eq(
            'active',
            $db->query("SELECT status FROM licenses WHERE order_id='ord_paid'")->fetchColumn(),
        );
        Harness::eq(
            220,
            (int) $db
                ->query("SELECT refunded_amount FROM orders WHERE order_id='ord_paid'")
                ->fetchColumn(),
        );
        $refund['data']['status'] = 'refunded';
        $refund['data']['refunded_amount'] = 1320;
        $refund['data']['refunded_tax_amount'] = 120;
        $h->hook($port, $refund, 'refund-full');
        $h->hook($port, $order, 'paid-late');
        $r = $db->query("SELECT * FROM orders WHERE order_id='ord_paid'")->fetch();
        Harness::eq('refunded', $r['status']);
        Harness::eq(1320, (int) $r['refunded_amount']);
    },
);
$h->test(
    'Telemetry response byte parity, strict fields, daily HMAC aggregation, no raw prefix, contention under one second',
    function () use ($h, $port, &$jti, &$db): void {
        $pepper = $h->dir . '/pepper';
        file_put_contents($pepper, str_repeat('p', 32));
        $pingPort = $h->api(['FLUXFILES_LICENSE_NET_PEPPER_FILE' => $pepper]);
        $a = $h->call($pingPort, 'POST', '/update-status', ['jti' => $jti], 'status');
        $data = [
            'jti' => $jti,
            'module' => 'share',
            'current' => '1.2.3',
            'core_version' => '1.2.4',
            'install' => true,
            'net' => '203.0.113.0/24',
        ];
        $b = $h->call($pingPort, 'POST', '/update-status', $data, 'status');
        Harness::eq($a['raw'], $b['raw']);
        $h->call($pingPort, 'POST', '/update-status', $data, 'status');
        $p = $db->query("SELECT * FROM install_pings WHERE module='share'")->fetch();
        Harness::eq(2, (int) $p['n']);
        Harness::eq(
            substr(hash_hmac('sha256', '203.0.113.0/24', str_repeat('p', 32)), 0, 16),
            $p['net'],
        );
        Harness::eq(
            $a['raw'],
            $h->call(
                $port,
                'POST',
                '/update-status',
                ['jti' => $jti, 'module' => '<script>', 'net' => 'bad', 'current' => '<img>'],
                'status',
            )['raw'],
        );
        // Reading/closing the live file directly can release SQLite's POSIX locks
        // on Linux. Inspect a consistent snapshot that has no open DB connection.
        $snapshot = $h->dir . '/telemetry-snapshot.sqlite';
        $db->prepare('VACUUM INTO ?')->execute([$snapshot]);
        Harness::ok(!str_contains(file_get_contents($snapshot), '203.0.113.0/24'));
        Harness::eq('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
        $db->exec('BEGIN IMMEDIATE');
        $start = microtime(true);
        try {
            $r = $h->call($pingPort, 'POST', '/update-status', $data, 'status');
        } finally {
            $db->exec('ROLLBACK');
        }
        Harness::eq(200, $r['code']);
        Harness::ok(microtime(true) - $start < 1, 'latency');
    },
);
$h->test(
    'Resend Svix delivery updates, bounce alerts, recording failures preserve webhook response',
    function () use ($h, $port, &$db, $order): void {
        $db->prepare(
            "INSERT INTO mail_log(at,kind,recipient,transport,ok,provider_id,status,actor) VALUES(?,'licence','p***@example.test','resend',1,'mail-1','accepted','system')",
        )->execute([time() - 10]);
        $body = [
            'type' => 'email.bounced',
            'created_at' => gmdate('c'),
            'data' => ['email_id' => 'mail-1'],
        ];
        Harness::eq(200, $h->hook($port, $body, 'resend-1', 'resend')['code']);
        Harness::eq(
            'bounced',
            $db->query("SELECT status FROM mail_log WHERE provider_id='mail-1'")->fetchColumn(),
        );
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM ops_outbox WHERE kind='mail.bounced'")
                ->fetchColumn(),
        );
        $db->exec(
            "CREATE TRIGGER recording_fail BEFORE INSERT ON webhook_events BEGIN SELECT RAISE(FAIL,'fixture failure'); END",
        );
        try {
            Harness::eq(
                200,
                $h->hook($port, ['type' => 'ignored', 'data' => []], 'record-failure')['code'],
            );
        } finally {
            $db->exec('DROP TRIGGER recording_fail');
        }
        $db->exec('UPDATE webhook_events SET body_expires_at=1 WHERE body IS NOT NULL');
        $s = new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);
        $s->prune();
        Harness::eq(
            0,
            (int) $db
                ->query('SELECT COUNT(*) FROM webhook_events WHERE body IS NOT NULL')
                ->fetchColumn(),
        );
    },
);
$h->test(
    'Cascade preview, descendants and truthful history agree; restore does not revive renewals',
    function () use ($h, $port, &$db): void {
        $base = $h->call($port, 'POST', '/issue', [
            'email' => 'chain@example.test',
            'plan' => 'pro-lifetime',
        ])['body']['record']['jti'];
        $r1 = $h->call($port, 'POST', '/issue', [
            'email' => 'chain@example.test',
            'plan' => 'pro-renewal',
        ])['body']['record']['jti'];
        $r2 = $h->call($port, 'POST', '/issue', [
            'email' => 'chain@example.test',
            'plan' => 'pro-renewal',
        ])['body']['record']['jti'];
        $preview = $h->call($port, 'GET', "/admin/licences/$base/cascade-preview")['body'][
            'affected'
        ];
        Harness::eq([$base, $r1, $r2], array_column($preview, 'jti'));
        $result = $h->call($port, 'POST', '/revoke', ['jti' => $base])['body'];
        Harness::eq([$r1, $r2], array_column($result['cascaded'], 'jti'));
        $q = $db->prepare(
            "SELECT actor,ref,kind FROM license_history WHERE kind='cascade' AND ref=?",
        );
        $q->execute([$base]);
        $history = $q->fetchAll();
        Harness::eq(2, count($history));
        Harness::eq('ui:owner', $history[0]['actor']);
        $h->call($port, 'POST', '/revoke', ['jti' => $base, 'status' => 'active']);
        $q = $db->prepare('SELECT status FROM licenses WHERE jti=?');
        $q->execute([$r2]);
        Harness::eq('revoked', $q->fetchColumn());
    },
);
$h->test('Outbox dedup, leases, retries, attempt exhaustion and key refusal', function () use (
    $h,
    $port,
    &$db,
): void {
    $db->exec('DELETE FROM ops_outbox');
    $a = ['kind' => 'test', 'severity' => 'warn', 'dedup_key' => 'same', 'text' => 'safe'];
    $h->call($port, 'POST', '/admin/ops/alert', $a, 'ops');
    $h->call($port, 'POST', '/admin/ops/alert', $a, 'ops');
    Harness::eq(1, (int) $db->query('SELECT suppressed FROM ops_outbox')->fetchColumn());
    $claim = $h->call($port, 'POST', '/admin/ops/outbox/claim', [], 'ops')['body']['items'];
    Harness::eq(1, count($claim));
    Harness::eq([], $h->call($port, 'POST', '/admin/ops/outbox/claim', [], 'ops')['body']['items']);
    $h->call(
        $port,
        'POST',
        '/admin/ops/outbox/ack',
        ['id' => $claim[0]['id'], 'ok' => false, 'retry_after' => 123],
        'ops',
    );
    Harness::ok((int) $db->query('SELECT next_at FROM ops_outbox')->fetchColumn() >= time() + 120);
    $db->exec('UPDATE ops_outbox SET attempts=9,next_at=1');
    $h->call($port, 'POST', '/admin/ops/outbox/claim', [], 'ops');
    $h->call(
        $port,
        'POST',
        '/admin/ops/outbox/ack',
        ['id' => $claim[0]['id'], 'ok' => false],
        'ops',
    );
    Harness::ok($db->query('SELECT dropped_at FROM ops_outbox')->fetchColumn() !== null);
    $a['text'] = str_repeat('a', 20) . '.' . str_repeat('b', 20) . '.' . str_repeat('c', 20);
    Harness::eq(400, $h->call($port, 'POST', '/admin/ops/alert', $a, 'ops')['code']);
});
$h->test(
    'Lease and retention comparisons use integers; fresh rows survive and lifetime dedup persists',
    function () use ($h, $port, &$db): void {
        $db->exec('DELETE FROM ops_outbox');
        $s = new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);
        for ($i = 0; $i < 21; $i++) {
            $s->enqueueAlert('test', 'info', 'lease-' . $i, 'Lease ' . $i);
        }
        $last = (int) $db->query('SELECT MAX(id) FROM ops_outbox')->fetchColumn();
        $db->prepare('UPDATE ops_outbox SET lease_until=? WHERE id=?')->execute([
            time() + 120,
            $last,
        ]);
        $items = $h->call($port, 'POST', '/admin/ops/outbox/claim', [], 'ops')['body']['items'];
        Harness::eq(20, count($items));
        Harness::ok(!in_array('budget.digest', array_column($items, 'kind'), true));
        $q = $db->prepare('SELECT dropped_at FROM ops_outbox WHERE id=?');
        $q->execute([$last]);
        Harness::eq(null, $q->fetchColumn());
        $q->closeCursor();
        $db->exec('DELETE FROM ops_outbox');
        $s->enqueueAlert('test', 'info', 'fresh-sent', 'Fresh sent');
        $s->enqueueAlert('order.new', 'info', 'forever-order', 'Old sent');
        $db->prepare("UPDATE ops_outbox SET sent_at=? WHERE dedup_key='fresh-sent'")->execute([
            time(),
        ]);
        $db->prepare(
            "UPDATE ops_outbox SET created_at=?,sent_at=? WHERE dedup_key='forever-order'",
        )->execute([time() - 32 * 86400, time() - 31 * 86400]);
        $s->prune();
        Harness::eq(1, (int) $db->query('SELECT COUNT(*) FROM ops_outbox')->fetchColumn());
        $s->enqueueAlert('order.new', 'info', 'forever-order', 'Should stay deduplicated');
        Harness::eq(1, (int) $db->query('SELECT COUNT(*) FROM ops_outbox')->fetchColumn());
    },
);
$h->finish();
