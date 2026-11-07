<?php
/**
 * Renewal security/lifecycle, end to end: boots the real server.php (php -S, ephemeral
 * port, throwaway key + DB) and drives it with signed Polar webhooks.
 * Usage: php services/license-server/tests/test-renewal-http.php
 */
declare(strict_types=1);

$core = __DIR__ . '/../../../packages/core/vendor/autoload.php';
if (!is_file($core)) { fwrite(STDERR, "skip: core vendor not installed\n"); exit(0); }
require_once $core;
use FluxFiles\LicenseManager;

$green="\033[32m";$red="\033[31m";$cyan="\033[36m";$reset="\033[0m";$p=0;$f=0;
function test(string $n, callable $fn): void { global $p,$f,$green,$red,$reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: ".$e->getMessage()."\n"; $f++; } }
function eq($e,$a,$m=''){ if($e!==$a) throw new RuntimeException(($m?"$m: ":'').'expected '.json_encode($e).' got '.json_encode($a)); }
function ok($c,$m=''){ if(!$c) throw new RuntimeException($m?:'expected true'); }

$dir = sys_get_temp_dir() . '/fxren-' . bin2hex(random_bytes(4)); mkdir($dir);
$kp = sodium_crypto_sign_keypair();
$sec = base64_encode(sodium_crypto_sign_secretkey($kp)); $pub = ['k1' => base64_encode(sodium_crypto_sign_publickey($kp))];
$whsec = 'test-webhook-secret-0123456789abcdef';
$planMap = ['prod_pl'=>'pro-lifetime','prod_pr'=>'pro-renewal','prod_l'=>'lifetime','prod_sr'=>'studio-renewal'];
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
$env = ['FLUXFILES_LICENSE_PRIVATE_KEY'=>$sec,'FLUXFILES_LICENSE_DB'=>"$dir/l.sqlite",'FLUXFILES_POLAR_WEBHOOK_SECRET'=>$whsec,
  'FLUXFILES_POLAR_PLAN_MAP'=>json_encode($planMap),'FLUXFILES_LICENSE_ADMIN_TOKEN'=>'adm-tok','FLUXFILES_UPDATE_STATUS_TOKEN'=>'upd-tok',
  'FLUXFILES_MAIL_TRANSPORT'=>'log','PATH'=>getenv('PATH')];
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

echo "\n{$cyan}══ Renewals over HTTP ══{$reset}\n\n";
try {
    test('S1: a renewal bought with the victim\'s email is never served by /claim, and a foreign Polar customer is refused', function () use ($pub) {
        paid('ord_v_base','prod_l','victim@corp.com','co_victim_000001','cus_victim');
        [$c] = paid('ord_a_ren','prod_sr','victim@corp.com','co_attacker_00001','cus_attacker');
        eq(200, $c, 'webhook ACKs');
        eq(0, count(byPlan('victim@corp.com','studio-renewal')), 'nothing issued for the foreign customer');
        [$cc, $claim] = req('GET','/claim?checkout_id=co_attacker_00001');
        eq(404, $cc); ok(empty($claim['license_key']), 'no key via /claim');
        // Same customer id renews fine, but /claim still never serves a renewal key.
        paid('ord_v_ren','prod_sr','victim@corp.com','co_victim_ren_0001','cus_victim');
        eq(1, count(byPlan('victim@corp.com','studio-renewal')));
        eq(404, req('GET','/claim?checkout_id=co_victim_ren_0001')[0], 'renewal keys are mail-only');
        paid('ord_cl_base','prod_pl','cl@x.com','co_claim_base_001','cus_cl');
        eq(200, req('GET','/claim?checkout_id=co_claim_base_001')[0], 'a base purchase is still claimable');
    });
    test('S1b: legacy base without a customer id: email fallback issues, but still not via /claim', function () {
        req('POST','/issue',json_encode(['email'=>'leg@x.com','plan'=>'pro-lifetime']),['Authorization: Bearer adm-tok']);
        paid('ord_leg_ren','prod_pr','leg@x.com','co_leg_ren_000001','cus_whoever');
        eq(1, count(byPlan('leg@x.com','pro-renewal')));
        eq(404, req('GET','/claim?checkout_id=co_leg_ren_000001')[0]);
    });
    test('S2: refunding the base retires the renewal key too', function () {
        paid('ord_s2_base','prod_pl','s2@x.com','co_s2_base_0001'); paid('ord_s2_ren','prod_pr','s2@x.com','co_s2_ren_00001');
        refund('ord_s2_base');
        foreach (rows('s2@x.com') as $r) { eq('refunded', $r['status'], $r['plan']); eq(false, upd($r['jti']), $r['plan'].' update-eligible'); }
    });
    test('S3: refunding a renewal restores the base; the next renewal works', function () {
        paid('ord_s3_base','prod_pl','s3@x.com'); paid('ord_s3_ren','prod_pr','s3@x.com');
        refund('ord_s3_ren');
        eq('active', byPlan('s3@x.com','pro-lifetime')[0]['status']);
        paid('ord_s3_ren2','prod_pr','s3@x.com');
        eq(2, count(byPlan('s3@x.com','pro-renewal')), 'second renewal issued');
    });
    test('S4: two Studio lifetimes under one email; a renewal retires only the extended one', function () {
        req('POST','/issue',json_encode(['email'=>'s4@x.com','plan'=>'lifetime']),['Authorization: Bearer adm-tok']);
        paid('ord_s4_b','prod_l','s4@x.com');
        paid('ord_s4_r','prod_sr','s4@x.com');
        $st = array_map(fn ($r) => $r['status'], byPlan('s4@x.com','lifetime'));
        eq(['active'], array_values(array_filter($st, fn ($s) => $s === 'active')), 'one lifetime left active');
    });
    test('S5: redelivering a renewal order is idempotent', function () {
        paid('ord_s5_base','prod_pl','s5@x.com'); $a = paid('ord_s5_r','prod_pr','s5@x.com'); $b = paid('ord_s5_r','prod_pr','s5@x.com');
        eq($a[1]['jti'], $b[1]['jti']); eq(1, count(byPlan('s5@x.com','pro-renewal')));
    });
    test('S6: renewal with no licence: 200 ACK, nothing issued, buyer mailed; manual /issue gives 400', function () use ($dir) {
        [$c,$b] = paid('ord_s6_r','prod_pr','nobody@x.com');
        eq(200, $c); eq('renewal_without_licence', $b['ignored'] ?? null);
        eq([], rows('nobody@x.com'));
        ok(str_contains((string) file_get_contents("$dir/err.log"), 'We could not match your FluxFiles renewal'), 'buyer was mailed');
        eq(400, req('POST','/issue',json_encode(['email'=>'nobody@x.com','plan'=>'pro-renewal']),['Authorization: Bearer adm-tok'])[0]);
    });
    test('manual /issue of a renewal for the lifetime email works (the documented fix)', function () use ($pub) {
        paid('ord_fix_base','prod_pl','fix@x.com');
        [$c,$b] = req('POST','/issue',json_encode(['email'=>'fix@x.com','plan'=>'pro-renewal']),['Authorization: Bearer adm-tok']);
        eq(200, $c); $lm = new LicenseManager($b['license_key'], $pub);
        eq(['share','intake'], $lm->modules()); ok($lm->daysLeft() > 725);
    });
    test('admin /revoke of a base cascades to its renewal', function () {
        paid('ord_rv_base','prod_pl','rv@x.com'); paid('ord_rv_ren','prod_pr','rv@x.com');
        $base = byPlan('rv@x.com','pro-lifetime')[0];
        req('POST','/revoke',json_encode(['jti'=>$base['jti'],'status'=>'revoked']),['Authorization: Bearer adm-tok']);
        eq('revoked', byPlan('rv@x.com','pro-renewal')[0]['status']);
    });
} finally {
    proc_terminate($proc); proc_close($proc);
}
echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
