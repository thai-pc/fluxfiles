<?php
/**
 * POST /refresh and backfill-subscription-ids.php over real HTTP (php -S, throwaway key + DB):
 *  - a monthly chain refreshes old key -> newest; `current` when already newest
 *  - every refusal (revoked/refunded chain, support-only, stale, unknown, key mismatch,
 *    ambiguous email+plan) is one byte-identical 404; a bad signature is 401
 *  - the key is Bearer-only (query string ignored), never reaches the server log
 *  - per-licence and per-IP throttles answer 429 + Retry-After
 *  - edition upgrade followed; an older / earlier-expiring key is never returned
 *  - the server boots on a pre-existing (old-schema) DB and migrates it
 *  - /revoke {subscription_id} stops refreshing
 *  - the backfill (dry run vs --apply, token never printed) against a fake Polar
 * Usage: php services/license-server/tests/test-refresh-http.php
 */
declare(strict_types=1);

$green="\033[32m";$red="\033[31m";$cyan="\033[36m";$reset="\033[0m";$p=0;$f=0;
function test(string $n, callable $fn): void { global $p,$f,$green,$red,$reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; } catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: ".$e->getMessage()."\n"; $f++; } }
function eq($e,$a,$m=''){ if($e!==$a) throw new RuntimeException(($m?"$m: ":'').'expected '.json_encode($e).' got '.json_encode($a)); }
function ok($c,$m=''){ if(!$c) throw new RuntimeException($m?:'expected true'); }

if (!function_exists('sodium_crypto_sign_keypair')) { fwrite(STDERR, "skip: no sodium\n"); exit(0); }
require_once dirname(__DIR__) . '/LicenseSigner.php';
use FluxFiles\LicenseServer\LicenseSigner;

$dir = sys_get_temp_dir() . '/fxrf-' . bin2hex(random_bytes(4)); mkdir($dir);
register_shutdown_function(static function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir)); });
$kp = sodium_crypto_sign_keypair(); $sec = base64_encode(sodium_crypto_sign_secretkey($kp));
$signer = new LicenseSigner($sec);
$port = static function (): int { $s = stream_socket_server('tcp://127.0.0.1:0'); $n = (int) substr(strrchr((string) stream_socket_get_name($s, false), ':'), 1); fclose($s); return $n; };
[$pSrv, $pPolar] = [$port(), $port()];
$srv = dirname(__DIR__) . '/server.php';
$WH = 'test-webhook-secret-0123456789abcdef';
$base = ['FLUXFILES_LICENSE_PRIVATE_KEY' => $sec, 'FLUXFILES_LICENSE_DB' => "$dir/l.sqlite", 'FLUXFILES_LICENSE_ADMIN_TOKEN' => 'adm-tok',
    'FLUXFILES_POLAR_WEBHOOK_SECRET' => $WH, 'FLUXFILES_MAIL_TRANSPORT' => 'log', 'PATH' => getenv('PATH'),
    'FLUXFILES_POLAR_PLAN_MAP' => json_encode(['prod_pm' => 'pro-monthly', 'prod_sm' => 'studio-monthly', 'prod_sup' => 'support-monthly'])];

// A database created by an OLD server version (no subscription columns), holding a legacy
// Polar monthly row whose key the old server really signed.
$legacy = $signer->mint(['edition' => 'pro', 'modules' => ['share', 'intake'], 'enforcement' => 'subscription', 'ttlDays' => 31, 'graceDays' => 7, 'issuedAtLeast' => time() - 40 * 86400]);
$old = new PDO("sqlite:$dir/l.sqlite");
$old->exec("CREATE TABLE licenses (id INTEGER PRIMARY KEY AUTOINCREMENT, jti TEXT UNIQUE NOT NULL, email TEXT NOT NULL, customer TEXT, plan TEXT, edition TEXT, modules TEXT, enforcement TEXT, issued INTEGER, expires INTEGER, license_key TEXT NOT NULL, gateway TEXT, order_id TEXT, status TEXT DEFAULT 'active', created_at INTEGER)");
$old->prepare("INSERT INTO licenses (jti,email,customer,plan,edition,modules,enforcement,issued,expires,license_key,gateway,order_id,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$legacy['payload']['jti'], 'legacy@example.test', 'L', 'pro-monthly', 'pro', 'share,intake', 'subscription', $legacy['payload']['issued'], time() + 3 * 86400, $legacy['key'], 'polar', 'ord-legacy-0001', 'active', time() - 40 * 86400]);
$old = null;

$procs = [];
$start = function (int $p, string $script, array $env) use (&$procs, $dir): void {
    $procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$p", $script], [1 => ['file', "$dir/out$p.log", 'a'], 2 => ['file', "$dir/err$p.log", 'a']], $pp, dirname($script), $env + ['PHP_CLI_SERVER_WORKERS' => '2']);
    for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $p); $i++) { usleep(100000); }
};
register_shutdown_function(static function () use (&$procs) { foreach ($procs as $pr) { proc_terminate($pr); } });
$start($pSrv, $srv, $base);

function req(int $port, string $m, string $path, ?string $body = null, array $h = [], ?string &$hdrs = null) {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
    $r = (string) curl_exec($ch); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $hdrs = substr($r, 0, $hs); $raw = substr($r, $hs);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true), $raw];
}
$ADM = ['Authorization: Bearer adm-tok', 'Content-Type: application/json'];
$refresh = fn (string $key, string $q = '', ?string &$h = null) => req($pSrv, 'POST', '/refresh' . $q, '{"v":1,"client":"core/1.2.0"}', ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], $h);
$db = null;
$ordN = 0;
$hook = function (string $product, string $email, string $sub, string $reason = 'subscription_cycle', string $cus = 'cus1') use ($pSrv, $WH, &$ordN, &$db, $dir): array {
    $ordN++; $oid = "ord_rf_{$ordN}_0123456789";
    $data = ['id' => $oid, 'status' => 'paid', 'product_id' => $product, 'checkout_id' => "co_rf_$ordN" . '_0000', 'customer_id' => $cus, 'billing_reason' => $reason,
        'customer' => ['id' => $cus, 'email' => $email, 'name' => 'T']] + ($sub !== '' ? ['subscription_id' => $sub] : []);
    $raw = json_encode(['type' => 'order.paid', 'data' => $data]);
    $id = 'msg_' . bin2hex(random_bytes(6)); $ts = (string) time(); $sig = base64_encode(hash_hmac('sha256', "$id.$ts.$raw", $WH, true));
    [$c, $b] = req($pSrv, 'POST', '/webhook/polar', $raw, ["webhook-id: $id", "webhook-timestamp: $ts", "webhook-signature: v1,$sig", 'Content-Type: application/json']);
    if ($c !== 200) { throw new RuntimeException("webhook $c " . json_encode($b)); }
    $db ??= new PDO("sqlite:$dir/l.sqlite");
    $r = $db->query("SELECT jti, license_key, issued, expires, order_id FROM licenses WHERE jti = " . $db->quote((string) $b['jti']))->fetch(PDO::FETCH_ASSOC);
    return $r + ['sub' => $sub];
};
$notAvailable = '{"error":"not available","hint":"paste the key from your licence e-mail"}';

echo "\n{$cyan}══ POST /refresh (HTTP) ══{$reset}\n\n";
req($pSrv, 'GET', '/licenses', null, $ADM);   // first touch migrates the old DB

test('the server migrates a pre-existing old-schema DB; the legacy row survives untouched', function () use ($dir, $legacy) {
    $c = new PDO("sqlite:$dir/l.sqlite");
    $cols = $c->query('PRAGMA table_info(licenses)')->fetchAll(PDO::FETCH_COLUMN, 1);
    ok(in_array('subscription_id', $cols, true) && in_array('billing_reason', $cols, true), 'columns added');
    eq(1, (int) $c->query("SELECT COUNT(*) FROM sqlite_master WHERE name='idx_subscription'")->fetchColumn(), 'index');
    eq(null, $c->query("SELECT subscription_id FROM licenses WHERE jti='{$legacy['payload']['jti']}'")->fetchColumn() ?: null);
});

test('legacy row (no subscription id) refreshes through the email+plan fallback after its next cycle', function () use ($hook, $refresh, $legacy) {
    [$c, $b] = $refresh($legacy['key']); eq(200, $c); eq('current', $b['status'], 'nothing newer yet');
    $n = $hook('prod_pm', 'legacy@example.test', '');         // renewal webhook without a subscription id
    [$c, $b] = $refresh($legacy['key']); eq(200, $c, json_encode($b));
    eq(['refreshed', $n['license_key'], $n['jti']], [$b['status'], $b['license_key'], $b['jti']]);
    eq([(int) $n['issued'], (int) $n['expires']], [$b['issued'], $b['expires']]);
});

test('monthly chain: old key -> newest; current when already newest; contract keys exactly', function () use ($hook, $refresh) {
    $k1 = $hook('prod_pm', 'chain@example.test', 'sub_chain');
    $k2 = $hook('prod_pm', 'chain@example.test', 'sub_chain');
    $k3 = $hook('prod_pm', 'chain@example.test', 'sub_chain');
    ok((int) $k2['issued'] > (int) $k1['issued'] && (int) $k3['issued'] > (int) $k2['issued'], 'issued strictly increases even within one second');
    [$c, $b, $raw] = $refresh($k1['license_key']); eq(200, $c);
    eq(['status', 'license_key', 'jti', 'issued', 'expires'], array_keys($b), 'exact keys');
    eq([$k3['license_key'], $k3['jti']], [$b['license_key'], $b['jti']], 'skips straight to the newest');
    [, $b] = $refresh($k2['license_key']); eq($k3['jti'], $b['jti']);
    [$c, $b, $raw] = $refresh($k3['license_key']); eq([200, '{"status":"current"}'], [$c, $raw]);
});

test('Cache-Control: no-store and no CORS headers on /refresh', function () use ($hook, $refresh) {
    $k = $hook('prod_pm', 'hdr@example.test', 'sub_hdr');
    $refresh($k['license_key'], '', $h);
    ok(stripos($h, 'Cache-Control: no-store') !== false, 'no-store');
    ok(stripos($h, 'Access-Control-') === false, 'no CORS');
});

test('bad signature / missing / malformed Bearer -> 401 {"error":"invalid key"}', function () use ($refresh, $hook, $pSrv, $signer) {
    $k = $hook('prod_pm', 'sig@example.test', 'sub_sig');
    [$h, $pl, $sg] = explode('.', $k['license_key']);
    $t = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $d = json_decode(base64_decode(strtr($pl, '-_', '+/')), true); $d['edition'] = 'enterprise';
    $other = (new LicenseSigner(base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))))->mint(['edition' => 'pro']);
    foreach (['tampered' => "$h." . $t(json_encode($d)) . ".$sg", 'foreign' => $other['key'], 'garbage' => 'definitely.not.akey', 'one-part' => 'abc'] as $why => $key) {
        [$c, , $raw] = $refresh($key); eq([401, '{"error":"invalid key"}'], [$c, $raw], $why);
    }
    eq(401, req($pSrv, 'POST', '/refresh', '{}', ['Content-Type: application/json'])[0], 'no Authorization header');
    eq(401, req($pSrv, 'POST', '/refresh', '{}', ['Authorization: Basic abc'])[0], 'not a Bearer');
    eq(400, req($pSrv, 'POST', '/refresh', str_repeat('x', 1025), ['Authorization: Bearer ' . $k['license_key']])[0], 'body over 1 KB');
});

test('a key in the query string or the body is ignored (401), a GET is not served', function () use ($pSrv, $hook) {
    $k = $hook('prod_pm', 'qs@example.test', 'sub_qs')['license_key'];
    foreach (['?token=', '?license_key=', '?key=', '?access_token='] as $q) {
        eq(401, req($pSrv, 'POST', '/refresh' . $q . $k, '{}', ['Content-Type: application/json'])[0], $q);
    }
    eq(401, req($pSrv, 'POST', '/refresh', json_encode(['license_key' => $k, 'key' => $k]), ['Content-Type: application/json'])[0], 'body key');
    ok(req($pSrv, 'GET', '/refresh', null, ['Authorization: Bearer ' . $k])[0] !== 200, 'GET');
});

test('every refusal is one byte-identical 404: revoked, refunded, support, stale, unknown jti, key mismatch, ambiguous', function () use ($hook, $refresh, $pSrv, $ADM, $notAvailable, $dir, $signer) {
    $db = new PDO("sqlite:$dir/l.sqlite");
    $bodies = [];
    // revoked via /revoke {jti}
    $r1 = $hook('prod_pm', 'rv@example.test', 'sub_rv'); req($pSrv, 'POST', '/revoke', json_encode(['jti' => $r1['jti']]), $ADM);
    $bodies['revoked'] = $refresh($r1['license_key']);
    // refunded: the latest order is refunded while the OLD key is presented (dead chain)
    $f1 = $hook('prod_pm', 'rf@example.test', 'sub_rf'); $f2 = $hook('prod_pm', 'rf@example.test', 'sub_rf');
    $raw = json_encode(['type' => 'order.refunded', 'data' => ['id' => $f2['order_id'], 'status' => 'refunded']]);
    $id = 'msg_' . bin2hex(random_bytes(6)); $ts = (string) time();
    $sig = base64_encode(hash_hmac('sha256', "$id.$ts.$raw", 'test-webhook-secret-0123456789abcdef', true));
    eq(200, req($pSrv, 'POST', '/webhook/polar', $raw, ["webhook-id: $id", "webhook-timestamp: $ts", "webhook-signature: v1,$sig"])[0]);
    $bodies['refunded chain'] = $refresh($f1['license_key']);
    // support-only
    $s1 = $hook('prod_sup', 'sp@example.test', 'sub_sp'); $hook('prod_sup', 'sp@example.test', 'sub_sp');
    $bodies['support'] = $refresh($s1['license_key']);
    // stale: expired + grace + 90 d + 1 day ago, with a newer row present
    $t1 = $hook('prod_pm', 'st@example.test', 'sub_st'); $hook('prod_pm', 'st@example.test', 'sub_st');
    $db->prepare('UPDATE licenses SET expires = ? WHERE jti = ?')->execute([time() - (7 + 90 + 1) * 86400, $t1['jti']]);
    $bodies['stale'] = $refresh($t1['license_key']);
    // unknown jti: validly signed by this server, never stored (an offline license-gen key)
    $bodies['unknown'] = $refresh($signer->mint(['edition' => 'pro', 'modules' => ['share'], 'ttlDays' => 31, 'enforcement' => 'subscription'])['key']);
    // key mismatch: right jti, a different validly signed key
    $m = $hook('prod_pm', 'mm@example.test', 'sub_mm');
    [$h, $pl] = explode('.', $m['license_key']);
    $d = json_decode(base64_decode(strtr($pl, '-_', '+/')), true);
    $b64 = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $pl2 = $b64(json_encode(['edition' => 'studio'] + $d)); $hh = $b64(json_encode(['alg' => 'Ed25519', 'kid' => 'k1']));
    $forged = "$hh.$pl2." . $b64(sodium_crypto_sign_detached("$hh.$pl2", sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
    $bodies['mismatch (foreign sig)'] = $refresh($forged);
    $rawSecret = base64_decode($GLOBALS['sec']);
    $sig2 = $b64(sodium_crypto_sign_detached("$hh.$pl2", $rawSecret));
    $bodies['mismatch (valid sig, other bytes)'] = $refresh("$hh.$pl2.$sig2");
    // ambiguous: a legacy row plus rows of two distinct subscriptions for the same email+plan
    $a0 = $hook('prod_pm', 'amb@example.test', '');
    $hook('prod_pm', 'amb@example.test', 'sub_amb1'); $hook('prod_pm', 'amb@example.test', 'sub_amb2');
    $bodies['ambiguous'] = $refresh($a0['license_key']);

    $expect401 = ['mismatch (foreign sig)'];   // not signed by us: refused earlier, as 401
    foreach ($bodies as $why => [$c, , $raw]) {
        if (in_array($why, $expect401, true)) { eq(401, $c, $why); continue; }
        eq([404, $notAvailable], [$c, $raw], $why);
    }
});

test('/revoke {subscription_id} stops every key of that subscription from refreshing; other subscriptions are untouched', function () use ($hook, $refresh, $pSrv, $ADM, $notAvailable) {
    $a1 = $hook('prod_pm', 'rs@example.test', 'sub_rs'); $a2 = $hook('prod_pm', 'rs@example.test', 'sub_rs');
    $o1 = $hook('prod_pm', 'rs@example.test', 'sub_rs_other'); $o2 = $hook('prod_pm', 'rs@example.test', 'sub_rs_other');
    eq(200, $refresh($a1['license_key'])[0]);
    [$c, $b] = req($pSrv, 'POST', '/revoke', json_encode(['subscription_id' => 'sub_rs', 'status' => 'revoked']), $ADM);
    eq([200, true, 2], [$c, $b['updated'], $b['rows']]);
    eq([404, $notAvailable], [$refresh($a1['license_key'])[0], $refresh($a1['license_key'])[2]]);
    eq(404, $refresh($a2['license_key'])[0]);
    eq(200, $refresh($o1['license_key'])[0], 'other subscription still refreshes');
    eq(404, req($pSrv, 'POST', '/revoke', json_encode(['subscription_id' => 'sub_nope', 'status' => 'revoked']), $ADM)[0]);
    eq(400, req($pSrv, 'POST', '/revoke', json_encode(['subscription_id' => 'bad id!', 'status' => 'revoked']), $ADM)[0]);
    eq(401, req($pSrv, 'POST', '/revoke', json_encode(['subscription_id' => 'sub_rs']), ['Content-Type: application/json'])[0], 'admin only');
});

test('edition upgrade inside one subscription is followed (pro -> studio)', function () use ($hook, $refresh) {
    $p = $hook('prod_pm', 'up@example.test', 'sub_up');
    $s = $hook('prod_sm', 'up@example.test', 'sub_up', 'subscription_update');
    [$c, $b] = $refresh($p['license_key']); eq(200, $c);
    eq($s['jti'], $b['jti']);
    $pl = json_decode(base64_decode(strtr(explode('.', $b['license_key'])[1], '-_', '+/')), true);
    eq('studio', $pl['edition']);
});

test('never an older or earlier-expiring key: such a row is not offered ("current")', function () use ($hook, $refresh, $dir) {
    $db = new PDO("sqlite:$dir/l.sqlite");
    $a = $hook('prod_pm', 'dg@example.test', 'sub_dg'); $b = $hook('prod_pm', 'dg@example.test', 'sub_dg');
    $db->prepare('UPDATE licenses SET expires = ? WHERE jti = ?')->execute([(int) $a['expires'] - 100, $b['jti']]);   // newer issued, earlier expiry
    [$c, $r] = $refresh($a['license_key']); eq([200, 'current'], [$c, $r['status']]);
    [$c, $r] = $refresh($b['license_key']); eq('current', $r['status'], 'and the newer key is not walked back to the older');
});

test('throttle: the 11th call in an hour for one licence is 429 with Retry-After; per-IP bucket also 429', function () use ($hook, $refresh, $dir) {
    $k = $hook('prod_pm', 'th@example.test', 'sub_th');
    $db = new PDO("sqlite:$dir/l.sqlite"); $db->exec('DELETE FROM rate_hits');
    $codes = []; $h = '';
    for ($i = 0; $i < 11; $i++) { $codes[] = $refresh($k['license_key'], '', $h)[0]; }
    eq(array_merge(array_fill(0, 10, 200), [429]), $codes);
    ok(stripos($h, 'Retry-After:') !== false, 'Retry-After');
    $k2 = $hook('prod_pm', 'th2@example.test', 'sub_th2');
    eq(200, $refresh($k2['license_key'])[0], 'a different licence is not throttled');
    $db->prepare("INSERT INTO rate_hits (bucket, win, n) VALUES ('refresh-ip:127.0.0.1', ?, 120) ON CONFLICT(bucket, win) DO UPDATE SET n = 120")->execute([intdiv(time(), 3600)]);
    $k3 = $hook('prod_pm', 'th3@example.test', 'sub_th3');
    eq(429, $refresh($k3['license_key'])[0], 'IP bucket full');
    $db->exec('DELETE FROM rate_hits');
});

test('an invalid signature never costs a rate_hits write', function () use ($refresh, $dir) {
    $db = new PDO("sqlite:$dir/l.sqlite"); $db->exec('DELETE FROM rate_hits');
    for ($i = 0; $i < 5; $i++) { $refresh('bad.key.value'); }
    eq(0, (int) $db->query('SELECT COUNT(*) FROM rate_hits')->fetchColumn());
});

test('the key never appears in the server log (stderr/stdout) when sent as a Bearer header', function () use ($dir, $pSrv, $hook, $refresh) {
    $k = $hook('prod_pm', 'log@example.test', 'sub_log');
    $refresh($k['license_key']);
    $all = (string) @file_get_contents("$dir/err$pSrv.log") . (string) @file_get_contents("$dir/out$pSrv.log");
    $sigPart = explode('.', $k['license_key'])[2];
    ok(!str_contains($all, $k['license_key']) && !str_contains($all, $sigPart), 'no key material in logs');
    ok(str_contains($all, 'license refresh: jti=' . $k['jti']), 'but the outcome line with the jti is logged');
});

test('/refresh makes no outbound call (source check), answers with no update server configured', function () use ($hook, $refresh) {
    $src = (string) file_get_contents(dirname(__DIR__) . '/server.php');
    $blk = substr($src, (int) strpos($src, "uri === '/refresh'"), 2600);
    ok(!preg_match('/curl_|file_get_contents\(\s*[\'"]http|ProDownload::(fetch|linkFor)|stream_context/', $blk), 'no outbound call in the /refresh block');
    $k = $hook('prod_pm', 'off@example.test', 'sub_off');
    eq(200, $refresh($k['license_key'])[0]);
});

// ── backfill-subscription-ids.php against a fake Polar ────────────────────────────────
echo "\n{$cyan}══ backfill-subscription-ids.php ══{$reset}\n\n";
file_put_contents("$dir/polar.php", '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (($_SERVER["HTTP_AUTHORIZATION"] ?? "") !== "Bearer fake-polar-token-123") { http_response_code(401); echo "{}"; exit; }
$map = json_decode(file_get_contents(' . var_export("$dir/orders.json", true) . '), true);
if (preg_match("#^/v1/orders/([^/]+)$#", $p, $m) && isset($map[$m[1]])) { header("Content-Type: application/json"); echo json_encode($map[$m[1]]); exit; }
http_response_code(404); echo "{}";');
file_put_contents("$dir/orders.json", json_encode([
    'ord-bf-sub'   => ['id' => 'ord-bf-sub', 'subscription_id' => 'sub_bf_1', 'billing_reason' => 'subscription_cycle'],
    'ord-bf-nest'  => ['id' => 'ord-bf-nest', 'subscription' => ['id' => 'sub_bf_2'], 'billing_reason' => 'subscription_create'],
    'ord-bf-once'  => ['id' => 'ord-bf-once', 'subscription_id' => null, 'billing_reason' => 'purchase'],
]));
$start($pPolar, "$dir/polar.php", ['PATH' => getenv('PATH')]);
$bfDb = "$dir/bf.sqlite";
$bfSeed = new PDO("sqlite:$bfDb");
$bfSeed = null;
require_once dirname(__DIR__) . '/LicenseStore.php';
$st = new \FluxFiles\LicenseServer\LicenseStore($bfDb);
foreach ([['jti-bf-1', 'ord-bf-sub', 'polar'], ['jti-bf-2', 'ord-bf-nest', 'polar'], ['jti-bf-3', 'ord-bf-once', 'polar'], ['jti-bf-4', 'ord-bf-missing', 'polar'], ['jti-bf-5', 'ord-bf-manual', 'manual']] as [$j, $o, $g]) {
    $st->record(['jti' => $j, 'email' => "$j@example.test", 'plan' => 'pro-monthly', 'license_key' => "k-$j", 'gateway' => $g, 'order_id' => $o]);
}
$st->record(['jti' => 'jti-bf-6', 'email' => 'six@example.test', 'plan' => 'pro-monthly', 'license_key' => 'k-6', 'gateway' => 'polar', 'order_id' => 'ord-bf-done', 'subscription_id' => 'sub_existing']);
$run = function (array $args, array $env = []) use ($bfDb, $pPolar): array {
    $cmd = array_merge([PHP_BINARY, dirname(__DIR__) . '/backfill-subscription-ids.php'], $args);
    $pr = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['FLUXFILES_LICENSE_DB' => $bfDb, 'PATH' => getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($pr), $out];
};
$api = "--api-base=http://127.0.0.1:$pPolar/v1";
$col = fn (string $c) => (new PDO("sqlite:$bfDb"))->query("SELECT jti || '=' || COALESCE($c, '~') FROM licenses ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

test('backfill dry run reads Polar, reports, and changes nothing; the token is never printed', function () use ($run, $api, $col) {
    $before = $col('subscription_id');
    [$code, $out] = $run([$api, '--delay-ms=0'], ['POLAR_TOKEN' => 'fake-polar-token-123']);
    eq(2, $code, 'one order is unknown to Polar -> non-zero so cron notices');
    ok(str_contains($out, 'dry run') && str_contains($out, 'subscription=sub_bf_1') && str_contains($out, 'subscription=sub_bf_2'), $out);
    ok(!str_contains($out, 'fake-polar-token-123'), 'token not printed');
    eq($before, $col('subscription_id'), 'DB untouched');
});
test('backfill without a token refuses (exit 1); a wrong token stops on the first 401; --api-base must be Polar or loopback', function () use ($run, $api) {
    [$c, $o] = $run([$api]); eq(1, $c); ok(str_contains($o, 'no Polar token'));
    [$c, $o] = $run([$api, '--delay-ms=0'], ['POLAR_TOKEN' => 'wrong-token-xyz']); eq(1, $c); ok(!str_contains($o, 'wrong-token-xyz'), 'token not printed');
    [$c] = $run(['--api-base=https://evil.example/v1'], ['POLAR_TOKEN' => 'x']); eq(1, $c, 'token cannot be sent elsewhere');
    [$c] = $run(['--api-base=http://evil.example/v1'], ['POLAR_TOKEN' => 'x']); eq(1, $c);
});
test('backfill --apply writes subscription ids (flat and nested), marks one-time orders, leaves manual / failed / already-set rows', function () use ($run, $api, $col, $dir) {
    file_put_contents("$dir/tok", "fake-polar-token-123\n");
    [$code, $out] = $run([$api, '--delay-ms=0', '--apply', "--token-file=$dir/tok"]);
    eq(2, $code); ok(!str_contains($out, 'fake-polar-token-123'), 'token not printed (file mode)');
    eq(['jti-bf-1=sub_bf_1', 'jti-bf-2=sub_bf_2', 'jti-bf-3=~', 'jti-bf-4=~', 'jti-bf-5=~', 'jti-bf-6=sub_existing'], $col('subscription_id'));
    eq(['jti-bf-1=subscription_cycle', 'jti-bf-2=subscription_create', 'jti-bf-3=purchase', 'jti-bf-4=~', 'jti-bf-5=~', 'jti-bf-6=~'], $col('billing_reason'));
});
test('backfill is idempotent: a re-run only retries what failed', function () use ($run, $api, $col) {
    $before = $col('subscription_id');
    [$code, $out] = $run([$api, '--delay-ms=0', '--apply'], ['POLAR_TOKEN' => 'fake-polar-token-123']);
    ok(str_contains($out, '1 Polar row(s) to look up'), $out);
    eq($before, $col('subscription_id'));
});

echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
