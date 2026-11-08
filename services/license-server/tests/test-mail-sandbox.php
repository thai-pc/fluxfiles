<?php
/**
 * Sandbox-instance mail guards: FLUXFILES_MAIL_SUBJECT_PREFIX, FLUXFILES_MAIL_ALLOW_TO and the
 * FLUXFILES_LICENSE_INSTANCE /health marker.
 * Usage: php services/license-server/tests/test-mail-sandbox.php
 */
declare(strict_types=1);

$green="\033[32m";$red="\033[31m";$cyan="\033[36m";$reset="\033[0m";$p=0;$f=0;
function test(string $n, callable $fn): void { global $p,$f,$green,$red,$reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; } catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: ".$e->getMessage()."\n"; $f++; } }
function ok($c,$m=''){ if(!$c) throw new RuntimeException($m?:'expected true'); }
function eq($e,$a,$m=''){ if($e!==$a) throw new RuntimeException(($m?"$m: ":'').'expected '.json_encode($e).' got '.json_encode($a)); }

require_once dirname(__DIR__) . '/LicenseMailer.php';
use FluxFiles\LicenseServer\LicenseMailer;

$dir = sys_get_temp_dir() . '/fxsbx-' . bin2hex(random_bytes(4)); mkdir($dir);
register_shutdown_function(static function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir)); });
$logf = "$dir/err.log"; ini_set('error_log', $logf);

/** Run $fn with the given env, return the error_log it produced. */
function logged(array $env, callable $fn): string {
    global $logf;
    @unlink($logf);
    $keys = ['FLUXFILES_MAIL_SUBJECT_PREFIX', 'FLUXFILES_MAIL_ALLOW_TO', 'FLUXFILES_OPS_EMAIL'];
    foreach ($keys as $k) { putenv($k); }
    foreach ($env as $k => $v) { putenv("$k=$v"); }
    try { $fn(); } finally { foreach ($keys as $k) { putenv($k); } }
    return (string) @file_get_contents($logf);
}
function sends(string $log): int { return substr_count($log, '[log transport]'); }
function rec(string $email, string $modules = 'share,intake'): array {
    return ['jti'=>'j1','email'=>$email,'plan'=>'pro-monthly','edition'=>'pro','modules'=>$modules,'license_key'=>'KEY-ABC-123','expires'=>time()+86400*20,'grace_days'=>7,'enforcement'=>'subscription','issued'=>time()];
}
$m = new LicenseMailer('log');

echo "\n{$cyan}══ Sandbox mail guards ══{$reset}\n\n";

test('no env: subjects untouched, everything sent', function () use ($m) {
    $l = logged([], fn () => $m->sendLicense(rec('a@example.test')));
    ok(str_contains($l, 'subject=Your FluxFiles licence key'), $l);
    eq(1, sends($l));
});
test('prefix is applied to licence, support, reminder, grace, unmatched-renewal and ops mails', function () use ($m) {
    $env = ['FLUXFILES_MAIL_SUBJECT_PREFIX' => '[SANDBOX]', 'FLUXFILES_OPS_EMAIL' => 'ops@example.test'];
    $l = logged($env, function () use ($m) {
        $m->sendLicense(rec('a@example.test'));
        $m->sendLicense(rec('a@example.test', ''));
        $m->sendReminder(rec('a@example.test'), '7');
        $m->sendReminder(rec('a@example.test'), 'grace');
        $m->sendRenewalUnmatched('a@example.test', 'pro-renewal', 'ord_1');
        $m->sendOpsNotice('Ops hello', 'body');
    });
    // licence, support, reminder, grace, unmatched (customer + ops), ops notice
    eq(7, sends($l), $l);
    eq(7, substr_count($l, 'subject=[SANDBOX] '), $l);
});
test('prefix: CR/LF and control chars stripped (no header injection), capped at 32 chars', function () use ($m) {
    $l = logged(['FLUXFILES_MAIL_SUBJECT_PREFIX' => "[X]\r\nBcc: evil@example.test\x00\x07"], fn () => $m->sendLicense(rec('a@example.test')));
    ok(str_contains($l, 'subject=[X]Bcc: evil@example.test Your FluxFiles licence key'), $l);
    ok(!str_contains($l, "\r") && !preg_match('/subject=[^\n]*\x00/', $l));
    $l = logged(['FLUXFILES_MAIL_SUBJECT_PREFIX' => str_repeat('A', 100)], fn () => $m->sendLicense(rec('a@example.test')));
    ok(str_contains($l, 'subject=' . str_repeat('A', 32) . ' Your'), $l);
    ok(!str_contains($l, str_repeat('A', 33)), 'capped');
    $l = logged(['FLUXFILES_MAIL_SUBJECT_PREFIX' => "\r\n  "], fn () => $m->sendLicense(rec('a@example.test')));
    ok(str_contains($l, 'subject=Your FluxFiles licence key'), 'a prefix that sanitises to nothing adds nothing: ' . $l);
});
test('allow-list: exact address and @domain suffix match, case-insensitive; others suppressed', function () use ($m) {
    $env = ['FLUXFILES_MAIL_ALLOW_TO' => ' Me@Example.test , @Team.example '];
    foreach (['me@example.test', 'ME@EXAMPLE.TEST', 'anyone@team.example', 'X@TEAM.EXAMPLE'] as $to) {
        $l = logged($env, fn () => $m->sendLicense(rec($to)));
        eq(1, sends($l), "$to allowed: $l");
    }
    foreach (['other@example.test', 'me@example.test.evil.com', 'x@sub.team.example', 'evilteam.example@x.test', 'x@footeam.example'] as $to) {
        $l = logged($env, function () use ($m, $to, &$ret) { $ret = $m->sendLicense(rec($to)); });
        eq(0, sends($l), "$to suppressed: $l");
        eq(true, $ret, 'suppression reports handled so mailed_at is stamped');
    }
});
test('suppressed mail: no transport call, log masks the address, covers all mail types', function () use ($m) {
    $env = ['FLUXFILES_MAIL_ALLOW_TO' => '@only.example', 'FLUXFILES_OPS_EMAIL' => 'ops@elsewhere.test'];
    $l = logged($env, function () use ($m) {
        $m->sendLicense(rec('secret.buyer@corp.test'));
        $m->sendReminder(rec('secret.buyer@corp.test'), '7');
        $m->sendRenewalUnmatched('secret.buyer@corp.test', 'pro-renewal', 'ord_1');
        $m->sendOpsNotice('Ops hello', 'body');
    });
    eq(0, sends($l), $l);
    eq(5, substr_count($l, 'mail suppressed by FLUXFILES_MAIL_ALLOW_TO (to='), $l);
    ok(str_contains($l, '(to=s***@corp.test)') && str_contains($l, '(to=o***@elsewhere.test)'), $l);
    ok(!str_contains($l, 'secret.buyer') && !str_contains($l, 'ops@elsewhere') && !str_contains($l, 'KEY-ABC-123'), 'no address or key in the log');
});
test('empty or whitespace-only allow-list means no restriction', function () use ($m) {
    foreach (['', '   '] as $v) {
        $l = logged(['FLUXFILES_MAIL_ALLOW_TO' => $v], fn () => $m->sendLicense(rec('anyone@anywhere.test')));
        eq(1, sends($l), "'$v': $l");
    }
});

// /health over a real server.php
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
$boot = function (array $extra) use ($dir, $port) {
    $srv = dirname(__DIR__) . '/server.php';
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $srv], [1 => ['file', "$dir/o.log", 'a'], 2 => ['file', "$dir/e.log", 'a']], $pp, dirname($srv), ['PATH' => getenv('PATH')] + $extra);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    $body = (string) @file_get_contents("http://127.0.0.1:$port/health");
    proc_terminate($proc); proc_close($proc);
    return $body;
};
test('/health body is exactly {"ok":true} without FLUXFILES_LICENSE_INSTANCE', function () use ($boot) {
    eq('{"ok":true}', $boot([]));
});
test('/health includes the instance when FLUXFILES_LICENSE_INSTANCE is set', function () use ($boot) {
    eq('{"ok":true,"instance":"sandbox"}', $boot(['FLUXFILES_LICENSE_INSTANCE' => 'sandbox']));
});

echo "\n  {$p} passed, {$f} failed\n";
exit($f > 0 ? 1 : 0);
