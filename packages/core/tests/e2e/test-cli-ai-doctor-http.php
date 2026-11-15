<?php

declare(strict_types=1);

/**
 * `fluxfiles ai:test` and `fluxfiles doctor` (docs/design/CONNECTION-TEST-DESIGN.md §8) run
 * as real subprocesses against the fake AI provider fixture: exit codes, --json shape, and
 * the API key never reaching stdout/stderr. The provider binds an ephemeral port.
 */

$core = realpath(__DIR__ . '/../..');
$apiKey = 'sk-test-cli-secret-KEY1234567890';
$runId = bin2hex(random_bytes(6));
$tmp = sys_get_temp_dir() . '/ff-cli-ai-' . $runId;
mkdir($tmp . '/uploads', 0777, true);
mkdir($tmp . '/state', 0777, true);
$disksFile = $tmp . '/disks.php';
file_put_contents($disksFile, "<?php\nreturn ['local' => ['driver' => 'local', 'root' => " . var_export($tmp . '/uploads', true) . ", 'url' => '/storage/uploads']];\n");
$scenarioFile = $tmp . '/scenario';
file_put_contents($scenarioFile, 'ok');

$s = stream_socket_server('tcp://127.0.0.1:0');
$fakeAddr = stream_socket_get_name($s, false);
fclose($s);
$fake = proc_open([PHP_BINARY, '-S', $fakeAddr, __DIR__ . '/fixtures/fake-ai-provider.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p,
    __DIR__, array_merge(getenv(), ['FAKE_AI_SCENARIO_FILE' => $scenarioFile]));
register_shutdown_function(static function () use ($fake, $tmp): void {
    if (is_resource($fake)) { proc_terminate($fake); }
    exec('rm -rf ' . escapeshellarg($tmp));
});
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    $c = @stream_socket_client('tcp://' . $fakeAddr, $e, $m, .2);
    if ($c) { fclose($c); $up = true; } else { usleep(100000); }
}
if (!$up) {
    fwrite(STDERR, "fake provider did not start on $fakeAddr\n");
    exit(1);
}
// An empty .env in the cwd is the first match for the CLI's env lookup, so no developer .env is read.
touch($tmp . '/.env');

function cli(array $args, array $env): array
{
    global $core, $tmp, $disksFile;
    $base = array_merge(getenv(), [
        'FLUXFILES_SECRET' => str_repeat('cli-ai-doctor-secret-', 3),
        'FLUXFILES_DISKS_CONFIG' => $disksFile,
        'FLUXFILES_STORAGE_PATH' => $tmp . '/state',
        'FLUXFILES_AI_PROVIDER' => '', 'FLUXFILES_AI_API_KEY' => '',
        'FLUXFILES_AI_MODEL' => '', 'FLUXFILES_AI_BASE_URL' => '',
    ], $env);
    // cwd = temp dir holding an empty .env (first match wins): only the env above counts.
    $proc = proc_open(array_merge([PHP_BINARY, $core . '/bin/fluxfiles'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, $base);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($proc), $out, $err];
}
$aiEnv = fn(string $model = 'claude-sonnet-5-5') => [
    'FLUXFILES_AI_PROVIDER' => 'claude', 'FLUXFILES_AI_API_KEY' => $apiKey,
    'FLUXFILES_AI_MODEL' => $model, 'FLUXFILES_AI_BASE_URL' => 'http://' . $fakeAddr . '/v1',
];
function check(bool $ok, string $msg): void
{
    if (!$ok) { throw new RuntimeException($msg); }
}
$passed = $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}

test('ai:test ok -> exit 0', function () use ($aiEnv, $apiKey) {
    [$code, $out, $err] = cli(['ai:test'], $aiEnv());
    check($code === 0, "exit $code: $out$err");
    check(str_starts_with($out, 'OK '), $out);
    check(!str_contains($out . $err, $apiKey), 'key leaked');
});
test('ai:test --json shape', function () use ($aiEnv, $apiKey, $fakeAddr) {
    [$code, $out] = cli(['ai:test', '--json'], $aiEnv());
    $j = json_decode($out, true);
    check($code === 0 && is_array($j), $out);
    foreach (['ok', 'check', 'provider', 'model', 'verified', 'latency_ms', 'code', 'hint'] as $k) {
        check(array_key_exists($k, $j), "missing $k");
    }
    check($j['ok'] === true && $j['check'] === 'vision' && $j['provider'] === 'claude', $out);
    check(!str_contains($out, $apiKey) && !str_contains($out, $fakeAddr), 'key/base url leaked');
});
test('ai:test auth failure -> exit 2, key not echoed', function () use ($aiEnv, $apiKey, $scenarioFile) {
    file_put_contents($scenarioFile, 'badkey');
    [$code, $out, $err] = cli(['ai:test'], $aiEnv());
    check($code === 2, "exit $code: $out$err");
    check(str_contains($out, 'ai_auth'), $out);
    check(!str_contains($out . $err, $apiKey), 'key leaked');
    [$code, $out, $err] = cli(['ai:test', '--json'], $aiEnv());
    $j = json_decode($out, true);
    check($code === 2 && $j['ok'] === false && $j['code'] === 'ai_auth', $out);
    check(!str_contains($out . $err, $apiKey), 'key leaked in json');
    file_put_contents($scenarioFile, 'ok');
});
test('ai:test not configured -> exit 1', function () {
    [$code, $out] = cli(['ai:test', '--json'], []);
    $j = json_decode($out, true);
    check($code === 1 && ($j['code'] ?? '') === 'ai_not_configured', "exit $code: $out");
});
test('ai:test --cheap runs the model lookup', function () use ($aiEnv) {
    [$code, $out] = cli(['ai:test', '--cheap', '--json'], $aiEnv());
    $j = json_decode($out, true);
    check(is_array($j) && $j['check'] === 'model', $out);
    // The fake serves no /models route: a 404 there is "no objection", not a failure.
    check($code === 0 && $j['ok'] === true, "exit $code: $out");
});
test('ai:test network failure never names the base URL host', function () {
    foreach (['http://my-secret-gw.invalid:9/v1' => 'my-secret-gw', 'http://127.0.0.1:9/v1' => '127.0.0.1'] as $url => $needle) {
        [$code, $out, $err] = cli(['ai:test'], [
            'FLUXFILES_AI_PROVIDER' => 'compatible', 'FLUXFILES_AI_API_KEY' => 'k', 'FLUXFILES_AI_MODEL' => 'm',
            'FLUXFILES_AI_BASE_URL' => $url,
        ]);
        check($code === 2, "exit $code: $out$err");
        check(!str_contains($out . $err, $needle), "host leaked for $url: $out$err");
        [, $json, $jerr] = cli(['ai:test', '--json'], [
            'FLUXFILES_AI_PROVIDER' => 'compatible', 'FLUXFILES_AI_API_KEY' => 'k', 'FLUXFILES_AI_MODEL' => 'm',
            'FLUXFILES_AI_BASE_URL' => $url,
        ]);
        check(!str_contains($json . $jerr, $needle), "host leaked in json for $url");
    }
});
test('ai:test rejects unknown option -> exit 1', function () {
    [$code, , $err] = cli(['ai:test', '--bogus'], []);
    check($code === 1 && str_contains($err, 'Usage'), "exit $code");
});
test('doctor local -> exit 0 with checks', function () {
    [$code, $out, $err] = cli(['doctor', 'local'], []);
    check($code === 0 && str_contains($out, 'OK    write'), "exit $code: $out$err");
});
test('doctor --json shape', function () {
    [$code, $out] = cli(['doctor', '--json'], []);
    $j = json_decode($out, true);
    check($code === 0 && ($j['disk'] ?? '') === 'local' && ($j['summary'] ?? '') === 'ok' && !empty($j['checks']), $out);
});
test('doctor unknown disk -> exit 1', function () {
    [$code, , $err] = cli(['doctor', 'nope'], []);
    check($code === 1 && str_contains($err, 'not configured'), "exit $code: $err");
});
test('doctor disk that cannot be opened -> exit 1', function () use ($tmp) {
    // A root nested under a regular file can never be created, as any user.
    file_put_contents($tmp . '/afile', 'x');
    $bad = $tmp . '/bad-disks.php';
    file_put_contents($bad, "<?php\nreturn ['local' => ['driver' => 'local', 'root' => " . var_export($tmp . '/afile/sub', true) . ", 'url' => '/s']];\n");
    [$code, $out, $err] = cli(['doctor'], ['FLUXFILES_DISKS_CONFIG' => $bad]);
    check($code === 1 && str_contains($err, 'Doctor could not run'), "exit $code: $out$err");
});

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
