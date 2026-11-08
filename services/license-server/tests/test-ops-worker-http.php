<?php

declare(strict_types=1);

require_once __DIR__.'/support/Harness.php';
use FluxFiles\LicenseServer\LicenseStore;

$h=new Harness();$api=$h->api();$h->call($api,'GET','/admin/meta');$store=new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);$db=$h->db();
$token='123456:fake_bot_token_for_tests';file_put_contents($h->dir.'/telegram.token',$token);
$fake=$h->dir.'/telegram.php';
file_put_contents($fake, <<<'FAKE'
<?php
$dir=__DIR__;$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$body=json_decode(file_get_contents('php://input'),true);
file_put_contents($dir.'/calls.jsonl',json_encode(['method'=>basename($path),'body'=>$body])."\n",FILE_APPEND);
header('Content-Type: application/json');
if (basename($path)==='sendMessage' && is_file($dir.'/429')) { http_response_code(429);echo json_encode(['ok'=>false,'description'=>$_SERVER['REQUEST_URI'],'parameters'=>['retry_after'=>17]]);exit; }
$result=match(basename($path)) { 'getMe'=>['username'=>'fluxfiles_ops_bot'],'getUpdates'=>json_decode((string)@file_get_contents($dir.'/updates.json'),true)?:[],default=>['message_id'=>1] };
echo json_encode(['ok'=>true,'result'=>$result]);
FAKE);
$telegram=$h->start($fake,['PATH'=>(string)getenv('PATH')]);
$env=['PATH'=>(string)getenv('PATH'),'FLUXFILES_ADMIN_API'=>'http://127.0.0.1:'.$api,'FLUXFILES_LICENSE_OPS_TOKEN'=>'ops-secret','FLUXFILES_TELEGRAM_BOT_TOKEN_FILE'=>$h->dir.'/telegram.token','FLUXFILES_TELEGRAM_CHAT_ID'=>'42','FLUXFILES_TELEGRAM_API_BASE'=>'http://127.0.0.1:'.$telegram,'FLUXFILES_OPS_STATE_DIR'=>$h->dir.'/ops'];
$run=function(array $extra=[]) use($h,$env):array {
    $p=proc_open([PHP_BINARY,dirname(__DIR__).'/ops/ops-worker.php','run'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$extra+$env);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);foreach($pipes as $fp) { fclose($fp); }return [proc_close($p),$out];
};
$calls=function() use($h):array { return array_map(static fn(string $l):array=>json_decode($l,true),file($h->dir.'/calls.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]); };
$clear=function() use($h,$db):void { $db->exec('DELETE FROM ops_outbox');file_put_contents($h->dir.'/calls.jsonl',''); };
$h->test('Telegram plain text, tags, thread ID; commands off never getUpdates; worker lacks DB and key env',function() use($h,$store,$run,$calls,$token):void {
    $store->enqueueAlert('test','info','safe','Safe alert · a***@example.test');$r=$run(['FLUXFILES_TELEGRAM_THREAD_ID'=>'7']);Harness::eq(0,$r[0]);Harness::ok(!str_contains($r[1],$token));$c=$calls();Harness::eq(1,count($c));Harness::eq('sendMessage',$c[0]['method']);Harness::ok(!isset($c[0]['body']['parse_mode']));Harness::eq(7,$c[0]['body']['message_thread_id']);Harness::ok(str_starts_with($c[0]['body']['text'],'[FluxFiles]'));
});
$h->test('Defence in depth refuses raw key and full email even if persisted directly',function() use($h,$clear,$db,$run,$calls):void {
    $clear();$key=str_repeat('a',20).'.'.str_repeat('b',20).'.'.str_repeat('c',20);
    foreach ([$key,'full@example.test'] as $i=>$text) { $db->prepare("INSERT INTO ops_outbox(created_at,channel,kind,severity,dedup_key,text,next_at) VALUES(?,'telegram','test','warn',?,?,1)")->execute([time(),'unsafe-'.$i,$text]); }
    Harness::eq(0,$run()[0]);Harness::eq([],$calls());Harness::eq(2,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE last_error='masking' AND dropped_at IS NOT NULL")->fetchColumn());
});
$h->test('Flood collapses into bounded messages and a digest; hourly budget persists',function() use($h,$clear,$store,$db,$run,$calls):void {
    $clear();for($i=0;$i<50;$i++) { $store->enqueueAlert('test','warn','flood-'.$i,'Alert '.$i); }
    Harness::eq(0,$run()[0]);$c=$calls();Harness::eq(20,count($c));Harness::ok(str_contains($c[19]['body']['text'],'digest'));Harness::eq(31,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE last_error='budget_digest'")->fetchColumn());
    $state=json_decode(file_get_contents($h->dir.'/ops/state.json'),true);$state['sends']=array_fill(0,60,time());file_put_contents($h->dir.'/ops/state.json',json_encode($state));
    $clear();$store->enqueueAlert('test','warn','hour-limit','Wait for next hour');Harness::eq(0,$run()[0]);Harness::eq([],$calls());Harness::eq(1,(int)$db->query('SELECT COUNT(*) FROM ops_outbox WHERE sent_at IS NULL AND dropped_at IS NULL')->fetchColumn());
    $state['sends']=[];file_put_contents($h->dir.'/ops/state.json',json_encode($state));
});
$h->test('429 Retry-After persists and provider token/URL errors never enter logs',function() use($h,$clear,$store,$db,$run,$calls,$token):void {
    $clear();file_put_contents($h->dir.'/429','1');$store->enqueueAlert('test','warn','retry','Retry alert');$r=$run();unlink($h->dir.'/429');Harness::eq(0,$r[0]);Harness::ok(!str_contains($r[1],$token));$row=$db->query('SELECT * FROM ops_outbox')->fetch();Harness::ok((int)$row['next_at']>=time()+15);Harness::eq('telegram_http_429',$row['last_error']);Harness::ok(!str_contains($row['last_error'],$token));
});
$h->test('Optional read-only commands authorize chat, group author and topic; cursor persists',function() use($h,$clear,$run,$calls):void {
    $clear();file_put_contents($h->dir.'/updates.json',json_encode([
        ['update_id'=>1,'message'=>['chat'=>['id'=>666,'type'=>'private'],'text'=>'/stats']],
        ['update_id'=>2,'message'=>['chat'=>['id'=>42,'type'=>'private'],'text'=>'/stats']],
        ['update_id'=>3,'message'=>['chat'=>['id'=>42,'type'=>'private'],'text'=>'/revoke']],
        ['update_id'=>4,'message'=>['chat'=>['id'=>42,'type'=>'supergroup'],'from'=>['id'=>9],'message_thread_id'=>7,'text'=>'/today@fluxfiles_ops_bot']],
        ['update_id'=>5,'message'=>['chat'=>['id'=>42,'type'=>'supergroup'],'from'=>['id'=>10],'message_thread_id'=>7,'text'=>'/today@fluxfiles_ops_bot']],
    ]));
    Harness::eq(0,$run(['FLUXFILES_TELEGRAM_COMMANDS'=>'1'])[0]);$sent=array_values(array_filter($calls(),static fn(array $c):bool=>$c['method']==='sendMessage'));Harness::eq(1,count($sent));
    $state=json_decode(file_get_contents($h->dir.'/ops/state.json'),true);Harness::eq(5,$state['offset']);
    $clear();file_put_contents($h->dir.'/updates.json',json_encode([['update_id'=>6,'message'=>['chat'=>['id'=>42,'type'=>'supergroup'],'from'=>['id'=>9],'message_thread_id'=>7,'text'=>'/today@fluxfiles_ops_bot']]]));
    Harness::eq(0,$run(['FLUXFILES_TELEGRAM_COMMANDS'=>'1','FLUXFILES_TELEGRAM_ADMIN_USER_ID'=>'9','FLUXFILES_TELEGRAM_THREAD_ID'=>'7'])[0]);Harness::eq(1,count(array_filter($calls(),static fn(array $c):bool=>$c['method']==='sendMessage')));
    $poll=array_values(array_filter($calls(),static fn(array $c):bool=>$c['method']==='getUpdates'))[0];Harness::eq(6,$poll['body']['offset']);
});
$h->test('Health alerts after two failures then recovers, stale backup alert, heartbeat and retention',function() use($h,$clear,$run,$db):void {
    $clear();$health=$h->dir.'/health.php';file_put_contents($health,'<?php http_response_code(is_file(__DIR__."/down")?503:200);echo "ok";');$p=$h->start($health,['PATH'=>(string)getenv('PATH')]);file_put_contents($h->dir.'/down','1');file_put_contents($h->dir.'/backup.json',json_encode(['ok'=>true,'at'=>time()-27*3600]));
    $config=['FLUXFILES_TELEGRAM_CHAT_ID'=>'','FLUXFILES_OPS_HEALTH_TARGETS'=>json_encode([['name'=>'fake','url'=>'http://127.0.0.1:'.$p,'expect_status'=>200]])];
    $reset=function() use($h):void { $state=json_decode(file_get_contents($h->dir.'/ops/state.json'),true);$state['health_at']=0;file_put_contents($h->dir.'/ops/state.json',json_encode($state)); };
    $reset();Harness::eq(0,$run($config)[0]);Harness::eq(0,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE kind='health.down'")->fetchColumn());
    $reset();Harness::eq(0,$run($config)[0]);Harness::eq(1,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE kind='health.down'")->fetchColumn());
    unlink($h->dir.'/down');$reset();Harness::eq(0,$run($config)[0]);Harness::eq(1,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE kind='health.up'")->fetchColumn());Harness::eq(1,(int)$db->query("SELECT COUNT(*) FROM ops_outbox WHERE kind='backup.stale'")->fetchColumn());
    Harness::ok($db->query("SELECT value FROM ops_state WHERE key='heartbeat'")->fetchColumn()!==false);
});
$h->test('TLS health probe verifies a trusted self-signed loopback certificate and reports expiry',function() use($h):void {
    $config=$h->dir.'/openssl.cnf';file_put_contents($config,"[req]\ndistinguished_name=dn\nx509_extensions=v3\n[dn]\nCN=localhost\n[v3]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>$config]);
    $csr=openssl_csr_new(['commonName'=>'localhost'],$key,['config'=>$config,'digest_alg'=>'sha256']);
    $certificate=openssl_csr_sign($csr,null,$key,12,['config'=>$config,'x509_extensions'=>'v3','digest_alg'=>'sha256']);Harness::ok($certificate!==false);
    openssl_x509_export($certificate,$pem);openssl_pkey_export($key,$private,null,['config'=>$config]);file_put_contents($h->dir.'/cert.pem',$pem);file_put_contents($h->dir.'/server.pem',$pem.$private);
    $server=$h->dir.'/tls.php';file_put_contents($server, <<<'TLS'
<?php
$context=stream_context_create(['ssl'=>['local_cert'=>__DIR__.'/server.pem','verify_peer'=>false]]);
$server=stream_socket_server('tls://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
file_put_contents(__DIR__.'/tls.port',substr(strrchr(stream_socket_get_name($server,false),':'),1));
while($client=@stream_socket_accept($server,20)) { stream_set_timeout($client,2);@fread($client,4096);@fwrite($client,"HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");fclose($client); }
TLS
    );
    $h->processes[]=proc_open([PHP_BINARY,$server],[1=>['file',$h->dir.'/tls.log','a'],2=>['file',$h->dir.'/tls.log','a']],$pipes,null,['PATH'=>(string)getenv('PATH')]);
    for($i=0;$i<50 && !is_file($h->dir.'/tls.port');$i++) { usleep(50000); }$port=(int)file_get_contents($h->dir.'/tls.port');Harness::ok($port>0);
    $code='require '.var_export(dirname(__DIR__).'/ops/src/Health.php',true).';echo json_encode((new \FluxFiles\LicenseServer\Ops\Health())->probe(["name"=>"tls","url"=>"https://127.0.0.1:'.$port.'","expect_status"=>200]));';
    $process=proc_open([PHP_BINARY,'-d','openssl.cafile='.$h->dir.'/cert.pem','-d','curl.cainfo='.$h->dir.'/cert.pem','-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>(string)getenv('PATH')]);
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);foreach($pipes as $fp) { fclose($fp); }Harness::eq(0,proc_close($process),$error);$result=json_decode($out,true);Harness::eq(true,$result['ok']);Harness::ok($result['tls_days']>=10 && $result['tls_days']<=12);
});
$h->finish();
