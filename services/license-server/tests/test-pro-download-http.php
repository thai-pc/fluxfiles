<?php
/**
 * FluxFiles Pro download over HTTP, end to end (php -S, throwaway key + DB):
 *  - /claim carries a cached link and never waits long on the update server
 *  - the licence pool does NOT deadlock: the update server's /update-status callback lands on a
 *    SEPARATE "internal" server (the deploy's second php-fpm pool); 6 concurrent claims on a
 *    3-worker public pool all get a link
 *  - the Polar webhook makes no update-server call at all
 *  - POST /pro-download: landing-origin CORS only, key in the body, generic errors, rate limits
 * Usage: php services/license-server/tests/test-pro-download-http.php
 */
declare(strict_types=1);

$green="\033[32m";$red="\033[31m";$cyan="\033[36m";$reset="\033[0m";$p=0;$f=0;
function test(string $n, callable $fn): void { global $p,$f,$green,$red,$reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; } catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: ".$e->getMessage()."\n"; $f++; } }
function eq($e,$a,$m=''){ if($e!==$a) throw new RuntimeException(($m?"$m: ":'').'expected '.json_encode($e).' got '.json_encode($a)); }
function ok($c,$m=''){ if(!$c) throw new RuntimeException($m?:'expected true'); }

if (!function_exists('sodium_crypto_sign_keypair')) { fwrite(STDERR, "skip: no sodium\n"); exit(0); }
$dir = sys_get_temp_dir() . '/fxpd-' . bin2hex(random_bytes(4)); mkdir($dir);
register_shutdown_function(static function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir)); });
$kp = sodium_crypto_sign_keypair(); $sec = base64_encode(sodium_crypto_sign_secretkey($kp));
$port = static function (): int { $s = stream_socket_server('tcp://127.0.0.1:0'); $n = (int) substr(strrchr((string) stream_socket_get_name($s, false), ':'), 1); fclose($s); return $n; };
[$pPub, $pInt, $pUpd] = [$port(), $port(), $port()];
$landing = 'https://landing.example';
$srv = dirname(__DIR__) . '/server.php';
$base = ['FLUXFILES_LICENSE_PRIVATE_KEY' => $sec, 'FLUXFILES_LICENSE_DB' => "$dir/l.sqlite", 'FLUXFILES_LICENSE_ADMIN_TOKEN' => 'adm-tok', 'FLUXFILES_UPDATE_STATUS_TOKEN' => 'upd-tok',
    'FLUXFILES_POLAR_WEBHOOK_SECRET' => 'test-webhook-secret-0123456789abcdef', 'FLUXFILES_POLAR_PLAN_MAP' => json_encode(['prod_pro' => 'pro']),
    'FLUXFILES_MAIL_TRANSPORT' => 'log', 'PATH' => getenv('PATH')];

// Fake update server: models the real hop (slow, then calls /update-status on the INTERNAL server).
file_put_contents("$dir/upd.php", '<?php
$hits = ' . var_export("$dir/hits", true) . ';
file_put_contents($hits, "x", FILE_APPEND);
usleep(300000);
$auth = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
if (!preg_match("/^Bearer\s+(\S+)$/", $auth, $m)) { http_response_code(402); exit("no"); }
$pl = json_decode((string) base64_decode(strtr(explode(".", $m[1])[1] ?? "", "-_", "+/")), true);
$ch = curl_init("http://127.0.0.1:' . (getenv("FXPD_SHARED") ? $pPub : $pInt) . '/update-status");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(["jti" => $pl["jti"] ?? ""]), CURLOPT_HTTPHEADER => ["Authorization: Bearer upd-tok"], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$st = json_decode((string) curl_exec($ch), true);
if (($st["active"] ?? false) !== true) { http_response_code(402); exit("license revoked or inactive"); }
$ttl = max(300, min((int) ($_GET["link_ttl"] ?? 86400), 604800));
$u = fn ($x) => rtrim(strtr(base64_encode($x), "+/", "-_"), "=");
echo $u("{}") . "." . $u(json_encode(["module" => "fluxfiles-pro", "version" => "1.0.0", "url" => "https://upd.example/dl/m/fluxfiles-pro-1.0.0.zip?md5=a&expires=" . (time() + $ttl)])) . "." . $u("sig");');

$procs = [];
$start = function (int $p, string $script, array $env, int $workers) use (&$procs, $dir): void {
    $procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$p", $script], [1 => ['file', "$dir/out$p.log", 'a'], 2 => ['file', "$dir/err$p.log", 'a']], $pp, dirname($script), $env + ['PHP_CLI_SERVER_WORKERS' => (string) $workers]);
    for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $p); $i++) { usleep(100000); }
};
register_shutdown_function(static function () use (&$procs) { foreach ($procs as $pr) { proc_terminate($pr); } });
$start($pUpd, "$dir/upd.php", ['PATH' => getenv('PATH')], 6);
$start($pInt, $srv, $base, 2);                                                           // internal pool: /update-status
$start($pPub, $srv, $base + ['FLUXFILES_PRO_UPDATE_URL' => "http://127.0.0.1:$pUpd", 'FLUXFILES_LANDING_ORIGIN' => $landing], 3);   // public pool: 3 workers like the deploy

function req(int $port, string $m, string $path, ?string $body = null, array $h = [], ?float &$secs = null) {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
    $r = curl_exec($ch); $secs = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $r, true), (string) $r];
}
$hits = static fn (): int => is_file("$GLOBALS[dir]/hits") ? strlen((string) file_get_contents("$GLOBALS[dir]/hits")) : 0;
$db = new PDO("sqlite:$dir/l.sqlite");
$n = 0;
$issue = function (string $plan) use ($pPub, $db, &$n): array {
    $n++;
    [$c, $b] = req($pPub, 'POST', '/issue', json_encode(['email' => "b$n@example.test", 'plan' => $plan, 'order_id' => "order-0123456789-$n"]), ['Authorization: Bearer adm-tok', 'Content-Type: application/json']);
    $chk = sprintf('chk-%012d-abcd', $n);
    $db->prepare("UPDATE licenses SET gateway='polar', checkout_id=? WHERE jti=?")->execute([$chk, $b['record']['jti']]);
    return ['key' => $b['license_key'], 'jti' => $b['record']['jti'], 'chk' => $chk];
};
$claim = fn (string $chk, ?float &$t = null) => req($pPub, 'GET', '/claim?checkout_id=' . $chk, null, [], $t);

echo "\n{$cyan}══ Pro download (HTTP) ══{$reset}\n\n";
// the licences table gets its checkout_id column lazily; touch the DB once through the server
req($pPub, 'GET', '/health');

test('/claim: link for a Pro plan, none for support; cached per licence (second claim = no update-server call)', function () use ($issue, $claim, $hits) {
    $a = $issue('pro'); $h0 = $hits();
    [$c, $b] = $claim($a['chk']); eq(200, $c); ok(str_contains($b['pro_download']['url'] ?? '', 'fluxfiles-pro-1.0.0.zip'), 'link');
    ok($b['pro_download']['expires_in'] > 3500 && $b['pro_download']['expires_in'] <= 3600, 'expires_in from the URL: ' . $b['pro_download']['expires_in']);
    eq($h0 + 1, $hits(), 'one fetch');
    [, $b2] = $claim($a['chk']); eq($b['pro_download']['url'], $b2['pro_download']['url']); eq($h0 + 1, $hits(), 'cached: still one fetch');
    $s = $issue('support'); [, $b3] = $claim($s['chk']); ok(!isset($b3['pro_download']), 'support plan: no link'); eq($h0 + 1, $hits(), 'no fetch for support');
});
test('no deadlock: 6 concurrent /claim on a 3-worker public pool all finish fast WITH a link, and /health is not starved', function () use ($issue, $pPub, $hits) {
    $lic = []; for ($i = 0; $i < 6; $i++) { $lic[] = $issue('pro'); }
    $mh = curl_multi_init(); $chs = [];
    foreach ($lic as $l) { $ch = curl_init("http://127.0.0.1:$pPub/claim?checkout_id=" . $l['chk']); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]); curl_multi_add_handle($mh, $ch); $chs[] = $ch; }
    $t0 = microtime(true); $health = null;
    do { curl_multi_exec($mh, $act); if ($health === null && microtime(true) - $t0 > 0.15) { req($pPub, 'GET', '/health', null, [], $health); } curl_multi_select($mh, 0.05); } while ($act > 0);
    $total = microtime(true) - $t0; $links = 0;
    foreach ($chs as $ch) { $d = json_decode((string) curl_multi_getcontent($ch), true); $links += isset($d['pro_download']) ? 1 : 0; }
    eq(6, $links, 'every concurrent claim got its link');
    ok($total < 4.0, sprintf('all 6 done in %.2fs (the deadlock took 8s+)', $total));
    ok($health !== null && $health < 2.0, 'health during the burst: ' . var_export($health, true));
});
test('Polar webhook never calls the update server (mail links the landing page instead)', function () use ($pPub, $hits) {
    $h0 = $hits(); $raw = json_encode(['type' => 'order.paid', 'data' => ['id' => 'ord_wh_1', 'status' => 'paid', 'product_id' => 'prod_pro', 'checkout_id' => 'co_wh_00000001', 'customer_id' => 'cus1', 'customer' => ['id' => 'cus1', 'email' => 'wh@example.test', 'name' => 'W']]]);
    $id = 'msg_' . bin2hex(random_bytes(6)); $ts = (string) time(); $sig = base64_encode(hash_hmac('sha256', "$id.$ts.$raw", 'test-webhook-secret-0123456789abcdef', true));
    [$c] = req($pPub, 'POST', '/webhook/polar', $raw, ["webhook-id: $id", "webhook-timestamp: $ts", "webhook-signature: v1,$sig", 'Content-Type: application/json']);
    eq(200, $c); eq($h0, $hits(), 'no update-server hit');
});
test('source: only /claim and /pro-download reach the update server (not the webhook, not /redeliver)', function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/server.php');
    preg_match_all('/ProDownload::(fetch|linkFor)\(/', $src, $m); eq(2, count($m[0]));
});

$cors = ['Origin: ' . $landing, 'Content-Type: application/json'];
test('/pro-download CORS: only the landing origin; preflight answers 204', function () use ($pPub, $landing, $issue) {
    $body = json_encode(['license_key' => 'x']);
    eq(403, req($pPub, 'POST', '/pro-download', $body, ['Content-Type: application/json'])[0], 'no Origin');
    eq(403, req($pPub, 'POST', '/pro-download', $body, ['Origin: https://evil.example', 'Content-Type: application/json'])[0], 'other origin');
    $ch = curl_init("http://127.0.0.1:$pPub/pro-download"); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'OPTIONS', CURLOPT_HTTPHEADER => ['Origin: ' . $landing], CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true]);
    $r = (string) curl_exec($ch); eq(204, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE));
    ok(stripos($r, 'Access-Control-Allow-Origin: ' . $landing) !== false && stripos($r, 'Access-Control-Allow-Origin: *') === false, 'ACAO is the landing origin');
});
test('/pro-download: valid key in the body -> fresh link; unknown, revoked and support keys all answer the SAME generic 404', function () use ($pPub, $issue, $cors, $db) {
    $a = $issue('pro');
    [$c, $b] = req($pPub, 'POST', '/pro-download', json_encode(['license_key' => $a['key']]), $cors); eq(200, $c, json_encode($b));
    ok(str_contains($b['pro_download']['url'], '.zip'), 'link');
    [$c1, , $r1] = req($pPub, 'POST', '/pro-download', json_encode(['license_key' => 'definitely.not.akey']), $cors);
    req($pPub, 'POST', '/revoke', json_encode(['jti' => $a['jti'], 'status' => 'revoked']), ['Authorization: Bearer adm-tok']);
    [$c2, , $r2] = req($pPub, 'POST', '/pro-download', json_encode(['license_key' => $a['key']]), $cors);
    $s = $issue('support'); [$c3, , $r3] = req($pPub, 'POST', '/pro-download', json_encode(['license_key' => $s['key']]), $cors);
    eq([404, 404, 404], [$c1, $c2, $c3]); eq($r1, $r2, 'revoked == unknown'); eq($r1, $r3, 'support == unknown');
});
test('/pro-download: a SUPERSEDED key (early renewal) still works; revoked does not; both count against the licence throttle', function () use ($pPub, $issue, $cors, $db) {
    $a = $issue('pro');
    $db->prepare("UPDATE licenses SET status='superseded' WHERE jti=?")->execute([$a['jti']]);
    eq(200, req($pPub, 'POST', '/pro-download', json_encode(['license_key' => $a['key']]), $cors)[0], 'superseded is update-eligible');
    $db->prepare("UPDATE licenses SET status='revoked' WHERE jti=?")->execute([$a['jti']]);
    eq(404, req($pPub, 'POST', '/pro-download', json_encode(['license_key' => $a['key']]), $cors)[0], 'revoked: generic 404');
    $n = (int) $db->query("SELECT n FROM rate_hits WHERE bucket='dl-jti:{$a['jti']}'")->fetchColumn();
    eq(2, $n, 'the revoked attempt was counted too');
});
test('/pro-download rate limits: 5 per licence, then 10 per IP, both per 10 minutes', function () use ($pPub, $issue, $cors, $db) {
    $db->exec('DELETE FROM rate_hits');   // fresh window: the earlier tests spent part of the IP budget
    $a = $issue('pro'); $codes = [];
    for ($i = 0; $i < 6; $i++) { $codes[] = req($pPub, 'POST', '/pro-download', json_encode(['license_key' => $a['key']]), $cors)[0]; }
    eq([200, 200, 200, 200, 200, 429], $codes, 'the 6th call for one licence is throttled');
    for ($i = 0; $i < 4; $i++) { eq(404, req($pPub, 'POST', '/pro-download', json_encode(['license_key' => 'x']), $cors)[0], 'unknown key, IP budget left'); }
    eq(429, req($pPub, 'POST', '/pro-download', json_encode(['license_key' => 'x']), $cors)[0], 'the 11th call from this IP in the window');
});

echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
