<?php
/**
 * Ignored (sponsorship) products, end to end: boots the real server.php (php -S, ephemeral
 * port, throwaway key + DB) and drives it with signed Polar webhooks.
 * Usage: php services/license-server/tests/test-ignore-products-http.php
 */
declare(strict_types=1);

$core = __DIR__ . '/../../../packages/core/vendor/autoload.php';
if (!is_file($core)) {
    fwrite(STDERR, "core vendor not installed; run composer install -d packages/core\n");
    exit(filter_var(getenv('CI'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
}
require_once $core;
use FluxFiles\LicenseManager;

$green="\033[32m";$red="\033[31m";$cyan="\033[36m";$reset="\033[0m";$p=0;$f=0;
function test(string $n, callable $fn): void { global $p,$f,$green,$red,$reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: ".$e->getMessage()."\n"; $f++; } }
function eq($e,$a,$m=''){ if($e!==$a) throw new RuntimeException(($m?"$m: ":'').'expected '.json_encode($e).' got '.json_encode($a)); }
function ok($c,$m=''){ if(!$c) throw new RuntimeException($m?:'expected true'); }

$dir = sys_get_temp_dir() . '/fxign-' . bin2hex(random_bytes(4)); mkdir($dir);
$kp = sodium_crypto_sign_keypair();
$sec = base64_encode(sodium_crypto_sign_secretkey($kp)); $pub = ['k1' => base64_encode(sodium_crypto_sign_publickey($kp))];
$whsec = 'test-webhook-secret-0123456789abcdef';
$planMap = ['prod_pl'=>'pro-lifetime','prod_both'=>'pro-lifetime'];
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
$env = ['FLUXFILES_LICENSE_PRIVATE_KEY'=>$sec,'FLUXFILES_LICENSE_DB'=>"$dir/l.sqlite",'FLUXFILES_POLAR_WEBHOOK_SECRET'=>$whsec,
  'FLUXFILES_POLAR_PLAN_MAP'=>json_encode($planMap),'FLUXFILES_LICENSE_ADMIN_TOKEN'=>'adm-tok','FLUXFILES_UPDATE_STATUS_TOKEN'=>'upd-tok',
  'FLUXFILES_MAIL_TRANSPORT'=>'log','FLUXFILES_POLAR_IGNORE_PRODUCTS'=>' prod_sp1 , prod_sp2,,prod_both ','PATH'=>getenv('PATH')];
$srv = dirname(__DIR__) . '/server.php';
$proc = proc_open([PHP_BINARY,'-S',"127.0.0.1:$port",$srv], [1=>['file',"$dir/out.log",'a'],2=>['file',"$dir/err.log",'a']], $pp, dirname($srv), $env);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }

function req(string $m, string $path, ?string $body = null, array $h = []) { global $port;
  $ch = curl_init("http://127.0.0.1:$port$path"); curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$h]+($body!==null?[CURLOPT_POSTFIELDS=>$body]:[]));
  $r = curl_exec($ch); return [(int)curl_getinfo($ch,CURLINFO_HTTP_CODE), json_decode((string)$r,true)]; }
function hook(array $body) { global $whsec; $raw = json_encode($body); $id='msg_'.bin2hex(random_bytes(6)); $ts=(string)time();
  $sig = base64_encode(hash_hmac('sha256',"$id.$ts.$raw",$whsec,true));
  return req('POST','/webhook/polar',$raw,["webhook-id: $id","webhook-timestamp: $ts","webhook-signature: v1,$sig",'Content-Type: application/json']); }
function paid(string $oid, string $prod, string $email, string $co = '', string $cus = '') { return hook(['type'=>'order.paid','data'=>['id'=>$oid,'status'=>'paid','product_id'=>$prod,'checkout_id'=>$co,'customer_id'=>$cus,'customer'=>['id'=>$cus,'email'=>$email,'name'=>'N']]]); }
function refund(string $oid) { return hook(['type'=>'order.refunded','data'=>['id'=>$oid,'status'=>'refunded']]); }
function rows(string $email): array { return req('GET','/licenses?email='.urlencode($email),null,['Authorization: Bearer adm-tok'])[1]['licenses'] ?? []; }
function byPlan(string $email, string $plan): array { return array_values(array_filter(rows($email), fn ($r) => $r['plan'] === $plan)); }
function upd(string $jti) { return req('POST','/update-status',json_encode(['jti'=>$jti]),['Authorization: Bearer upd-tok'])[1]['active'] ?? null; }

function db(): PDO { global $dir; $d = new PDO("sqlite:$dir/l.sqlite"); $d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); return $d; }
function alerts(): int { try { return (int) db()->query('SELECT COUNT(*) FROM ops_outbox')->fetchColumn(); } catch (\Throwable $e) { return 0; } }
function licences(): int { try { return (int) db()->query('SELECT COUNT(*) FROM licenses')->fetchColumn(); } catch (\Throwable $e) { return 0; } }
function outcomes(string $oid): array { $q = db()->prepare('SELECT outcome,http_status FROM webhook_events WHERE order_id=?'); $q->execute([$oid]); return $q->fetchAll(); }

echo "\n{$cyan}══ Ignored products over HTTP ══{$reset}\n\n";
try {
    test('ignored product: 200, no licence, no mail, no alert, outcome recorded', function () use ($dir) {
        $a0 = alerts(); $l0 = licences();
        [$c, $b] = paid('ord_sp_1', 'prod_sp1', 'sponsor@x.com', 'co_sp_000000001', 'cus_sp');
        eq(200, $c); eq('product', $b['ignored'] ?? null); ok(!isset($b['jti']), 'no jti');
        eq($l0, licences(), 'no licence'); eq($a0, alerts(), 'no alert');
        eq([['outcome' => 'ignored_product', 'http_status' => 200]], outcomes('ord_sp_1'));
        eq(0, (int) db()->query("SELECT COUNT(*) FROM ops_items WHERE resolved_at IS NULL")->fetchColumn(), 'no ops item');
        ok(!str_contains((string) file_get_contents("$dir/err.log"), 'unmapped product'), 'not logged as unmapped');
        eq([], rows('sponsor@x.com'));
        eq(404, req('GET', '/claim?checkout_id=co_sp_000000001')[0], 'nothing to claim');
    });
    test('whitespace-tolerant list: the second id is ignored too', function () {
        eq(200, paid('ord_sp_2', 'prod_sp2', 'sponsor2@x.com')[0]);
        eq('ignored_product', outcomes('ord_sp_2')[0]['outcome']);
    });
    test('redelivery is a no-op', function () {
        $a0 = alerts(); $l0 = licences();
        eq(200, paid('ord_sp_1', 'prod_sp1', 'sponsor@x.com', 'co_sp_000000001', 'cus_sp')[0]);
        eq($l0, licences()); eq($a0, alerts());
        eq(1, (int) db()->query("SELECT COUNT(*) FROM orders WHERE order_id='ord_sp_1'")->fetchColumn());
        eq(['ignored_product', 'ignored_product'], array_column(outcomes('ord_sp_1'), 'outcome'), 'both deliveries recorded');
    });
    test('a product in the ignore list AND the plan map still issues (map wins)', function () use ($dir) {
        [$c, $b] = paid('ord_both_1', 'prod_both', 'both@x.com');
        eq(200, $c); ok(!empty($b['jti']), 'licence issued');
        eq(1, count(byPlan('both@x.com', 'pro-lifetime')));
        ok(str_contains((string) file_get_contents("$dir/err.log"), 'is in both FLUXFILES_POLAR_PLAN_MAP'), 'config error logged');
    });
    test('a genuinely unmapped product still answers 503 and alerts', function () {
        $a0 = alerts();
        eq(503, paid('ord_un_1', 'prod_nope', 'u@x.com')[0]);
        eq('unmapped_product', outcomes('ord_un_1')[0]['outcome']);
        ok(alerts() > $a0, 'operator alert raised');
    });
    test('subscription events and refunds of an ignored product raise no alert', function () {
        hook(['type' => 'order.paid', 'data' => ['id' => 'ord_sp_sub', 'status' => 'paid', 'product_id' => 'prod_sp1', 'subscription_id' => 'sub_sp_1',
            'customer_id' => 'cus_sp', 'customer' => ['id' => 'cus_sp', 'email' => 'sponsor@x.com']]]);
        $a0 = alerts(); $l0 = licences();
        foreach (['subscription.created', 'subscription.updated', 'subscription.canceled', 'subscription.revoked'] as $t) {
            [$c] = hook(['type' => $t, 'data' => ['id' => 'sub_sp_1', 'product_id' => 'prod_sp1', 'customer_id' => 'cus_sp', 'status' => 'active']]);
            ok($c >= 200 && $c < 300, "$t → 2xx");
        }
        [$c] = hook(['type' => 'subscription.updated', 'data' => ['id' => 'sub_never_seen', 'product_id' => 'prod_sp1']]);
        ok($c >= 200 && $c < 300, 'unknown subscription → 2xx');
        eq(200, refund('ord_sp_sub')[0]);
        eq($l0, licences(), 'no licence'); eq($a0, alerts(), 'no alert');
        eq(0, (int) db()->query("SELECT COUNT(*) FROM webhook_events WHERE outcome IN ('unmapped_product','error')  AND product_id='prod_sp1'")->fetchColumn());
    });
    test('sponsor revenue is labelled "ignored", not "(unmapped)", in the order facts', function () {
        eq('ignored', db()->query("SELECT plan FROM orders WHERE order_id='ord_sp_1'")->fetchColumn());
    });
} finally {
    proc_terminate($proc); proc_close($proc);
}
echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
