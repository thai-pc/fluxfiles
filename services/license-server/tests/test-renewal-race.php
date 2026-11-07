<?php
/**
 * Two renewals of the same licence processed by two PHP processes at once must extend
 * twice and leave a consistent chain (BEGIN IMMEDIATE in LicenseIssuer).
 * Usage: php services/license-server/tests/test-renewal-race.php
 */
declare(strict_types=1);
$dir = dirname(__DIR__);
foreach (['LicenseSigner','LicenseStore','Plans','LicenseIssuer'] as $f) require_once "$dir/$f.php";
use FluxFiles\LicenseServer\{LicenseIssuer,LicenseSigner,LicenseStore};

if (($argv[1] ?? '') === 'child') {
    [, , $db, $sec, $order] = $argv;
    $iss = new LicenseIssuer(new LicenseSigner($sec), new LicenseStore($db));
    // Start together: wait for the go-file.
    while (!is_file($db . '.go')) { usleep(1000); }
    $r = $iss->issue(['email'=>'race@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>$order]);
    echo (int) $r['record']['expires'];
    exit;
}

$tmp = sys_get_temp_dir() . '/fxrace-' . bin2hex(random_bytes(4)); mkdir($tmp);
$db = "$tmp/l.sqlite";
$sec = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
$store = new LicenseStore($db);
$base = (new LicenseIssuer(new LicenseSigner($sec), $store))->issue(['email'=>'race@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'BASE'])['record'];
$procs = [];
foreach (['R1','R2'] as $o) {
    $procs[$o] = proc_open([PHP_BINARY, __FILE__, 'child', $db, $sec, $o], [1=>['pipe','w'],2=>['pipe','w']], $pipes[$o]);
}
usleep(300000); touch($db . '.go');
$exp = [];
foreach ($procs as $o => $pr) { $exp[$o] = (int) stream_get_contents($pipes[$o][1]); proc_close($pr); }

$fail = 0; $chk = function (bool $c, string $m) use (&$fail) { echo ($c ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m ") . $m . "\n"; if (!$c) $fail++; };
$e = array_values($exp); sort($e);
$chk($e[0] === (int) $base['expires'] + 365 * 86400 + 0 || abs($e[0] - ((int) $base['expires'] + 365 * 86400)) < 5, 'first renewal extends the base by 12 months');
$chk(abs($e[1] - ($e[0] + 365 * 86400)) < 5, 'second renewal extends the first (two payments = two years)');
$rows = []; foreach ($store->findByEmail('race@x.com') as $r) { $rows[$r['order_id']] = $r; }
$active = array_filter($rows, fn ($r) => $r['status'] === 'active');
$chk(count($active) === 1, 'exactly one active head of the chain');
$head = array_values($active)[0] ?? null;
$chk($head !== null && (int) $head['expires'] === $e[1], 'the head is the later expiry');
$chk($rows['BASE']['status'] === 'superseded', 'base superseded');
$chk(count($rows) === 3, 'three rows');
echo "\n"; exit($fail ? 1 : 0);
