<?php

declare(strict_types=1);

require_once __DIR__.'/support/Harness.php';
require_once dirname(__DIR__).'/Plans.php';
require_once dirname(__DIR__).'/Stats.php';
require_once dirname(__DIR__).'/Analytics.php';
require_once dirname(__DIR__).'/VersionRange.php';
use FluxFiles\LicenseServer\{LicenseStore,LicenseSigner,LicenseIssuer,PolarWebhook,Stats,Analytics,VersionRange};

$h=new Harness();$s=new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);$db=$s->pdo();
$issuer=new LicenseIssuer(new LicenseSigner($h->secret),$s);
$issue=static fn(string $email,string $plan='pro',array $extra=[])=>$issuer->issue($extra+['email'=>$email,'plan'=>$plan,'gateway'=>'polar','order_id'=>bin2hex(random_bytes(8))])['record'];
$h->test('A2 refund timestamp uses Polar modified_at then envelope timestamp; duplicate/refunded backfill stays historical',function()use($s,$db):void {
    $body=['type'=>'order.refunded','timestamp'=>'2025-05-12T00:00:00Z','data'=>['id'=>'historic-refund','modified_at'=>'2025-05-11T00:00:00Z','refunded_amount'=>1100,'refunded_tax_amount'=>100]];
    $facts=PolarWebhook::orderFacts($body);Harness::eq(strtotime('2025-05-11 UTC'),$facts['refunded_at']);$s->upsertOrder($facts);$first=$facts['refunded_at'];
    $body['data']['modified_at']='2026-11-01T00:00:00Z';$s->upsertOrder(PolarWebhook::orderFacts($body));Harness::eq($first,(int)$db->query("SELECT refunded_at FROM orders WHERE order_id='historic-refund'")->fetchColumn());Harness::eq(1,(int)$db->query("SELECT COUNT(*) FROM order_refunds WHERE order_id='historic-refund'")->fetchColumn());
    unset($body['data']['modified_at']);Harness::eq(strtotime('2025-05-12 UTC'),PolarWebhook::orderFacts($body)['refunded_at']);
});
$h->test('B12 partial refunds retain their own months even when cumulative snapshots arrive out of order',function()use($s,$db):void {
    $base=['order_id'=>'partial-months','gateway'=>'polar','currency'=>'usd','plan'=>'pro','status'=>'paid','paid_at'=>strtotime('2025-05-01T00:00:00+07:00'),'net_amount'=>10000,'total_amount'=>11000];$s->upsertOrder($base);
    $s->upsertOrder(['order_id'=>'partial-months','status'=>'partially_refunded','refunded_at'=>strtotime('2025-06-15T00:00:00+07:00'),'refunded_amount'=>770,'refunded_tax_amount'=>70]);
    $s->upsertOrder(['order_id'=>'partial-months','status'=>'partially_refunded','refunded_at'=>strtotime('2025-05-15T00:00:00+07:00'),'refunded_amount'=>330,'refunded_tax_amount'=>30]);
    $s->upsertOrder(['order_id'=>'partial-months','status'=>'partially_refunded','refunded_at'=>strtotime('2025-07-15T00:00:00+07:00'),'refunded_amount'=>770,'refunded_tax_amount'=>70]);
    $r=(new Stats($db))->revenue(['from'=>'2025-05-01','to'=>'2025-06-30','currency'=>'usd']);$rows=array_column($r['series'],null,'month');Harness::eq(9700,$rows['2025-05']['net']);Harness::eq(-400,$rows['2025-06']['net']);Harness::eq(2,(int)$db->query("SELECT COUNT(*) FROM order_refunds WHERE order_id='partial-months'")->fetchColumn());
});
$h->test('B4 additive v4 migration uses nondeprecated SQLite functions and preserves signed records',function()use($s,$db,$h,$issue):void {
    $record=$issue('migration-review@example.test');$before=$record['license_key'];
    set_error_handler(static function(int $code,string $message) { if (!(error_reporting() & $code)) { return false; }throw new ErrorException($message,0,$code); });
    try { $again=new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);Harness::eq(4,(int)$again->pdo()->query('PRAGMA user_version')->fetchColumn());Harness::eq($before,$again->findByJti($record['jti'])['license_key']); }finally { restore_error_handler(); }
    $plan=$db->query("EXPLAIN QUERY PLAN SELECT id FROM webhook_events WHERE source='polar' AND webhook_id='x' AND verify='bad'")->fetchAll();Harness::ok(str_contains(json_encode($plan),'idx_webhook_identity'));
});
$h->test('B5 grace period excludes existing and manual licences from never-installed alerts',function()use($s,$db,$issue):void {
    $r=$issue('legacy-observation@example.test');$manual=$issue('manual-observation@example.test','pro',['gateway'=>'manual']);
    $db->prepare('UPDATE licenses SET created_at=? WHERE jti IN (?,?)')->execute([time()-30*86400,$r['jti'],$manual['jti']]);
    Harness::eq(0,(new Stats($db))->installs()['never_installed']);$s->sweepOpsItems();Harness::eq(0,(int)$db->query("SELECT COUNT(*) FROM ops_items WHERE kind='never_installed'")->fetchColumn());
    $s->state('telemetry_migration',['at'=>time()-8*86400]);Harness::eq(1,(new Stats($db))->installs()['never_installed']);$s->sweepOpsItems();Harness::eq(1,(int)$db->query("SELECT COUNT(*) FROM ops_items WHERE kind='never_installed'")->fetchColumn());
});
$h->test('B7/B12 stale revocation is monotonic and paid-period end controls the 48-hour rule',function()use($s,$db,$issue):void {
    foreach(['short'=>24,'long'=>72] as $name=>$hours){$r=$issue($name.'-revoke@example.test','pro-monthly',['subscription_id'=>'review-'.$name]);$db->prepare('UPDATE licenses SET expires=? WHERE jti=?')->execute([time()+10*86400,$r['jti']]);
        $s->subscriptionEvent(['type'=>'subscription.updated','data'=>['id'=>'review-'.$name,'status'=>'active','modified_at'=>gmdate('c')]],'new-'.$name);
        $s->subscriptionEvent(['type'=>'subscription.revoked','data'=>['id'=>'review-'.$name,'modified_at'=>gmdate('c',time()-3600),'current_period_end'=>gmdate('c',time()+$hours*3600),'ended_at'=>gmdate('c')]],'stale-'.$name);
        Harness::eq($name==='short'?'active':'revoked',$s->findByJti($r['jti'])['status']);
        $s->subscriptionEvent(['type'=>'subscription.uncanceled','data'=>['id'=>'review-'.$name,'modified_at'=>gmdate('c',time()+60)]],'uncancel-'.$name);
        $q=$db->prepare('SELECT status FROM subscriptions WHERE subscription_id=?');$q->execute(['review-'.$name]);Harness::eq('revoked',$q->fetchColumn());
    }
});
$h->test('B8 discount usage counts initial distinct subscriptions, never billing cycles',function()use($s,$db):void {
    putenv('FLUXFILES_ADMIN_TRACKED_DISCOUNTS=REVIEW40:10');
    foreach(['subscription_create','subscription_cycle','subscription_cycle','subscription_create'] as $i=>$reason){$s->upsertOrder(['order_id'=>'discount-'.$i,'gateway'=>'polar','currency'=>'usd','status'=>'paid','paid_at'=>time(),'net_amount'=>1000,'discount_code'=>'REVIEW40','subscription_id'=>'one-subscription','billing_reason'=>$reason]);}
    Harness::eq(1,(new Stats($db))->founding()[0]['used']);putenv('FLUXFILES_ADMIN_TRACKED_DISCOUNTS');
});
$h->test('B10 installs and field versions survive monthly and one-time renewal key rotations',function()use($s,$db,$issue):void {
    $old=$issue('group@example.test','pro-monthly',['subscription_id'=>'group-sub']);$head=$issue('group@example.test','pro-monthly',['subscription_id'=>'group-sub']);
    $db->prepare('UPDATE licenses SET first_seen_at=?,last_seen_at=? WHERE jti=?')->execute([time()-86400,time(),$old['jti']]);
    $db->prepare('INSERT INTO install_pings VALUES(?,?,?,?,?,?,0,1,1,?,?)')->execute([gmdate('Y-m-d'),$old['jti'],'share','old-net','1.2.0','1.2.0',time()-86400,time()]);
    $stats=(new Stats($db))->installs();Harness::eq(1,$stats['active_30d']);$versions=(new Analytics($s))->versions();$share=array_values(array_filter($versions['installs'],static fn(array $r):bool=>$r['component']==='share'));Harness::eq(1,count($share));Harness::eq($head['jti'],$share[0]['jti']);
    $base=$issue('renewal-group@example.test','pro-lifetime');$renewal=$issue('renewal-group@example.test','pro-renewal');$db->prepare('UPDATE licenses SET first_seen_at=?,last_seen_at=? WHERE jti=?')->execute([time()-86400,time(),$base['jti']]);Harness::eq(2,(new Stats($db))->installs()['active_30d']);
});
$h->test('B11 expired bodies are removed on every record, independent of random pruning',function()use($s,$db):void {
    $db->prepare("INSERT INTO webhook_events(source,webhook_id,received_at,last_at,verify,outcome,http_status,body,body_expires_at) VALUES('polar','expired-review',?,?,'ok','error',500,?,1)")->execute([time(),time(),gzcompress('private payload')]);
    $s->recordEvent('polar','fresh-review','ok',['type'=>'ignored','data'=>[]],'{}','ignored',200);Harness::eq(null,$db->query("SELECT body FROM webhook_events WHERE webhook_id='expired-review'")->fetchColumn());
});
$h->test('B12 install day uses local midnight, not the UTC date of the ping',function()use($s,$db,$issue):void {
    $r=$issue('midnight@example.test');$at=strtotime('2025-08-01T00:30:00+07:00');$db->prepare('INSERT INTO install_pings VALUES(?,?,?,?,?,?,0,1,1,?,?)')->execute(['2025-07-31',$r['jti'],'share','midnight','1.2.0','1.2.0',$at,$at]);
    $series=(new Stats($db))->installs(['from'=>'2025-08-01','to'=>'2025-08-01'])['series'];Harness::eq([['day'=>'2025-08-01','active'=>1]],$series);
    Harness::ok(!VersionRange::matches('1.1','<1.1.0'));Harness::ok(VersionRange::matches('1.1','=1.1.0'));
});
$h->test('B12/B14 Telegram-off retention bounds queued rows and old dedup receipts',function()use($s,$db):void {
    $s->enqueueAlert('test','info','old-unread','Old unread');$db->prepare("UPDATE ops_outbox SET created_at=? WHERE dedup_key='old-unread'")->execute([time()-31*86400]);
    $s->state('alert-dedup:old-receipt',['test'=>true]);$db->prepare("UPDATE ops_state SET updated_at=? WHERE key='alert-dedup:old-receipt'")->execute([time()-731*86400]);$s->prune();
    Harness::eq('expired',$db->query("SELECT last_error FROM ops_outbox WHERE dedup_key='old-unread'")->fetchColumn());Harness::eq(0,(int)$db->query("SELECT COUNT(*) FROM ops_state WHERE key='alert-dedup:old-receipt'")->fetchColumn());
});
$h->finish();
