<?php
/**
 * A missing/broken templates/email.php must never stop a mail path: the text mail still goes out,
 * every public mailer method returns a bool and none throws (a throw would 500 the Polar webhook,
 * and ten failures disable the endpoint).
 * Usage: php services/license-server/tests/test-mailer-fallback.php
 */
declare(strict_types=1);

$green="\033[32m";$red="\033[31m";$cyan="\033[36m";$reset="\033[0m";$p=0;$f=0;
function test(string $n, callable $fn): void { global $p,$f,$green,$red,$reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; } catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: ".$e->getMessage()."\n"; $f++; } }
function ok($c,$m=''){ if(!$c) throw new RuntimeException($m?:'expected true'); }

// A copy of the mailer WITHOUT templates/ (the bad-deploy state), run in a child process.
$dir = sys_get_temp_dir() . '/fxmf-' . bin2hex(random_bytes(4)); mkdir($dir);
register_shutdown_function(static function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir)); });
foreach (['LicenseMailer.php', 'ProDownload.php', 'Plans.php'] as $fl) { copy(dirname(__DIR__) . '/' . $fl, "$dir/$fl"); }
file_put_contents("$dir/run.php", '<?php
ini_set("error_log", ' . var_export("$dir/err.log", true) . ');
require ' . var_export("$dir/LicenseMailer.php", true) . ';
use FluxFiles\LicenseServer\LicenseMailer;
$m = new LicenseMailer("log");
$rec = ["jti"=>"j1","email"=>"buyer@example.test","plan"=>"pro-monthly","edition"=>"pro","modules"=>"share,intake","license_key"=>"KEY-ABC-123","expires"=>time()+86400*20,"grace_days"=>7,"enforcement"=>"subscription","issued"=>time()];
$out = [];
try { $out["license"] = $m->sendLicense($rec); } catch (\Throwable $e) { $out["license"] = "THROW"; }
try { $out["reminder"] = $m->sendReminder($rec, "7"); } catch (\Throwable $e) { $out["reminder"] = "THROW"; }
try { $out["grace"] = $m->sendReminder($rec, "grace"); } catch (\Throwable $e) { $out["grace"] = "THROW"; }
try { $out["unmatched"] = $m->sendRenewalUnmatched("buyer@example.test", "pro-renewal", "ord_1"); } catch (\Throwable $e) { $out["unmatched"] = "THROW"; }
echo json_encode($out);');
$res = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/run.php") . ' 2>/dev/null'), true);
$log = (string) @file_get_contents("$dir/err.log");

echo "\n{$cyan}══ Mailer fallback without templates/email.php ══{$reset}\n\n";
test('no mailer path throws or fatals; each returns true (text-only send)', function () use ($res) {
    ok(is_array($res), 'child ran to completion');
    foreach (['license', 'reminder', 'grace', 'unmatched'] as $k) { ok($res[$k] === true, "$k => " . json_encode($res[$k] ?? null)); }
});
test('the text mail still went out (key present) and a loud error was logged', function () use ($log) {
    ok(str_contains($log, 'KEY-ABC-123'), 'text part with the key reached the transport');
    ok(str_contains($log, 'LICENSE MAIL: HTML template failed'), 'loud log line');
});
test('with the template present the HTML part is still produced (fallback is not the normal path)', function () {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('ini_set("error_log","/dev/stderr"); require ' . var_export(dirname(__DIR__) . '/LicenseMailer.php', true) . '; $m=new FluxFiles\LicenseServer\LicenseMailer("log"); var_dump($m->sendLicense(["jti"=>"j","email"=>"a@example.test","plan"=>"pro","edition"=>"pro","modules"=>"share","license_key"=>"K","expires"=>time()+86400])); ') . ' 2>&1');
    ok(!str_contains((string) $out, 'HTML template failed') && str_contains((string) $out, 'bool(true)'), (string) $out);
});

echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
