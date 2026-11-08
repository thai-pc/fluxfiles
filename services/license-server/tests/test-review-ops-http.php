<?php

declare(strict_types=1);

require_once __DIR__ . '/support/Harness.php';
require_once dirname(__DIR__) . '/ops/src/Worker.php';
require_once dirname(__DIR__) . '/collector/LogCollector.php';
use FluxFiles\LicenseServer\LicenseStore;
use FluxFiles\LicenseServer\Ops\{Worker, Telegram};
use FluxFiles\LicenseServer\Collector\LogCollector;

$h = new Harness();
$api = $h->api();
$s = new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);
$db = $s->pdo();
file_put_contents($h->dir . '/bot.token', '123456:fake_review_bot');
$fake = $h->dir . '/bot.php';
file_put_contents(
    $fake,
    <<<'BOT'
    <?php
    $method=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));$body=json_decode(file_get_contents('php://input'),true);file_put_contents(__DIR__.'/bot-calls.jsonl',json_encode(['method'=>$method,'body'=>$body])."\n",FILE_APPEND);header('Content-Type: application/json');
    if(is_file(__DIR__.'/bot-429')){http_response_code(429);echo '{"ok":false,"parameters":{"retry_after":120}}';exit;}
    echo json_encode(['ok'=>true,'result'=>$method==='getMe'?['username'=>'review_bot']:($method==='getUpdates'?[]:['message_id'=>1])]);
    BOT
    ,
);
$bot = $h->start($fake, ['PATH' => (string) getenv('PATH')]);
$env = [
    'PATH' => (string) getenv('PATH'),
    'FLUXFILES_ADMIN_API' => 'http://127.0.0.1:' . $api,
    'FLUXFILES_LICENSE_OPS_TOKEN' => 'ops-secret',
    'FLUXFILES_TELEGRAM_BOT_TOKEN_FILE' => $h->dir . '/bot.token',
    'FLUXFILES_TELEGRAM_CHAT_ID' => '42',
    'FLUXFILES_TELEGRAM_API_BASE' => 'http://127.0.0.1:' . $bot,
];
$run = static function (string $name, array $extra = []) use ($h, $env): array {
    $p = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/ops/ops-worker.php', 'run'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $extra + ['FLUXFILES_OPS_STATE_DIR' => $h->dir . '/' . $name] + $env,
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    foreach ($pipes as $f) {
        fclose($f);
    }
    return [proc_close($p), $out];
};
$calls = static function () use ($h): array {
    return array_map(
        static fn(string $r): array => json_decode($r, true),
        file($h->dir . '/bot-calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [],
    );
};
$clear = static function () use ($h, $db): void {
    $db->exec('DELETE FROM ops_outbox');
    file_put_contents($h->dir . '/bot-calls.jsonl', '');
};
$h->test(
    'A1 broken health JSON/target/target count cannot block queued critical delivery; heartbeat names failure',
    function () use ($h, $s, $run, $calls, $clear, $db): void {
        foreach (
            [
                'not-json',
                json_encode([['name' => 'bad target', 'url' => 'http://127.0.0.1:1']]),
                json_encode(
                    array_fill(0, 21, ['name' => 'too-many', 'url' => 'http://127.0.0.1:1']),
                ),
            ]
            as $i => $config
        ) {
            $clear();
            $s->enqueueAlert('test', 'crit', 'broken-health-' . $i, 'Critical ' . $i);
            $r = $run('bad-health-' . $i, ['FLUXFILES_OPS_HEALTH_TARGETS' => $config]);
            Harness::eq(1, $r[0]);
            Harness::ok(str_contains($r[1], 'health'));
            Harness::ok(!str_contains($r[1], 'bad target'));
            $sent = array_values(
                array_filter($calls(), static fn(array $r): bool => $r['method'] === 'sendMessage'),
            );
            Harness::eq(1, count($sent));
            $beat = json_decode(
                $db->query("SELECT value FROM ops_state WHERE key='heartbeat'")->fetchColumn(),
                true,
            );
            Harness::ok(in_array('health', $beat['stage_errors'], true));
        }
    },
);
$h->test(
    'A1 invalid individual target is skipped while a valid target is still probed',
    function () use ($h, $run, $clear, $db): void {
        $clear();
        $target = $h->dir . '/healthy.php';
        file_put_contents($target, '<?php echo "OK";');
        $port = $h->start($target, ['PATH' => (string) getenv('PATH')]);
        $r = $run('mixed-health', [
            'FLUXFILES_TELEGRAM_CHAT_ID' => '',
            'FLUXFILES_OPS_HEALTH_TARGETS' => json_encode([
                ['name' => 'invalid target', 'url' => 'http://127.0.0.1:1'],
                ['name' => 'review-good', 'url' => 'http://127.0.0.1:' . $port],
            ]),
        ]);
        Harness::eq(1, $r[0]);
        Harness::eq(
            1,
            (int) $db
                ->query("SELECT COUNT(*) FROM health_checks WHERE target='review-good' AND ok=1")
                ->fetchColumn(),
        );
    },
);
$h->test(
    'B13 Telegram 429 blocks every row and all command polling across later runs',
    function () use ($h, $run, $calls, $clear, $s): void {
        $clear();
        file_put_contents($h->dir . '/bot-429', '1');
        $s->enqueueAlert('test', 'crit', 'backoff-one', 'One');
        $s->enqueueAlert('test', 'crit', 'backoff-two', 'Two');
        $extra = ['FLUXFILES_TELEGRAM_COMMANDS' => '1'];
        Harness::eq(0, $run('global-backoff', $extra)[0]);
        Harness::eq(1, count($calls()));
        $state = json_decode(file_get_contents($h->dir . '/global-backoff/state.json'), true);
        Harness::ok($state['telegram_blocked_until'] >= time() + 115);
        unlink($h->dir . '/bot-429');
        Harness::eq(0, $run('global-backoff', $extra)[0]);
        Harness::eq(1, count($calls()));
    },
);
$h->test(
    'Sandbox end-to-end is off by default, explicit enable sends crit-only with tag and never polls commands',
    function () use ($h, $run, $calls, $clear, $s): void {
        $clear();
        $s->enqueueAlert('test', 'info', 'sbx-info', 'Info');
        $s->enqueueAlert('test', 'crit', 'sbx-crit', 'Critical');
        $extra = ['FLUXFILES_LICENSE_INSTANCE' => 'sandbox', 'FLUXFILES_TELEGRAM_COMMANDS' => '1'];
        Harness::eq(0, $run('sbx-default', $extra)[0]);
        Harness::eq([], $calls());
        Harness::eq(0, $run('sbx-enabled', ['FLUXFILES_TELEGRAM_SANDBOX' => '1'] + $extra)[0]);
        $c = $calls();
        Harness::eq(1, count($c));
        Harness::eq('sendMessage', $c[0]['method']);
        Harness::ok(str_contains($c[0]['body']['text'], 'SANDBOX'));
        Harness::ok(str_contains($c[0]['body']['text'], 'Critical'));
    },
);
$h->test(
    'B3/B22 default Vietnamese alert and suppression are sent with inherited proxies disabled',
    function () use ($s, $run, $calls, $clear): void {
        $clear();
        $s->enqueueAlert(
            'test',
            'crit',
            'vi-wire',
            \FluxFiles\LicenseServer\Client\OpsText::text('Admin test alert'),
        );
        $s->enqueueAlert(
            'test',
            'crit',
            'vi-wire',
            \FluxFiles\LicenseServer\Client\OpsText::text('Admin test alert'),
        );
        $r = $run('vi-wire', [
            'http_proxy' => 'http://127.0.0.1:1',
            'HTTP_PROXY' => 'http://127.0.0.1:1',
            'ALL_PROXY' => 'http://127.0.0.1:1',
            'NO_PROXY' => '',
            'no_proxy' => '',
        ]);
        Harness::eq(0, $r[0]);
        $wire = $calls()[0]['body']['text'];
        Harness::ok(str_contains($wire, 'Thông báo thử từ quản trị'));
        Harness::ok(str_contains($wire, '(+1 lần tương tự)'));
    },
);
$h->test(
    'Worker overlap lock prevents all requests and leaves persisted state intact',
    function () use ($h, $run, $calls, $clear): void {
        $clear();
        mkdir($h->dir . '/locked');
        $lock = fopen($h->dir . '/locked/worker.lock', 'c');
        flock($lock, LOCK_EX);
        file_put_contents($h->dir . '/locked/state.json', '{"sentinel":true}');
        $start = microtime(true);
        try {
            Harness::eq(0, $run('locked')[0]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        Harness::ok(microtime(true) - $start < 3);
        Harness::eq([], $calls());
        Harness::eq('{"sentinel":true}', file_get_contents($h->dir . '/locked/state.json'));
    },
);
$h->test(
    'Thread mismatch and foreign bot mentions are refused, including private chat',
    function () use ($h, $bot): void {
        putenv('FLUXFILES_TELEGRAM_CHAT_ID=42');
        putenv('FLUXFILES_TELEGRAM_THREAD_ID=7');
        $t = new Telegram();
        $message = [
            'chat' => ['id' => 42, 'type' => 'private'],
            'message_thread_id' => 8,
            'text' => '/stats',
        ];
        Harness::eq(null, $t->command($message, 'review_bot'));
        $message['message_thread_id'] = 7;
        $message['text'] = '/stats@otherbot';
        Harness::eq(null, $t->command($message, 'review_bot'));
        $message['text'] = '/stats@review_bot';
        Harness::eq('stats', $t->command($message, 'review_bot'));
        putenv('FLUXFILES_TELEGRAM_CHAT_ID');
        putenv('FLUXFILES_TELEGRAM_THREAD_ID');
    },
);
$h->test('Retry delays follow exponential backoff through exhaustion', function () use (
    $h,
    $api,
    $db,
    $s,
    $clear,
): void {
    $clear();
    $s->enqueueAlert('test', 'warn', 'retry-schedule', 'Retry');
    foreach ([60, 120, 240, 480, 960, 1920, 3600, 3600, 3600, 3600] as $i => $delay) {
        $db->exec('UPDATE ops_outbox SET next_at=1');
        $items = $h->call($api, 'POST', '/admin/ops/outbox/claim', [], 'ops')['body']['items'];
        Harness::eq(1, count($items));
        $before = time();
        $h->call(
            $api,
            'POST',
            '/admin/ops/outbox/ack',
            ['id' => $items[0]['id'], 'ok' => false],
            'ops',
        );
        $row = $db->query('SELECT * FROM ops_outbox')->fetch();
        Harness::ok((int) $row['next_at'] >= $before + $delay);
        Harness::ok((int) $row['next_at'] <= time() + $delay + 1);
        if ($i === 9) {
            Harness::ok($row['dropped_at'] !== null);
        }
    }
});
$h->test(
    'B16/collector CLI drops hostile overlong lines, refuses symlink output and writes only 0640 counts',
    function () use ($h): void {
        $day = gmdate('Y-m-d', time() - 86400);
        $time = (new DateTimeImmutable(
            $day . ' 12:00',
            new DateTimeZone('Asia/Ho_Chi_Minh'),
        ))->format('d/M/Y:H:i:s O');
        $good =
            '192.0.2.1 - - [' .
            $time .
            '] "GET /dl/m/share-1.2.3.zip?private=secret HTTP/1.1" 200 100';
        $file = $h->dir . '/collector.log';
        file_put_contents(
            $file,
            str_repeat('x', 20000) .
                $good .
                "\n" .
                'injected garbage ' .
                $good .
                "\n" .
                $good .
                "\n",
        );
        mkdir($h->dir . '/counts', 0750);
        $output = $h->dir . '/counts/' . $day . '.json';
        $run = static function () use ($file, $output, $day): int {
            $p = proc_open(
                [
                    PHP_BINARY,
                    dirname(__DIR__) . '/collect-ops.php',
                    '--day=' . $day,
                    '--update-log=' . $file,
                    '--output=' . $output,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['PATH' => (string) getenv('PATH')],
            );
            foreach ($pipes as $f) {
                stream_get_contents($f);
                fclose($f);
            }
            return proc_close($p);
        };
        Harness::eq(0, $run());
        $raw = file_get_contents($output);
        Harness::eq(1, json_decode($raw, true)['downloads'][0]['n']);
        Harness::eq(0640, fileperms($output) & 0777);
        Harness::ok(!str_contains($raw, 'secret'));
        Harness::ok(!str_contains($raw, '192.0.2.1'));
        unlink($output);
        symlink($file, $output);
        $before = hash_file('sha256', $file);
        Harness::eq(1, $run());
        Harness::eq($before, hash_file('sha256', $file));
    },
);
$h->test(
    'B16 health target cursor resumes skipped probes without advancing health_at prematurely',
    function () use ($h, $api): void {
        $file = $h->dir . '/rotate-health.php';
        file_put_contents(
            $file,
            '<?php if(str_contains($_SERVER["REQUEST_URI"],"slow")){usleep(2200000);}echo "OK";',
        );
        $port = $h->start($file, ['PATH' => (string) getenv('PATH')]);
        mkdir($h->dir . '/rotate');
        $env = [
            'FLUXFILES_ADMIN_API' => 'http://127.0.0.1:' . $api,
            'FLUXFILES_LICENSE_OPS_TOKEN' => 'ops-secret',
            'FLUXFILES_OPS_STATE_DIR' => $h->dir . '/rotate',
            'FLUXFILES_OPS_HEALTH_TARGETS' => json_encode([
                ['name' => 'rotate-slow', 'url' => 'http://127.0.0.1:' . $port . '/slow'],
                ['name' => 'rotate-fast', 'url' => 'http://127.0.0.1:' . $port . '/fast'],
            ]),
        ];
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
        }
        try {
            $worker = new Worker();
            $deadline = new ReflectionProperty(Worker::class, 'deadline');
            $deadline->setValue($worker, microtime(true) + 34);
            $method = new ReflectionMethod(Worker::class, 'health');
            $method->invoke($worker);
            $state = json_decode(file_get_contents($h->dir . '/rotate/state.json'), true);
            Harness::eq(1, $state['health_offset']);
            Harness::ok(!isset($state['health_at']));
            $deadline->setValue($worker, microtime(true) + 34);
            $method->invoke($worker);
            $state = json_decode(file_get_contents($h->dir . '/rotate/state.json'), true);
            Harness::eq(0, $state['health_offset']);
            Harness::ok($state['health_at'] > 0);
        } finally {
            foreach ($env as $key => $value) {
                putenv($key);
            }
        }
    },
);
$h->test(
    'Worker reserves request time and finishes stalled stages within the 55-second service limit',
    function () use ($h, $api, $run, $calls, $clear, $s): void {
        $clear();
        $s->enqueueAlert('test', 'crit', 'before-stall', 'Critical before stall');
        $proxy = $h->dir . '/slow-proxy.php';
        file_put_contents(
            $proxy,
            '<?php if(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH)==="/admin/ops/mail-jobs"){sleep(30);http_response_code(503);echo "{}";exit;}$c=curl_init("http://127.0.0.1:' .
                (int) $api .
                '".$_SERVER["REQUEST_URI"]);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$_SERVER["REQUEST_METHOD"],CURLOPT_POSTFIELDS=>file_get_contents("php://input"),CURLOPT_HTTPHEADER=>["Authorization: Bearer ops-secret","Content-Type: application/json"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>2]);$raw=curl_exec($c);http_response_code(curl_getinfo($c,CURLINFO_HTTP_CODE)?:503);echo $raw;',
        );
        $port = $h->start($proxy, ['PATH' => (string) getenv('PATH')]);
        $start = microtime(true);
        $r = $run('slow-stage', ['FLUXFILES_ADMIN_API' => 'http://127.0.0.1:' . $port]);
        $elapsed = microtime(true) - $start;
        Harness::eq(1, $r[0]);
        Harness::ok($elapsed >= 19 && $elapsed < 55, 'elapsed ' . $elapsed);
        Harness::ok(str_contains($r[1], 'mail_jobs'));
        Harness::eq('sendMessage', $calls()[0]['method']);
    },
);
$h->test('R3 worker uses API language even when its local language disagrees', function () use (
    $h,
    $s,
    $run,
    $calls,
    $clear,
): void {
    $clear();
    $api = $h->api(['FLUXFILES_ADMIN_LANG' => 'en']);
    $s->enqueueAlert('test', 'crit', 'language-r2', 'Admin test alert');
    $s->enqueueAlert('test', 'crit', 'language-r2', 'Admin test alert');
    $r = $run('language-r2', [
        'FLUXFILES_ADMIN_API' => 'http://127.0.0.1:' . $api,
        'FLUXFILES_ADMIN_LANG' => 'vi',
    ]);
    Harness::eq(0, $r[0]);
    Harness::ok(str_contains($calls()[0]['body']['text'], 'Admin test alert (+1 similar)'));
    Harness::ok(!str_contains($calls()[0]['body']['text'], 'tương tự'));
});
$h->test(
    'R2 review API 5xx in one stage preserves alert delivery and records stage name',
    function () use ($h, $api, $s, $run, $calls, $clear): void {
        $clear();
        $s->enqueueAlert('test', 'crit', '5xx-r2', 'Critical before API failure');
        $proxy = $h->dir . '/5xx-proxy.php';
        file_put_contents(
            $proxy,
            '<?php if(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH)==="/admin/ops/mail-jobs"){http_response_code(503);echo "{\"error\":\"provider_down\"}";exit;}$c=curl_init("http://127.0.0.1:' .
                $api .
                '".$_SERVER["REQUEST_URI"]);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$_SERVER["REQUEST_METHOD"],CURLOPT_POSTFIELDS=>file_get_contents("php://input"),CURLOPT_HTTPHEADER=>["Authorization: Bearer ops-secret","Content-Type: application/json"],CURLOPT_RETURNTRANSFER=>true]);$raw=curl_exec($c);http_response_code(curl_getinfo($c,CURLINFO_HTTP_CODE)?:503);echo $raw;',
        );
        $port = $h->start($proxy, ['PATH' => (string) getenv('PATH')]);
        $r = $run('5xx-r2', ['FLUXFILES_ADMIN_API' => 'http://127.0.0.1:' . $port]);
        Harness::eq(1, $r[0]);
        Harness::ok(str_contains($r[1], 'mail_jobs'));
        Harness::ok(!str_contains($r[1], 'provider_down'));
        Harness::eq('sendMessage', $calls()[0]['method']);
        Harness::ok(in_array('mail_jobs', $s->state('heartbeat')['stage_errors'], true));
    },
);
$h->finish();
