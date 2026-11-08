<?php

declare(strict_types=1);

require_once __DIR__.'/support/Harness.php';
use FluxFiles\LicenseServer\LicenseStore;

$h=new Harness();$port=$h->api();$h->call($port,'GET','/admin/meta');$db=$h->db();
$month=(new DateTimeImmutable('first day of this month',new DateTimeZone('Asia/Ho_Chi_Minh')))->setTime(0,0)->getTimestamp();
$last=(new DateTimeImmutable('@'.$month))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->modify('-1 month')->getTimestamp();
$h->test('Accounting fixtures keep currencies separate, month refunds and taxes correct, test/manual excluded',function() use($h,$port,$db,$month,$last):void {
    $s=new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);
    foreach ([['usd-1','usd',10000,1000,$month+1,0,0,null,'FOUNDING40'],['usd-refund','usd',5000,500,$last+1,2200,200,$month+2,'FOUNDING40'],['vnd-1','vnd',100000,10000,$month+1,0,0,null,null]] as [$id,$c,$net,$tax,$paid,$refund,$refundTax,$refunded,$discount]) {
        $s->upsertOrder(['order_id'=>$id,'gateway'=>'polar','currency'=>$c,'status'=>$refund?'partially_refunded':'paid','paid_at'=>$paid,'refunded_at'=>$refunded,'net_amount'=>$net,'tax_amount'=>$tax,'total_amount'=>$net+$tax,'refunded_amount'=>$refund,'refunded_tax_amount'=>$refundTax,'discount_code'=>$discount]);
    }
    $test=$h->call($port,'POST','/issue',['email'=>'test@example.test','plan'=>'pro','is_test'=>true])['body']['record']['jti'];
    $s->upsertOrder(['order_id'=>'test','gateway'=>'polar','currency'=>'usd','status'=>'paid','paid_at'=>$month+1,'net_amount'=>999999,'jti'=>$test]);
    $s->upsertOrder(['order_id'=>'manual','gateway'=>'manual','currency'=>'usd','status'=>'paid','paid_at'=>$month+1,'net_amount'=>999999]);
    $r=$h->call($port,'GET','/admin/stats/revenue');Harness::eq(200,$r['code']);$by=array_column($r['body']['currencies'],null,'currency');
    Harness::eq(8000,$by['usd']['net_month']);Harness::eq(5000,$by['usd']['net_previous']);Harness::eq(11000,$by['usd']['gross_month']);Harness::eq(1000,$by['usd']['tax_month']);Harness::eq(100000,$by['vnd']['net_month']);
    Harness::eq(2,$r['body']['founding'][0]['paid']);Harness::eq(2,$r['body']['founding'][0]['used']);Harness::eq(13000,$r['body']['founding'][0]['net']['usd']);
    Harness::eq(400,$h->call($port,'GET','/admin/stats/revenue?from=2020-01-01&to=2026-01-01')['code']);
});
$h->test('MRR follows latest live subscription and divides annual plans by twelve',function() use($h,$port,$db,$month):void {
    $r=$h->call($port,'POST','/issue',['email'=>'sub@example.test','plan'=>'pro-monthly','deliver'=>'manual'])['body']['record'];
    $db->prepare("UPDATE licenses SET gateway='polar',subscription_id='mrr-sub' WHERE jti=?")->execute([$r['jti']]);
    (new LicenseStore($h->env['FLUXFILES_LICENSE_DB']))->upsertOrder(['order_id'=>'mrr-order','gateway'=>'polar','currency'=>'usd','status'=>'paid','paid_at'=>time()-1,'net_amount'=>1900,'subscription_id'=>'mrr-sub','jti'=>$r['jti']]);
    $out=$h->call($port,'GET','/admin/stats/revenue')['body'];$by=array_column($out['currencies'],null,'currency');Harness::eq(1900,$by['usd']['mrr']);Harness::eq(22800,$by['usd']['arr']);
    $h->call($port,'POST','/revoke',['subscription_id'=>'mrr-sub']);$out=$h->call($port,'GET','/admin/stats/revenue')['body'];Harness::eq(0,array_column($out['currencies'],null,'currency')['usd']['mrr']);
});
$h->test('Install and mail fixture definitions, send and delivery rates',function() use($h,$port,$db):void {
    $a=$h->call($port,'POST','/issue',['email'=>'active@example.test','plan'=>'pro'])['body']['record']['jti'];
    $b=$h->call($port,'POST','/issue',['email'=>'never@example.test','plan'=>'pro'])['body']['record']['jti'];
    $c=$h->call($port,'POST','/issue',['email'=>'quiet@example.test','plan'=>'pro'])['body']['record']['jti'];
    $h->call($port,'POST','/update-status',['jti'=>$a,'module'=>'share'],'status');
    $db->prepare("UPDATE ops_state SET value=? WHERE key='telemetry_migration'")->execute([json_encode(['at'=>time()-9*86400])]);
    $db->prepare("UPDATE licenses SET gateway='polar' WHERE jti=?")->execute([$b]);
    $db->prepare('UPDATE licenses SET created_at=? WHERE jti=?')->execute([time()-8*86400,$b]);$db->prepare('UPDATE licenses SET first_seen_at=?,last_seen_at=? WHERE jti=?')->execute([time()-60*86400,time()-31*86400,$c]);
    $r=$h->call($port,'GET','/admin/stats/installs')['body'];Harness::eq(1,$r['active_30d']);Harness::eq(1,$r['never_installed']);Harness::eq(1,$r['gone_quiet']);
    foreach ([['delivered',1],['bounced',1],['failed',0]] as [$status,$ok]) { $db->prepare("INSERT INTO mail_log(at,kind,recipient,transport,ok,status,actor) VALUES(?,'licence','a***@example.test','resend',?,?,'system')")->execute([time(),$ok,$status]); }
    $r=$h->call($port,'GET','/admin/stats/mail')['body']['periods'][30][0];Harness::eq(3,$r['sent']);Harness::eq(2,$r['accepted']);Harness::eq(.5,$r['delivery_rate']);Harness::eq(.5,$r['bounce_rate']);
});
$h->test('Guarded migration on an old DB copy preserves key, repeat migrations are safe',function() use($h):void {
    $old=$h->dir.'/old.sqlite';$db=new PDO('sqlite:'.$old);$db->exec("CREATE TABLE licenses(id INTEGER PRIMARY KEY AUTOINCREMENT,jti TEXT UNIQUE NOT NULL,email TEXT NOT NULL,customer TEXT,plan TEXT,edition TEXT,modules TEXT,enforcement TEXT,issued INTEGER,expires INTEGER,license_key TEXT NOT NULL,gateway TEXT,order_id TEXT,status TEXT DEFAULT 'active',created_at INTEGER)");
    $db->exec("INSERT INTO licenses(jti,email,license_key) VALUES('legacy','legacy@example.test','unchanged')");$db=null;copy($old,$h->dir.'/copy.sqlite');
    for($i=0;$i<2;$i++) { $s=new LicenseStore($h->dir.'/copy.sqlite');Harness::eq('unchanged',$s->findByJti('legacy')['license_key']);Harness::eq(4,(int)$s->pdo()->query('PRAGMA user_version')->fetchColumn()); }
    $db=new PDO('sqlite:'.$old);Harness::eq(0,(int)$db->query('PRAGMA user_version')->fetchColumn());
});
$h->test('Order backfill dry-run writes nothing, apply uses fake Polar and is idempotent',function() use($h,$port,$db):void {
    $r=$h->call($port,'POST','/issue',['email'=>'backfill@example.test','plan'=>'pro','order_id'=>'backfill-order'])['body']['record'];
    $db->prepare("UPDATE licenses SET gateway='polar' WHERE jti=?")->execute([$r['jti']]);
    $fake=$h->dir.'/polar.php';file_put_contents($fake,'<?php header("Content-Type: application/json");echo json_encode(["id"=>"backfill-order","status"=>"paid","currency"=>"eur","net_amount"=>1234,"total_amount"=>1300,"created_at"=>gmdate("c")]);');$polar=$h->start($fake,['PATH'=>(string)getenv('PATH')]);
    $run=function(bool $apply) use($h,$polar):array {
        $args=[PHP_BINARY,dirname(__DIR__).'/backfill-orders.php','--api-base=http://127.0.0.1:'.$polar,'--delay-ms=0'];if($apply) { $args[]='--apply'; }
        $p=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['POLAR_TOKEN'=>'throwaway-backfill-token']+$h->env);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);foreach($pipes as $fp) { fclose($fp); }return [proc_close($p),$out];
    };
    $before=(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();$out=$run(false);Harness::eq(0,$out[0],$out[1]);Harness::eq($before,(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    Harness::eq(0,$run(true)[0]);Harness::eq(1234,(int)$db->query("SELECT net_amount FROM orders WHERE order_id='backfill-order'")->fetchColumn());Harness::eq(0,$run(true)[0]);Harness::eq($before+1,(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn());
});
$h->finish();
