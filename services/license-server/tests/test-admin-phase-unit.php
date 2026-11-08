<?php

declare(strict_types=1);

require_once __DIR__.'/support/Harness.php';
require_once dirname(__DIR__).'/VersionRange.php';
require_once dirname(__DIR__).'/client/Ingest.php';
require_once dirname(__DIR__).'/ops/src/Inbox.php';
require_once dirname(__DIR__).'/collector/LogCollector.php';
require_once dirname(__DIR__).'/admin/src/Access.php';
require_once dirname(__DIR__).'/Csv.php';
require_once dirname(__DIR__).'/Plans.php';
use FluxFiles\LicenseServer\{VersionRange,Csv,LicenseStore};
use FluxFiles\LicenseServer\Client\Ingest;
use FluxFiles\LicenseServer\Ops\Inbox;
use FluxFiles\LicenseServer\Collector\LogCollector;
use FluxFiles\LicenseServer\Admin\Access;

$h=new Harness();
$reject=static function(callable $fn):void { try {$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('accepted invalid input'); };
$h->test('Version grammar AND, OR, boundaries and prereleases fail closed on malformed input',function():void {
    foreach (['>=1.0.0 <1.4.3','<1.2.1 || >=2.0.0 <2.0.2','=1.2.3','v1.2.3','>=1.2.3-rc.1 <1.2.3'] as $range) { Harness::ok(VersionRange::valid($range),$range); }
    Harness::ok(VersionRange::matches('1.4.2','>=1.0.0 <1.4.3'));Harness::ok(!VersionRange::matches('1.4.3','>=1.0.0 <1.4.3'));
    Harness::ok(VersionRange::matches('2.0.1','<1.2.1 || >=2.0.0 <2.0.2'));Harness::ok(!VersionRange::matches('2.0.2','<1.2.1 || >=2.0.0 <2.0.2'));
    Harness::ok(VersionRange::matches('1.2.3-rc.2','>=1.2.3-rc.1 <1.2.3'));
    foreach (['','*','^1.2.3','>= 1.2.3','<1.2.3 ||','1.2.3;php','1.2.3|2.0.0','>=999999.2.3'] as $range) { Harness::ok(!VersionRange::valid($range),$range);Harness::ok(!VersionRange::matches('1.2.3',$range)); }
});
$h->test('Counts ingestion enforces exact fields, finite nonnegative integers and calendar days',function() use($reject):void {
    $row=['module'=>'share','version'=>'1.2.3','n'=>2];Harness::eq([$row],Ingest::rows('downloads_daily',gmdate('Y-m-d'),[$row]));
    foreach ([$row+['ip'=>'192.0.2.1'],array_replace($row,['n'=>-1]),array_replace($row,['n'=>1.2]),array_replace($row,['version'=>'<script>'])] as $bad) { $reject(fn()=>Ingest::rows('downloads_daily',gmdate('Y-m-d'),[$bad])); }
    $reject(fn()=>Ingest::rows('downloads_daily','2025-02-30',[$row]));$reject(fn()=>Ingest::rows('downloads_daily','2999-01-01',[$row]));
    $reject(fn()=>Ingest::rows('public_counters',gmdate('Y-m-d'),[['source'=>'github','subject'=>'x/y','metric'=>'email','value'=>1]]));
});
$h->test('Inbox validates every section first, retries idempotently, quarantines malformed and refuses symlinks',function() use($h,$reject):void {
    $dir=$h->dir.'/inbox';mkdir($dir);$day=gmdate('Y-m-d');$file=$dir.'/'.$day.'.json';
    $data=['day'=>$day,'downloads'=>[['module'=>'share','version'=>'1.0.0','n'=>2]],'demo'=>['sandboxes_created'=>2,'sandbox_limit_hits'=>0,'page_limit_hits'=>0,'sandboxes_live'=>1,'uploads_bytes'=>10]];
    file_put_contents($file,json_encode($data));$calls=0;
    try { Inbox::consume($dir,function()use(&$calls):void {$calls++;throw new RuntimeException('offline');},fn()=>true); }catch(RuntimeException $e) {}
    Harness::eq(1,$calls);Harness::ok(is_file($file));
    $out=Inbox::consume($dir,function()use(&$calls):void {$calls++;},fn()=>true);Harness::eq(1,$out['ingested']);Harness::eq(3,$calls);Harness::ok(!file_exists($file));
    $data['demo']['raw_ip']='192.0.2.3';file_put_contents($file,json_encode($data));$calls=0;
    $out=Inbox::consume($dir,function()use(&$calls):void {$calls++;},fn()=>true);Harness::eq(1,$out['quarantined']);Harness::eq(0,$calls);Harness::ok(is_file($file.'.bad'));
    symlink($file.'.bad',$file);$reject(fn()=>Inbox::read($file));
});
$h->test('Root collector exports only counts, strips queries, filters dates/statuses and requires timestamps',function():void {
    $c=new LogCollector('2026-10-10',new DateTimeZone('Asia/Ho_Chi_Minh'));
    $lines=[['uri'=>'/dl/m/share-1.2.3.zip?token=secret&email=buyer@example.test','status'=>200,'time_iso8601'=>'2026-10-10T10:00:00+07:00'],['uri'=>'/dl/m/share-1.2.3.zip','status'=>206,'time_iso8601'=>'2026-10-10T11:00:00+07:00'],['uri'=>'/dl/m/share-1.2.3.zip','status'=>403,'time_iso8601'=>'2026-10-10T11:00:00+07:00'],['uri'=>'/dl/m/share-1.2.3.zip','status'=>200,'time_iso8601'=>'2026-10-11T11:00:00+07:00'],['uri'=>'/dl/m/share-1.2.3.zip','status'=>200]];
    $stream=fopen('php://temp','w+');foreach($lines as $line){fwrite($stream,json_encode($line)."\n");}rewind($stream);
    Harness::eq([['module'=>'share','version'=>'1.2.3','n'=>2]],$c->updates($stream));fclose($stream);
    $stream=fopen('php://temp','w+');fwrite($stream,"192.0.2.1 - - [10/Oct/2026:10:00:00 +0700] \"POST /sandbox/mint?private=yes HTTP/1.1\" 201 100\n192.0.2.1 - - [10/Oct/2026:10:01:00 +0700] \"GET / HTTP/1.1\" 429 10\n");rewind($stream);$r=$c->demo($stream,'/sandbox/mint');fclose($stream);Harness::eq(1,$r['sandboxes_created']);Harness::eq(1,$r['page_limit_hits']);
});
$h->test('CSV has BOM and formula guards without mangling ordinary text, refuses signed keys',function() use($reject):void {
    $csv=Csv::encode([['name','amount'],['=HYPERLINK("evil")','-3.25'],["\tcmd",'normal']]);Harness::ok(str_starts_with($csv,"\xEF\xBB\xBF"));Harness::ok(str_contains($csv,"'=HYPERLINK"));Harness::ok(str_contains($csv,"'-3.25"));
    $out=Csv::encode([[str_repeat('a',20).'.'.str_repeat('b',20).'.'.str_repeat('c',20)]],$redactions);Harness::eq(1,$redactions);Harness::ok(str_contains($out,'[redacted]'));
});
$h->test('Access verifies RSA signature and exact issuer/audience/time/kid; malformed JWKS fail closed',function():void {
    $rsa=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$detail=openssl_pkey_get_details($rsa);$b64=static fn(string $x):string=>rtrim(strtr(base64_encode($x),'+/','-_'),'=');
    $jwks=['keys'=>[['kid'=>'test','kty'=>'RSA','n'=>$b64($detail['rsa']['n']),'e'=>$b64($detail['rsa']['e'])]]];$now=time();$issuer='https://test-team.cloudflareaccess.com';
    $token=static function(array $claims,array $header=['alg'=>'RS256','kid'=>'test'])use($rsa,$b64):string{$data=$b64(json_encode($header)).'.'.$b64(json_encode($claims));openssl_sign($data,$sig,$rsa,OPENSSL_ALGO_SHA256);return $data.'.'.$b64($sig);};
    $claims=['iss'=>$issuer,'aud'=>['test-aud'],'iat'=>$now-10,'exp'=>$now+60];$valid=$token($claims);Harness::ok(Access::verify($valid,$issuer,'test-aud',$jwks,$now));
    foreach ([array_replace($claims,['aud'=>['other']]),array_replace($claims,['iss'=>'https://evil.cloudflareaccess.com']),array_replace($claims,['exp'=>$now]),array_replace($claims,['iat'=>$now+100]),array_replace($claims,['nbf'=>$now+100])] as $bad) {Harness::ok(!Access::verify($token($bad),$issuer,'test-aud',$jwks,$now));}
    Harness::ok(!Access::verify($token($claims,['alg'=>'HS256','kid'=>'test']),$issuer,'test-aud',$jwks));Harness::ok(!Access::verify($valid.'x',$issuer,'test-aud',$jwks));Harness::ok(!Access::verify($valid,$issuer,'test-aud',['keys'=>'bad']));
    $jwks['keys'][]=$jwks['keys'][0];Harness::ok(!Access::verify($valid,$issuer,'test-aud',$jwks));
});
$h->test('Additive v2 migration on a copied database preserves keys/history and repeated starts are idempotent',function() use($h):void {
    $old=new LicenseStore($h->env['FLUXFILES_LICENSE_DB']);$issuer=new \FluxFiles\LicenseServer\LicenseIssuer(new \FluxFiles\LicenseServer\LicenseSigner($h->secret),$old);$issued=$issuer->issue(['email'=>'migration@example.test','plan'=>'pro','gateway'=>'manual','order_id'=>'migration-order']);
    foreach(['subscriptions','ops_items','activity_daily','downloads_daily','public_counters','demo_daily','customer_notes','advisories','notices'] as $table){$old->pdo()->exec('DROP TABLE '.$table);}$old->pdo()->exec('PRAGMA user_version=2');$copy=$h->dir.'/migration-copy.sqlite';$old->pdo()->prepare('VACUUM INTO ?')->execute([$copy]);
    $s=new LicenseStore($copy);Harness::eq(4,(int)$s->pdo()->query('PRAGMA user_version')->fetchColumn());Harness::eq($issued['key'],$s->findByJti($issued['record']['jti'])['license_key']);Harness::eq(1,(int)$s->pdo()->query('SELECT COUNT(*) FROM license_history')->fetchColumn());
    $s=new LicenseStore($copy);Harness::eq(1,(int)$s->pdo()->query('SELECT COUNT(*) FROM licenses')->fetchColumn());Harness::eq(2,(int)$old->pdo()->query('PRAGMA user_version')->fetchColumn());
});
$h->finish();
