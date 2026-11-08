<?php

declare(strict_types=1);

require_once __DIR__.'/support/Harness.php';
require_once dirname(__DIR__).'/Analytics.php';
use FluxFiles\LicenseServer\{LicenseStore,Analytics};
use FluxFiles\LicenseServer\Client\Mask;

$h=new Harness();$port=$h->api(['FLUXFILES_LICENSE_BACKUP_STAGE'=>$h->dir.'/stage']);$s=new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);$db=$s->pdo();$jtis=[];
$paid=static function(string $id,string $email,string $sid='',array $extra=[])use($h,$port):array {
    return $h->hook($port,['type'=>'order.paid','data'=>$extra+['id'=>$id,'product_id'=>'pm','status'=>'paid','subscription_id'=>$sid,'billing_reason'=>'subscription_create','customer_id'=>'customer-'.$id,'customer'=>['email'=>$email,'name'=>'Buyer'],'currency'=>'usd','subtotal_amount'=>2000,'discount_amount'=>0,'net_amount'=>2000,'tax_amount'=>200,'total_amount'=>2200,'created_at'=>gmdate('c')]],'paid-'.$id);
};
$sub=static fn(string $type,string $sid,string $id,array $extra=[])=>$h->hook($port,['type'=>'subscription.'.$type,'data'=>$extra+['id'=>$sid,'modified_at'=>gmdate('c')]],$id);
$h->test('Subscription cancellation suppresses pre-expiry mail, uncancel restores, stale events cannot undo revoke',function()use($h,$port,$s,$db,$paid,$sub,&$jtis):void {
    Harness::eq(200,$paid('a','a@example.test','sub-a')['code']);$r=$s->findByOrder('polar','a');$jtis['a']=$r['jti'];
    $db->prepare('UPDATE licenses SET expires=? WHERE jti=?')->execute([time()+5*86400,$r['jti']]);
    Harness::eq(true,$sub('canceled','sub-a','cancel-a')['body']['subscription_updated']);Harness::eq('active',$s->findByJti($r['jti'])['status']);Harness::ok($s->reminderSuppressed($r));
    Harness::ok(!in_array($r['jti'],array_column($s->needingReminder([7],time()),'jti'),true));
    $sub('uncanceled','sub-a','uncancel-a');Harness::ok(!$s->reminderSuppressed($r));Harness::ok(in_array($r['jti'],array_column($s->needingReminder([7],time()),'jti'),true));
    $sub('revoked','sub-a','revoke-a');Harness::eq('revoked',$s->findByJti($r['jti'])['status']);
    $sub('updated','sub-a','stale-a',['status'=>'active','modified_at'=>gmdate('c',time()-3600)]);Harness::eq('revoked',$db->query("SELECT status FROM subscriptions WHERE subscription_id='sub-a'")->fetchColumn());
    Harness::eq(false,$sub('canceled','unknown','unknown-a')['body']['subscription_updated']);
});
$h->test('Revoked subscription with at most 48h left keeps key usable, past_due is visible and excluded from MRR',function()use($h,$port,$s,$db,$paid,$sub,&$jtis):void {
    $paid('b','b@example.test','sub-b');$r=$s->findByOrder('polar','b');$jtis['b']=$r['jti'];$db->prepare('UPDATE licenses SET expires=? WHERE jti=?')->execute([time()+47*3600,$r['jti']]);
    $sub('revoked','sub-b','revoke-b');Harness::eq('active',$s->findByJti($r['jti'])['status']);
    $paid('c','c@example.test','sub-c');$r=$s->findByOrder('polar','c');$jtis['c']=$r['jti'];$sub('past_due','sub-c','due-c');
    $stats=$h->call($port,'GET','/admin/stats/revenue');Harness::eq(200,$stats['code']);Harness::eq(1,count($stats['body']['past_due']));
    foreach($stats['body']['currencies'] as $row) {Harness::eq(0,$row['mrr']);}
});
$h->test('Refund with active subscription creates a deduplicated operator item, canceled webhook resolves it',function()use($h,$port,$s,$db,$paid,$sub):void {
    $paid('refund','refund@example.test','sub-refund');
    $event=['type'=>'order.refunded','data'=>['id'=>'refund','status'=>'refunded','product_id'=>'pm','subscription_id'=>'sub-refund','currency'=>'usd','total_amount'=>2200,'refunded_amount'=>2200,'refunded_tax_amount'=>200,'customer'=>['email'=>'refund@example.test']]];
    Harness::eq(200,$h->hook($port,$event,'refund-one')['code']);$h->hook($port,$event,'refund-two');
    Harness::eq(1,(int)$db->query("SELECT COUNT(*) FROM ops_items WHERE kind='refund_sub_active' AND resolved_at IS NULL")->fetchColumn());
    $sub('canceled','sub-refund','refund-canceled');Harness::eq(0,(int)$db->query("SELECT COUNT(*) FROM ops_items WHERE kind='refund_sub_active' AND resolved_at IS NULL")->fetchColumn());
});
$h->test('Customer hash detail, identity conflicts, notes and currency-separated net; no signed keys in reads',function()use($h,$port,$s,$db,$paid,&$jtis):void {
    $paid('d','c@example.test','sub-d',['customer_id'=>'other-customer','currency'=>'eur']);$jtis['d']=$s->findByOrder('polar','d')['jti'];
    $hash=substr(hash('sha256','c@example.test'),0,16);$list=$h->call($port,'GET','/admin/customers?q=c%40example.test')['body'];Harness::eq(1,count($list['rows']));Harness::eq(true,$list['rows'][0]['identity_conflict']);Harness::eq(['usd'=>2000,'eur'=>2000],$list['rows'][0]['net']);
    Harness::eq(200,$h->call($port,'POST','/admin/customers/'.$hash.'/note',['note'=>'<b>operator only</b>'])['code']);$r=$h->call($port,'GET','/admin/customers/'.$hash);Harness::eq('<b>operator only</b>',$r['body']['note']['note']);Harness::eq(2,count($r['body']['licences']));Harness::ok(!preg_match(Mask::KEY,$r['raw']));
    Harness::eq(400,$h->call($port,'POST','/admin/customers/'.$hash.'/note',['note'=>str_repeat('x',501)])['code']);Harness::eq(404,$h->call($port,'GET','/admin/customers/0000000000000000')['code']);
});
$h->test('Counts-only ops API is scoped and idempotent, separates download sources and update checks',function()use($h,$port,$s,$db):void {
    $ingest=['type'=>'downloads_daily','day'=>gmdate('Y-m-d'),'rows'=>[['module'=>'share','version'=>'1.2.3','n'=>4]]];
    Harness::eq(401,$h->call($port,'POST','/admin/ops/ingest',$ingest)['code']);for($i=0;$i<2;$i++){Harness::eq(200,$h->call($port,'POST','/admin/ops/ingest',$ingest,'ops')['code']);}
    $s->recordDownload('share','pro_link_claim','1.2.3');$s->recordDownload('share','pro_link_download','1.2.3');$stats=$h->call($port,'GET','/admin/stats/downloads')['body'];$sources=array_column($stats['series'],'n','source');Harness::eq(4,$sources['zip_log']);Harness::eq(1,$sources['pro_link_claim']);Harness::eq(1,$sources['pro_link_download']);
    $ingest['rows'][0]['email']='raw@example.test';Harness::eq(400,$h->call($port,'POST','/admin/ops/ingest',$ingest,'ops')['code']);
    foreach(['customers','versions','downloads','growth','demo','anomalies'] as $kind){$r=$h->call($port,'GET','/admin/stats/'.$kind);Harness::eq(200,$r['code'],$kind);Harness::ok(!preg_match(Mask::KEY,$r['raw']),$kind);}
});
$h->test('Reported versions use latest ping, unknowns explicit; advisories and notice previews deduplicate emails',function()use($h,$port,$db,$s,&$jtis):void {
    foreach(['b'=>'1.2.2','c'=>null,'d'=>'1.2.3'] as $id=>$version){$db->prepare('INSERT INTO install_pings VALUES(?,?,?,?,?,?,0,1,1,?,?)')->execute([gmdate('Y-m-d'),$jtis[$id],'share','network-'.$id,$version,'1.2.1',time(),time()]);}
    $ad=['id'=>'FF-2026-1','component'=>'share','affected'=>'<1.2.3','fixed_in'=>'1.2.3','severity'=>'high','title'=>'Security fix','url'=>'https://example.test/fix'];Harness::eq(200,$h->call($port,'POST','/admin/advisories',$ad)['code']);
    $versions=$h->call($port,'GET','/admin/stats/versions')['body'];Harness::eq(1,$versions['advisories'][0]['affected_count']);Harness::eq(1,$versions['advisories'][0]['unknown_count']);
    $r=$h->call($port,'GET','/admin/notices/preview?advisory=FF-2026-1&include_unknown=1');Harness::eq(200,$r['code']);Harness::eq(2,$r['body']['recipients']);Harness::ok(!str_contains($r['raw'],'b@example.test'));Harness::ok(str_contains($r['body']['body_text'],'1.2.3'));
    $ad['affected']='^1.2.3';Harness::eq(400,$h->call($port,'POST','/admin/advisories',$ad)['code']);
});
$notice=[];
$h->test('Notices require exact confirmation and unchanged preview, queue once with immutable content',function()use($h,$port,$db,&$notice,&$jtis):void {
    $preview=$h->call($port,'GET','/admin/notices/preview?advisory=FF-2026-1&include_unknown=1')['body'];
    $notice=['advisory'=>'FF-2026-1','include_unknown'=>true,'subject'=>'Fix {fixed_in}','body_text'=>'Your version {version}; update {fixed_in}. {url}','request_id'=>str_repeat('a',32),'confirmation'=>'FF-2026-1','preview_fingerprint'=>$preview['preview_fingerprint']];
    Harness::eq(400,$h->call($port,'POST','/admin/notices/send',array_replace($notice,['confirmation'=>'wrong']))['code']);Harness::eq(409,$h->call($port,'POST','/admin/notices/send',array_replace($notice,['preview_fingerprint'=>str_repeat('0',64)]))['code']);
    $db->prepare("UPDATE install_pings SET version='1.2.1' WHERE jti=? AND module='share'")->execute([$jtis['b']]);
    Harness::eq(409,$h->call($port,'POST','/admin/notices/send',$notice)['code']);
    $db->prepare("UPDATE install_pings SET version='1.2.2' WHERE jti=? AND module='share'")->execute([$jtis['b']]);
    $r=$h->call($port,'POST','/admin/notices/send',$notice);Harness::eq(200,$r['code']);Harness::eq(2,$r['body']['queued']);
    $db->exec("UPDATE advisories SET fixed_in='9.9.9'");$r=$h->call($port,'POST','/admin/notices/send',$notice);Harness::eq(200,$r['code']);Harness::eq(true,$r['body']['reused']);Harness::eq(2,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE channel='email'")->fetchColumn());
    Harness::eq(400,$h->call($port,'POST','/admin/notices/send',array_replace($notice,['subject'=>'different']))['code']);
});
$h->test('Mail jobs retry failed sends and complete once with provider IDs and approved advisory snapshot',function()use($h,$port,$db):void {
    $db->exec("UPDATE ops_outbox SET next_at=1 WHERE channel='email'");
    $fake=$h->dir.'/notice-provider.php';file_put_contents($fake,'<?php $b=file_get_contents("php://input");file_put_contents(__DIR__."/notice-capture.jsonl",$b."\n",FILE_APPEND);header("Content-Type: application/json");echo json_encode(["id"=>"notice-".substr(hash("sha256",$b),0,12)]);');$provider=$h->start($fake,['PATH'=>(string)getenv('PATH')]);
    $bad=$h->api(['FLUXFILES_MAIL_TRANSPORT'=>'resend','FLUXFILES_RESEND_API_KEY'=>'fake','FLUXFILES_RESEND_API_BASE'=>'http://127.0.0.1:1']);$out=$h->call($bad,'POST','/admin/ops/mail-jobs',[],'ops');Harness::eq(200,$out['code']);Harness::eq(0,$out['body']['sent']);Harness::eq(2,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE channel='email' AND attempts=1 AND sent_at IS NULL AND dropped_at IS NULL")->fetchColumn());
    $sender=$h->api(['FLUXFILES_MAIL_TRANSPORT'=>'resend','FLUXFILES_RESEND_API_KEY'=>'fake','FLUXFILES_RESEND_API_BASE'=>'http://127.0.0.1:'.$provider]);$db->exec("UPDATE ops_outbox SET next_at=1 WHERE channel='email'");$out=$h->call($sender,'POST','/admin/ops/mail-jobs',[],'ops');Harness::eq(2,$out['body']['sent']);Harness::eq('done',$db->query('SELECT status FROM notices')->fetchColumn());Harness::eq(2,(int)$db->query('SELECT sent FROM notices')->fetchColumn());
    $captured=file_get_contents($h->dir.'/notice-capture.jsonl');Harness::ok(str_contains($captured,'1.2.3'));Harness::ok(!str_contains($captured,'9.9.9'));Harness::ok(!preg_match(Mask::KEY,$captured));Harness::eq(0,$h->call($sender,'POST','/admin/ops/mail-jobs',[],'ops')['body']['claimed']);Harness::eq(2,(int)$db->query("SELECT COUNT(*) FROM mail_log WHERE kind='notice' AND provider_id IS NOT NULL AND ok=1")->fetchColumn());
});
$h->test('Notice jobs stop for revoked recipients and exhaust the tenth failed attempt without duplicate counters',function()use($h,$port,$db,&$notice,&$jtis):void {
    $preview=$h->call($port,'GET','/admin/notices/preview?advisory=FF-2026-1&include_unknown=1')['body'];$body=array_replace($notice,['request_id'=>str_repeat('b',32),'preview_fingerprint'=>$preview['preview_fingerprint']]);$queued=$h->call($port,'POST','/admin/notices/send',$body);Harness::eq(2,$queued['body']['queued']);$id=$queued['body']['id'];
    $db->prepare("UPDATE licenses SET status='revoked' WHERE jti=?")->execute([$jtis['b']]);$db->exec("UPDATE ops_outbox SET attempts=9,next_at=1 WHERE channel='email' AND sent_at IS NULL");$bad=$h->api(['FLUXFILES_MAIL_TRANSPORT'=>'resend','FLUXFILES_RESEND_API_KEY'=>'fake','FLUXFILES_RESEND_API_BASE'=>'http://127.0.0.1:1']);$out=$h->call($bad,'POST','/admin/ops/mail-jobs',[],'ops');Harness::eq(2,$out['body']['failed']);$row=$db->query('SELECT * FROM notices WHERE id='.(int)$id)->fetch();Harness::eq(2,(int)$row['failed']);Harness::eq('done',$row['status']);Harness::eq(0,$h->call($bad,'POST','/admin/ops/mail-jobs',[],'ops')['body']['claimed']);
});
$h->test('Cohorts deduplicate customer IDs across emails and use unexpired entitlement at each month end',function()use($h,$port,$db,$paid,$s):void {
    $start=(new DateTimeImmutable('first day of this month',new DateTimeZone('Asia/Ho_Chi_Minh')))->setTime(0,0)->modify('-2 months');$paidAt=$start->modify('+1 day')->getTimestamp();$expires=$start->modify('+1 month +15 days')->getTimestamp();
    foreach(['one','two'] as $id){$paid('cohort-'.$id,'cohort-'.$id.'@example.test','',['customer_id'=>'cohort-person','created_at'=>gmdate('c',$paidAt)]);$jti=$s->findByOrder('polar','cohort-'.$id)['jti'];$db->prepare('UPDATE licenses SET issued=?,expires=? WHERE jti=?')->execute([$paidAt,$expires,$jti]);}
    $r=$h->call($port,'GET','/admin/stats/customers?from='.$start->format('Y-m-d').'&to='.gmdate('Y-m-d'))['body'];$members=array_values(array_filter($r['cohorts'],static fn(array $r):bool=>$r['cohort']===$start->format('Y-m')));Harness::eq(1,(int)$members[0]['customers']);Harness::eq(100,(int)$members[0]['rate']);Harness::eq(0,(int)$members[1]['rate']);Harness::eq(1,(int)$r['series'][0]['new']);
});
$h->test('CSV exports BOM and major amounts with formula guard, excludes manual/test and audits; backup is staged 0600',function()use($h,$port,$paid,$db):void {
    $paid('formula','formula@example.test','',['customer'=>['email'=>'formula@example.test','name'=>'=CMD()']]);
    $r=$h->call($port,'GET','/admin/export/orders.csv');Harness::eq(200,$r['code']);Harness::ok(str_starts_with($r['raw'],"\xEF\xBB\xBF"));Harness::ok(str_contains($r['raw'],"'=CMD()"));Harness::ok(str_contains($r['raw'],'20.00'));Harness::ok(!preg_match(Mask::KEY,$r['raw']));Harness::ok(stripos($r['headers'],'text/csv')!==false);
    Harness::ok((int)$db->query("SELECT COUNT(*) FROM admin_audit WHERE action='export.orders'")->fetchColumn()>0);
    $r=$h->call($port,'POST','/admin/backup');Harness::eq(200,$r['code']);Harness::eq('ok',$r['body']['integrity']);$file=$h->dir.'/stage/'.$r['body']['file'];Harness::ok(is_file($file));Harness::eq(0600,fileperms($file)&0777);Harness::eq('ok',(new PDO('sqlite:'.$file))->query('PRAGMA integrity_check')->fetchColumn());
});
$h->test('Anomaly thresholds are strictly greater than configured values and never revoke licences',function()use($h,$port,$s,$db,&$jtis):void {
    for($i=0;$i<21;$i++){$db->prepare('INSERT INTO install_pings VALUES(?,?,?,?,?,?,0,1,1,?,?)')->execute([gmdate('Y-m-d'),$jtis['d'],'intake',sprintf('%016x',$i),'1.0.0','1.0.0',time(),time()]);}
    for($i=0;$i<4;$i++){$s->recordActivity('refresh','rate_limited',$jtis['d']);}for($i=0;$i<21;$i++){$s->recordActivity('pro_download','refused');}
    $r=$h->call($port,'GET','/admin/stats/anomalies')['body'];Harness::eq(1,count($r['spread']));Harness::eq(1,count($r['refresh']));Harness::eq(1,count($r['guessing']));Harness::eq(false,$r['automatic_revocation']);Harness::eq('active',$s->findByJti($jtis['d'])['status']);
});
$h->test('Viewer API permits reads and audit only, rejects reveal/export/mutations; root token ignores role spoofing',function()use($h,$port,&$jtis):void {
    $call=static fn(string $method,string $path,array $b=[])=>$h->request($port,$method,$path,$method==='GET'?null:$b,['Authorization: Bearer ui-secret','X-FluxFiles-Role: viewer','Content-Type: application/json']);
    Harness::eq(200,$call('GET','/admin/customers')['code']);foreach([['GET','/admin/export/orders.csv'],['POST','/admin/licences/'.$jtis['d'].'/reveal'],['POST','/admin/backup'],['POST','/admin/notices/send'],['POST','/revoke']] as [$m,$p]){Harness::eq(403,$call($m,$p)['code'],$p);}
    Harness::eq(200,$h->request($port,'GET','/admin/meta',null,['Authorization: Bearer admin-secret','X-FluxFiles-Role: viewer'])['code']);
});
$h->test('Public refresh can verify with public key only, records counters without changing response',function()use($h,$db,$s,&$jtis):void {
    $public=$h->api(['FLUXFILES_LICENSE_PRIVATE_KEY'=>'','FLUXFILES_LICENSE_K1_PUB'=>$h->public,'FLUXFILES_LICENSE_ROLE'=>'public']);$key=$s->findByJti($jtis['d'])['license_key'];
    $r=$h->request($public,'POST','/refresh',[],['Authorization: Bearer '.$key,'Content-Type: application/json']);Harness::eq(200,$r['code']);Harness::eq('current',$r['body']['status']);Harness::ok((int)$db->query("SELECT SUM(n) FROM activity_daily WHERE kind='refresh' AND result='current'")->fetchColumn()>0);
});
$h->test('New subscription/ops bookkeeping failures preserve webhook ACK and paid delivery, retain safe replay',function()use($h,$port,$db,$sub):void {
    $db->exec("CREATE TRIGGER sub_failure BEFORE INSERT ON subscriptions BEGIN SELECT RAISE(FAIL,'fixture failure'); END");
    try{$r=$sub('updated','sub-c','sub-recording-failure',['status'=>'active']);Harness::eq(200,$r['code']);Harness::eq(true,$r['body']['needs_review']);}finally{$db->exec('DROP TRIGGER sub_failure');}
    $row=$db->query("SELECT id,body FROM webhook_events WHERE webhook_id='sub-recording-failure'")->fetch();Harness::ok($row['body']!==null);Harness::eq(200,$h->call($port,'POST','/admin/events/'.$row['id'].'/replay')['code']);
    $db->exec("CREATE TRIGGER ops_failure BEFORE UPDATE ON ops_items BEGIN SELECT RAISE(FAIL,'fixture failure'); END");
    try{Harness::eq(200,$h->call($port,'POST','/issue',['email'=>'bookkeeping@example.test','plan'=>'pro','deliver'=>'manual'])['code']);}finally{$db->exec('DROP TRIGGER ops_failure');}
});
$h->finish();
