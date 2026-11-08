<?php

declare(strict_types=1);

require_once __DIR__ . '/support/Harness.php';
require_once dirname(__DIR__) . '/ops/src/Counters.php';
use FluxFiles\LicenseServer\{LicenseStore};
use FluxFiles\LicenseServer\Client\Mask;
use FluxFiles\LicenseServer\Ops\Counters;

$h = new Harness();
$h->env['FLUXFILES_ADMIN_LANG'] = 'en';
$api = $h->api();
$s = new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);
$db = $s->pdo();
$providerFile = $h->dir . '/provider.php';
file_put_contents(
    $providerFile,
    <<<'FAKE'
    <?php
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$raw=file_get_contents('php://input');
    file_put_contents(__DIR__.'/provider-calls.jsonl',json_encode(['method'=>$_SERVER['REQUEST_METHOD'],'path'=>$path,'body'=>json_decode($raw,true),'idempotency'=>$_SERVER['HTTP_IDEMPOTENCY_KEY']??null])."\n",FILE_APPEND);
    header('Content-Type: application/json');
    if(is_file(__DIR__.'/provider-down')){http_response_code(503);echo '{"detail":"secret provider error"}';exit;}
    echo json_encode(['id'=>'fake-'.substr(hash('sha256',$raw),0,12)]);
    FAKE
    ,
);
$provider = $h->start($providerFile, ['PATH' => (string) getenv('PATH')]);
$h->test(
    'Replay requires retained verified unexpired Polar body, uses current mapping and issues idempotently',
    function () use ($h, $api, $db, $s): void {
        $body = [
            'type' => 'order.paid',
            'data' => [
                'id' => 'replay-order',
                'product_id' => 'missing-product',
                'status' => 'paid',
                'customer' => ['email' => 'replay@example.test'],
                'currency' => 'usd',
                'net_amount' => 1000,
                'total_amount' => 1000,
                'created_at' => gmdate('c'),
            ],
        ];
        Harness::eq(503, $h->hook($api, $body, 'replay-hook')['code']);
        $id = (int) $db
            ->query("SELECT id FROM webhook_events WHERE webhook_id='replay-hook'")
            ->fetchColumn();
        $read = $h->call($api, 'GET', '/admin/events/' . $id);
        Harness::eq(true, $read['body']['has_body']);
        Harness::ok(!array_key_exists('body', $read['body']));
        $fixed = $h->api(['FLUXFILES_POLAR_PLAN_MAP' => '{"missing-product":"pro"}']);
        $r = $h->call($fixed, 'POST', '/admin/events/' . $id . '/replay');
        Harness::eq(200, $r['code']);
        Harness::ok($s->findByOrder('polar', 'replay-order') !== null);
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM licenses WHERE order_id='replay-order'")
                ->fetchColumn(),
        );
        Harness::eq(
            'ui:owner',
            $db
                ->query(
                    "SELECT actor FROM admin_audit WHERE action='webhook.replay' ORDER BY id DESC LIMIT 1",
                )
                ->fetchColumn(),
        );
        Harness::eq(409, $h->call($fixed, 'POST', '/admin/events/' . $id . '/replay')['code']);
        $q = $db->prepare(
            "UPDATE webhook_events SET body=?,body_expires_at=?,verify='bad' WHERE id=?",
        );
        $q->execute([gzcompress(json_encode($body)), time() + 60, $id]);
        Harness::eq(409, $h->call($fixed, 'POST', '/admin/events/' . $id . '/replay')['code']);
        $db->prepare("UPDATE webhook_events SET verify='ok',body_expires_at=1 WHERE id=?")->execute(
            [$id],
        );
        Harness::eq(409, $h->call($fixed, 'POST', '/admin/events/' . $id . '/replay')['code']);
    },
);
$h->test(
    'Weekly report uses fake mail provider, stable idempotency, HTML/text, five-line masked digest and full backup status',
    function () use ($h, $provider, $db, $s): void {
        $backupAt = time();
        file_put_contents(
            $h->dir . '/backup.json',
            json_encode([
                'last_ok_at' => $backupAt,
                'file' => 'backup.sqlite',
                'bytes' => 1024,
                'integrity' => 'ok',
                'kept' => 30,
                'oldest_at' => time() - 86400,
                'last_error' => null,
            ]),
        );
        $sender = $h->api([
            'FLUXFILES_OPS_EMAIL' => 'ops@example.test',
            'FLUXFILES_MAIL_TRANSPORT' => 'resend',
            'FLUXFILES_RESEND_API_KEY' => 'fake-key',
            'FLUXFILES_RESEND_API_BASE' => 'http://127.0.0.1:' . $provider,
        ]);
        $week = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))
            ->modify('monday this week')
            ->modify('-1 week')
            ->format('o-\WW');
        $r = $h->call($sender, 'POST', '/admin/ops/report/weekly', ['week' => $week], 'ops');
        Harness::eq(200, $r['code']);
        Harness::eq(true, $r['body']['ok']);
        Harness::eq(
            true,
            $h->call($sender, 'POST', '/admin/ops/report/weekly', ['week' => $week], 'ops')['body'][
                'reused'
            ],
        );
        $calls = array_map(
            static fn(string $x): array => json_decode($x, true),
            file($h->dir . '/provider-calls.jsonl', FILE_IGNORE_NEW_LINES),
        );
        Harness::eq(1, count($calls));
        Harness::eq('weekly-' . $week, $calls[0]['idempotency']);
        Harness::ok(!empty($calls[0]['body']['html']), 'HTML absent');
        Harness::ok(!empty($calls[0]['body']['text']), 'text absent');
        Harness::ok(
            str_contains($calls[0]['body']['text'], 'Backup last OK: ' . gmdate('c', $backupAt)),
            'backup missing from text',
        );
        Harness::ok(!preg_match(Mask::KEY, json_encode($calls)));
        $digest = $db
            ->query("SELECT text FROM ops_outbox WHERE kind='report.weekly'")
            ->fetchColumn();
        Harness::eq(5, count(explode("\n", $digest)));
        Harness::ok(!str_contains($digest, 'replay@example.test'));
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM mail_log WHERE kind='report' AND ok=1")
                ->fetchColumn(),
        );
        Harness::eq(
            400,
            $h->call($sender, 'POST', '/admin/ops/report/weekly', ['week' => '2999-W01'], 'ops')[
                'code'
            ],
        );
        Harness::eq(
            30,
            $h->call($sender, 'GET', '/admin/ops/backup-status', [], 'ops')['body']['kept'],
        );
    },
);
$h->test(
    'Optional Polar cancel is off by default, typed and audited, sends scoped token only to selected fake endpoint',
    function () use ($h, $api, $provider, $db): void {
        $h->hook(
            $api,
            [
                'type' => 'order.paid',
                'data' => [
                    'id' => 'polar-action',
                    'product_id' => 'pm',
                    'status' => 'paid',
                    'subscription_id' => 'sub-action',
                    'customer' => ['email' => 'polar@example.test'],
                ],
            ],
            'action-order',
        );
        $body = ['polar_token' => 'fake-request-token-12345', 'confirmation' => 'sub-action'];
        Harness::eq(
            409,
            $h->call($api, 'POST', '/admin/subscriptions/sub-action/cancel', $body)['code'],
        );
        $enabled = $h->api([
            'FLUXFILES_ADMIN_POLAR_ACTIONS' => '1',
            'http_proxy' => 'http://127.0.0.1:1',
            'HTTP_PROXY' => 'http://127.0.0.1:1',
            'ALL_PROXY' => 'http://127.0.0.1:1',
            'NO_PROXY' => '',
            'no_proxy' => '',
            'FLUXFILES_POLAR_API_BASE' => 'http://127.0.0.1:' . $provider,
        ]);
        Harness::eq(
            400,
            $h->call(
                $enabled,
                'POST',
                '/admin/subscriptions/sub-action/cancel',
                array_replace($body, ['confirmation' => 'wrong']),
            )['code'],
        );
        Harness::eq(
            200,
            $h->call($enabled, 'POST', '/admin/subscriptions/sub-action/cancel', $body)['code'],
        );
        $calls = array_map(
            static fn(string $x): array => json_decode($x, true),
            file($h->dir . '/provider-calls.jsonl', FILE_IGNORE_NEW_LINES),
        );
        $last = end($calls);
        Harness::eq('PATCH', $last['method']);
        Harness::eq('/subscriptions/sub-action', $last['path']);
        Harness::eq(['cancel_at_period_end' => true], $last['body']);
        $audit = $db
            ->query(
                "SELECT * FROM admin_audit WHERE action='subscription.cancel' ORDER BY id DESC LIMIT 1",
            )
            ->fetch();
        Harness::eq('ui:owner', $audit['actor']);
        Harness::ok(!str_contains(json_encode($audit), $body['polar_token']));
        file_put_contents($h->dir . '/provider-down', '1');
        $r = $h->call($enabled, 'POST', '/admin/subscriptions/sub-action/cancel', $body);
        Harness::eq(502, $r['code']);
        Harness::ok(!str_contains($r['raw'], 'secret provider error'));
        unlink($h->dir . '/provider-down');
    },
);
$h->test(
    'System HEAD checks do not redirect, forward credentials or touch update-status; signing fingerprint matches',
    function () use ($h, $provider): void {
        $server = $h->api([
            'FLUXFILES_LICENSE_K1_PUB' => $h->public,
            'FLUXFILES_LANDING_ORIGIN' => 'http://127.0.0.1:' . $provider,
            'FLUXFILES_PRO_UPDATE_URL' => 'http://127.0.0.1:' . $provider,
        ]);
        $r = $h->call($server, 'GET', '/admin/system');
        Harness::eq(200, $r['code']);
        Harness::eq(true, $r['body']['signing']['matches']);
        foreach ($r['body']['site_checks'] as $check) {
            Harness::eq(true, $check['ok']);
        }
        $calls = array_map(
            static fn(string $x): array => json_decode($x, true),
            file($h->dir . '/provider-calls.jsonl', FILE_IGNORE_NEW_LINES),
        );
        foreach (array_slice($calls, -2) as $call) {
            Harness::eq('HEAD', $call['method']);
            Harness::eq('/', $call['path']);
        }
        $bad = $h->api([
            'FLUXFILES_PRO_UPDATE_URL' => 'http://127.0.0.1:' . $provider . '/update-status',
        ]);
        $r = $h->call($bad, 'GET', '/admin/system');
        Harness::eq(false, $r['body']['site_checks'][1]['ok']);
    },
);
$counterFile = $h->dir . '/counters.php';
file_put_contents(
    $counterFile,
    <<<'FAKE'
    <?php
    $source=explode('/',trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/'))[0];file_put_contents(__DIR__.'/counter-calls.jsonl',$source."\n",FILE_APPEND);header('Content-Type: application/json');
    if(is_file(__DIR__.'/counter-down')){http_response_code(503);echo '{"secret":"provider-body"}';exit;}
    if(is_file(__DIR__.'/counter-redirect')){http_response_code(302);header('Location: /never-follow');exit;}
    echo json_encode(match($source){'wporg'=>['active_installs'=>100,'downloaded'=>200],'github'=>['stargazers_count'=>3,'forks_count'=>1],'packagist'=>['downloads'=>['total'=>10,'monthly'=>2]],'npm'=>['downloads'=>9],default=>[]});
    FAKE
    ,
);
$counter = $h->start($counterFile, ['PATH' => (string) getenv('PATH')]);
$h->test(
    'Four counter response fixtures, bounded config, no redirect and no provider-body errors',
    function () use ($h, $counter): void {
        putenv('FLUXFILES_OPS_COUNTER_API_BASE=http://127.0.0.1:' . $counter);
        putenv(
            'FLUXFILES_OPS_PUBLIC_COUNTERS={"wporg":["fluxfiles"],"github":["owner/repo"],"packagist":["vendor/pkg"],"npm":["@scope/pkg"]}',
        );
        $c = new Counters();
        $rows = [];
        foreach ($c->targets() as $target) {
            $r = $c->fetch($target);
            Harness::eq(true, $r['ok']);
            $rows = array_merge($rows, $r['rows']);
        }
        Harness::eq(7, count($rows));
        file_put_contents($h->dir . '/counter-redirect', '1');
        Harness::eq(false, $c->fetch($c->targets()[0])['ok']);
        unlink($h->dir . '/counter-redirect');
        Harness::eq(5, count(file($h->dir . '/counter-calls.jsonl')));
        putenv(
            'FLUXFILES_OPS_PUBLIC_COUNTERS=' .
                json_encode([
                    'npm' => array_map(static fn(int $i): string => 'pkg' . $i, range(1, 21)),
                ]),
        );
        try {
            $c->targets();
            throw new RuntimeException('accepted twenty-one requests');
        } catch (InvalidArgumentException $e) {
        }
        putenv('FLUXFILES_OPS_PUBLIC_COUNTERS');
        putenv('FLUXFILES_OPS_COUNTER_API_BASE');
    },
);
$h->test(
    'Actual worker ingests counts-only inbox and polls once per day; failures retain previous values and record health',
    function () use ($h, $api, $counter, $db): void {
        $dir = $h->dir . '/ops';
        mkdir($dir);
        mkdir($dir . '/inbox');
        $day = gmdate('Y-m-d');
        file_put_contents(
            $dir . '/inbox/' . $day . '.json',
            json_encode([
                'day' => $day,
                'downloads' => [['module' => 'share', 'version' => '1.0.0', 'n' => 3]],
            ]),
        );
        file_put_contents($h->dir . '/counter-calls.jsonl', '');
        $env = [
            'PATH' => (string) getenv('PATH'),
            'FLUXFILES_ADMIN_API' => 'http://127.0.0.1:' . $api,
            'FLUXFILES_LICENSE_OPS_TOKEN' => 'ops-secret',
            'FLUXFILES_OPS_STATE_DIR' => $dir,
            'FLUXFILES_OPS_COUNTER_API_BASE' => 'http://127.0.0.1:' . $counter,
            'FLUXFILES_OPS_PUBLIC_COUNTERS' =>
                '{"wporg":["fluxfiles"],"github":["owner/repo"],"packagist":["vendor/pkg"],"npm":["@scope/pkg"]}',
        ];
        $run = static function () use ($env): void {
            $p = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/ops/ops-worker.php', 'run'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env,
            );
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            foreach ($pipes as $fp) {
                fclose($fp);
            }
            Harness::eq(0, proc_close($p), $out);
        };
        $run();
        Harness::eq(4, count(file($h->dir . '/counter-calls.jsonl')));
        Harness::eq(7, (int) $db->query('SELECT COUNT(*) FROM public_counters')->fetchColumn());
        Harness::ok(!file_exists($dir . '/inbox/' . $day . '.json'));
        Harness::eq(
            3,
            (int) $db->query("SELECT n FROM downloads_daily WHERE source='zip_log'")->fetchColumn(),
        );
        $run();
        Harness::eq(4, count(file($h->dir . '/counter-calls.jsonl')));
        $state = json_decode(file_get_contents($dir . '/state.json'), true);
        $state['counters']['day'] = '2000-01-01';
        file_put_contents($dir . '/state.json', json_encode($state));
        file_put_contents($h->dir . '/counter-down', '1');
        $run();
        Harness::eq(8, count(file($h->dir . '/counter-calls.jsonl')));
        Harness::eq(7, (int) $db->query('SELECT COUNT(*) FROM public_counters')->fetchColumn());
        Harness::eq(
            4,
            (int) $db
                ->query("SELECT COUNT(*) FROM health_checks WHERE target LIKE 'counter:%' AND ok=0")
                ->fetchColumn(),
        );
    },
);
$h->finish();
