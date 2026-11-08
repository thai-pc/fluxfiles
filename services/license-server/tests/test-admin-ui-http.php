<?php

declare(strict_types=1);

require_once __DIR__.'/support/Harness.php';
require_once dirname(__DIR__).'/admin/src/Auth.php';
use FluxFiles\LicenseServer\Admin\{Auth,Totp};
use FluxFiles\LicenseServer\Client\Mask;

$h=new Harness();$api=$h->api();$path=$h->dir.'/admin.sqlite';$auth=new Auth($path);$secret=Totp::secret();$password='a-strong-test-password';
$auth->db->prepare('INSERT INTO users(name,pass_hash,totp_secret,created_at) VALUES(?,?,?,?)')->execute(['owner',password_hash($password,PASSWORD_BCRYPT),$secret,time()]);
$env=['PATH'=>(string)getenv('PATH'),'FLUXFILES_ADMIN_API'=>'http://127.0.0.1:'.$api,'FLUXFILES_ADMIN_UI_TOKEN'=>'ui-secret','FLUXFILES_ADMIN_DB'=>$path,'FLUXFILES_ADMIN_TEST_CLOCK'=>'1'];
$ui=$h->start(dirname(__DIR__).'/admin/public/index.php',$env);$cookie='';$csrf='';$now=time();
$extract=static function(array $r) use(&$cookie,&$csrf):void {
    if(preg_match('/Set-Cookie: (fxadm(?:_sbx)?=[^;]+)/i',$r['headers'],$m)) { $cookie=$m[1]; }
    if(preg_match('/name="_csrf" value="([a-f0-9]+)"/',$r['raw'],$m)) { $csrf=$m[1]; }
};
$get=function(string $route,array $headers=[]) use($h,$ui,&$cookie,&$extract):array { $r=$h->request($ui,'GET',$route,null,array_merge(['Cookie: '.$cookie],$headers));$extract($r);return $r; };
$post=function(string $route,array $body=[],array $headers=[]) use($h,$ui,&$cookie,&$csrf,&$extract):array {
    $r=$h->request($ui,'POST',$route,$body+['_csrf'=>$csrf],array_merge(['Cookie: '.$cookie,'Origin: http://127.0.0.1:'.$ui,'Content-Type: application/x-www-form-urlencoded'],$headers),true);$extract($r);return $r;
};
$h->test('Login, errors, assets and redirects have security headers; hostile Host refused',function() use($h,$ui,$get):void {
    Harness::eq(303,$get('/')['code']);
    foreach (['/login','/assets/admin.js','/assets/admin.css'] as $url) {
        $r=$get($url);Harness::eq(200,$r['code']);
        foreach (['Content-Security-Policy:','Cache-Control: no-store','Referrer-Policy: no-referrer','X-Content-Type-Options: nosniff','X-Frame-Options: DENY','Cross-Origin-Opener-Policy: same-origin','Cross-Origin-Resource-Policy: same-origin','Permissions-Policy:'] as $header) { Harness::ok(stripos($r['headers'],$header)!==false,$header); }
        Harness::ok(str_contains($r['headers'],"require-trusted-types-for 'script'"));
    }
    Harness::eq(400,$h->request($ui,'GET','/login',null,['Host: evil.example:8471'])['code']);
});
$h->test('CSRF, exact Origin and Fetch-Site required even on login; generic wrong credentials',function() use($h,$ui,$get,$post,&$cookie):void {
    $get('/login');Harness::eq(403,$post('/login',['_csrf'=>'wrong'])['code']);
    Harness::eq(403,$h->request($ui,'POST','/login',[],['Cookie: '.$cookie,'Content-Type: application/x-www-form-urlencoded'],true)['code']);
    Harness::eq(403,$post('/login',[],['Origin: http://evil.example'])['code']);
    Harness::eq(403,$post('/login',[],['Sec-Fetch-Site: cross-site'])['code']);
    $bad=$post('/login',['username'=>'owner','password'=>'wrong','code'=>'000000']);$unknown=$post('/login',['username'=>'nobody','password'=>'wrong','code'=>'000000']);Harness::eq(401,$bad['code']);Harness::eq(401,$unknown['code']);
    Harness::ok(str_contains($bad['raw'],'Thông tin đăng nhập không đúng'));Harness::ok(str_contains($unknown['raw'],'Thông tin đăng nhập không đúng'));
});
$h->test('Password plus TOTP rotates session, stores hash only, rejects replay',function() use($get,$post,$password,$secret,&$cookie,&$csrf,$auth,$now):void {
    $get('/login');$old=$cookie;Harness::eq(303,$post('/login',['username'=>'owner','password'=>$password,'code'=>Totp::code($secret,intdiv($now,30))])['code']);Harness::ok($old!==$cookie,'session rotated');
    Harness::ok(str_contains($get('/')['raw'],'Bảng điều khiển'));
    $session=substr($cookie,strpos($cookie,'=')+1);$s=$auth->db->prepare('SELECT COUNT(*) FROM sessions WHERE id_hash=?');$s->execute([$session]);Harness::eq(0,(int)$s->fetchColumn());
    Harness::eq(401,$post('/login',['username'=>'owner','password'=>$password,'code'=>Totp::code($secret,intdiv($now,30))])['code']);
});
$jti='';$key='';
$h->test('All MVP pages render without key or inline code, hostile strings escaped throughout',function() use($h,$api,$get,&$jti,&$key):void {
    $r=$h->call($api,'POST','/issue',['email'=>'victim@example.test','customer'=>'"><img src=x onerror=alert(1)>','plan'=>'pro','deliver'=>'manual','note'=>'</script><script>alert(1)</script>']);$jti=$r['body']['record']['jti'];
    $db=$h->db();$key=$db->query('SELECT license_key FROM licenses')->fetchColumn();
    $db->prepare('INSERT INTO install_pings VALUES(?,?,?,?,?,?,0,1,1,?,?)')->execute([gmdate('Y-m-d'),$jti,'"><img src=x onerror=alert(1)>','','javascript:</script><script>','<img src=x onerror=1>',time(),time()]);
    $db->prepare("INSERT INTO webhook_events(source,webhook_id,received_at,last_at,type,verify,outcome,http_status,error,product_id) VALUES('polar','hostile',?,?,?,'ok','error',500,?,?)")->execute([time(),time(),'</script><script>','"><img src=x onerror=alert(1)>','javascript:<img>']);
    foreach (['/','/licences','/licences/'.$jti,'/events','/events/1','/audit','/system','/stats/revenue','/stats/installs','/stats/mail','/issue','/missing'] as $url) {
        $out=$get($url);Harness::eq($url==='/missing'?404:200,$out['code'],$url);
        Harness::ok(!str_contains($out['raw'],$key),$url);Harness::ok(!preg_match(Mask::KEY,$out['raw']),$url);
        Harness::ok(!str_contains($out['raw'],'<img src=x'),$url);Harness::ok(!str_contains($out['raw'],'</script><script>'),$url);
        Harness::ok(!preg_match('/<script\b(?![^>]*\bsrc=)|<style\b|\bstyle\s*=|<[^>]+\son[a-z]+\s*=/i',$out['raw']),$url);
    }
    $detail=$get('/licences/'.$jti)['raw'];Harness::ok(str_contains($detail,'&lt;img'));Harness::ok(str_contains($detail,'&lt;script&gt;'));
});
$h->test('Issue needs a delivery choice; action GET is 405; fresh step-up required after ten minutes',function() use($h,$ui,$get,$post,$secret,$now,&$cookie,&$csrf,&$jti):void {
    Harness::eq(400,$post('/issue',['email'=>'x@example.test','plan'=>'pro'])['code']);
    Harness::eq(405,$get('/licences/'.$jti.'/reveal')['code']);Harness::eq(405,$get('/logout')['code']);
    $future=$now+650;$headers=['X-FluxFiles-Test-Time: '.$future,'Accept: application/json'];
    Harness::eq(403,$post('/licences/'.$jti.'/reveal',[],$headers)['code']);
    $r=$post('/licences/'.$jti.'/reveal',['code'=>Totp::code($secret,intdiv($future,30))],$headers);Harness::eq(200,$r['code']);Harness::ok(isset($r['body']['license_key']));
    Harness::ok(str_contains($r['headers'],'no-store'));
});
$h->test('Typed confirmation prevents revoke; flags and undo, language and theme persist',function() use($get,$post,&$jti):void {
    Harness::eq(400,$post('/licences/'.$jti.'/revoke',['confirmation'=>'bad'])['code']);
    Harness::eq(303,$post('/licences/'.$jti.'/mark-test')['code']);Harness::ok(str_contains($get('/licences/'.$jti.'?notice=success&undo=mark-test')['raw'],'Hoàn tác'));
    Harness::eq(303,$post('/pref/lang',['value'=>'en'])['code']);Harness::ok(str_contains($get('/licences')['raw'],'Licence administration'));
    $post('/pref/theme',['value'=>'dark']);Harness::ok(str_contains($get('/')['raw'],'data-theme="dark"'));
});
$h->test('Idle expiry, absolute expiry, logout and CLI logout-all invalidate sessions',function() use($h,$ui,$get,$post,$auth,$secret,$password,$now,&$cookie):void {
    $row=$auth->db->query('SELECT id_hash FROM sessions WHERE user_id=1 LIMIT 1')->fetchColumn();$auth->db->prepare('UPDATE sessions SET last_seen=? WHERE id_hash=?')->execute([$now-1900,$row]);Harness::eq(303,$get('/')['code']);
    $auth->db->exec('UPDATE users SET totp_last_step=-1');$get('/login');Harness::eq(303,$post('/login',['username'=>'owner','password'=>$password,'code'=>Totp::code($secret,intdiv($now,30))])['code']);$get('/');
    $auth->db->exec('UPDATE sessions SET created_at=1 WHERE user_id=1');Harness::eq(303,$get('/')['code']);
    $auth->db->exec('UPDATE users SET totp_last_step=-1');$get('/login');$post('/login',['username'=>'owner','password'=>$password,'code'=>Totp::code($secret,intdiv($now,30))]);$get('/');Harness::eq(303,$post('/logout')['code']);Harness::eq(303,$get('/')['code']);
});
$h->test('Five failures lock username even with correct password and valid TOTP',function() use($get,$post,$auth,$secret,$password,$now):void {
    $auth->db->exec('DELETE FROM login_hits;UPDATE users SET totp_last_step=-1');$get('/login');
    for($i=0;$i<5;$i++) { Harness::eq(401,$post('/login',['username'=>'owner','password'=>'wrong','code'=>'000000'])['code']); }
    Harness::eq(401,$post('/login',['username'=>'owner','password'=>$password,'code'=>Totp::code($secret,intdiv($now,30))])['code']);
});
$h->test('CLI logout-all invalidates browser sessions and global throttle covers many usernames',function() use($h,$get,$post,$auth,$secret,$password,$now):void {
    $auth->db->exec('DELETE FROM login_hits;UPDATE users SET totp_last_step=-1');$get('/login');
    Harness::eq(303,$post('/login',['username'=>'owner','password'=>$password,'code'=>Totp::code($secret,intdiv($now,30))])['code']);$get('/');
    $p=proc_open([PHP_BINARY,dirname(__DIR__).'/admin/cli/admin-user.php','logout-all','owner'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>(string)getenv('PATH'),'FLUXFILES_ADMIN_DB'=>$h->dir.'/admin.sqlite']);
    foreach($pipes as $fp) { stream_get_contents($fp);fclose($fp); }Harness::eq(0,proc_close($p));Harness::eq(303,$get('/')['code']);
    $get('/login');$auth->db->exec('DELETE FROM login_hits;UPDATE users SET totp_last_step=-1');
    for($i=0;$i<20;$i++) { Harness::eq(401,$post('/login',['username'=>'unknown-'.$i,'password'=>'wrong','code'=>'000000'])['code']); }
    Harness::eq(401,$post('/login',['username'=>'owner','password'=>$password,'code'=>Totp::code($secret,intdiv($now,30))])['code']);
});
$h->test('Sandbox banner and title on login and errors, own cookie namespace, production has no banner',function() use($h,$env,$get):void {
    $sbx=$h->start(dirname(__DIR__).'/admin/public/index.php',['FLUXFILES_LICENSE_INSTANCE'=>'sandbox','FLUXFILES_ADMIN_DB'=>$h->dir.'/sbx-admin.sqlite']+$env);
    foreach (['/login','/assets/admin.css'] as $route) { $r=$h->request($sbx,'GET',$route);Harness::eq(200,$r['code']);if($route==='/login') { Harness::ok(str_contains($r['raw'],'[SANDBOX]'));Harness::ok(str_contains($r['raw'],'class="sandbox-banner"'));Harness::ok(str_contains($r['headers'],'fxadm_sbx=')); } }
    Harness::ok(!str_contains($get('/login')['raw'],'[SANDBOX]'));
});
$h->finish();
