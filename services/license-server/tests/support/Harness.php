<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/LicenseStore.php';
require_once dirname(__DIR__,2).'/LicenseSigner.php';
require_once dirname(__DIR__,2).'/LicenseIssuer.php';
require_once dirname(__DIR__,2).'/PolarWebhook.php';

final class Harness
{
    public string $dir;
    public string $secret;
    public string $public;
    public array $processes=[];
    public int $passed=0;
    public int $failed=0;
    public array $env;
    public function __construct()
    {
        $this->dir=sys_get_temp_dir().'/ff-admin-'.bin2hex(random_bytes(6));mkdir($this->dir,0700);
        $kp=sodium_crypto_sign_keypair();$this->secret=base64_encode(sodium_crypto_sign_secretkey($kp));$this->public=base64_encode(sodium_crypto_sign_publickey($kp));
        $this->env=['PATH'=>(string)getenv('PATH'),'FLUXFILES_LICENSE_DB'=>$this->dir.'/l.sqlite','FLUXFILES_LICENSE_PRIVATE_KEY'=>$this->secret,
            'FLUXFILES_LICENSE_ADMIN_TOKEN'=>'admin-secret','FLUXFILES_LICENSE_UI_TOKEN'=>'ui-secret','FLUXFILES_LICENSE_OPS_TOKEN'=>'ops-secret','FLUXFILES_UPDATE_STATUS_TOKEN'=>'status-secret',
            'FLUXFILES_POLAR_WEBHOOK_SECRET'=>'hook-secret','FLUXFILES_RESEND_WEBHOOK_SECRET'=>'resend-secret','FLUXFILES_MAIL_TRANSPORT'=>'log',
            'FLUXFILES_POLAR_PLAN_MAP'=>'{"pm":"pro-monthly","pro":"pro","studio":"studio","support":"support-monthly"}',
            'FLUXFILES_ADMIN_TRACKED_DISCOUNTS'=>'FOUNDING40:5','FLUXFILES_LICENSE_BACKUP_STATUS'=>$this->dir.'/backup.json'];
        putenv('RANDFILE='.$this->dir.'/openssl-rand');
        $this->env['RANDFILE']=$this->dir.'/openssl-rand';
        file_put_contents($this->dir.'/backup.json',json_encode(['ok'=>true,'at'=>time()]));
        register_shutdown_function(function():void { foreach (array_reverse($this->processes) as $p) { proc_terminate($p);proc_close($p); }if (getenv('FF_KEEP_ADMIN_TESTS')!=='1') { $this->remove($this->dir); }else { echo $this->dir."\n"; } });
    }
    private function remove(string $path): void
    {
        if (is_dir($path)) { foreach (scandir($path) as $f) { if ($f!=='.' && $f!=='..') { $this->remove($path.'/'.$f); } }@rmdir($path); }else { @unlink($path); }
    }
    public function test(string $name,callable $fn): void
    {
        try { $fn();$this->passed++;echo "  PASS $name\n"; }catch(Throwable $e) { $this->failed++;echo "  FAIL $name: ".$e->getMessage()."\n"; }
    }
    public static function eq($expected,$actual,string $why=''): void
    {
        if ($expected!==$actual) { throw new RuntimeException($why.' expected '.json_encode($expected).' got '.json_encode($actual)); }
    }
    public static function ok($condition,string $why='assertion failed'): void { if (!$condition) { throw new RuntimeException($why); } }
    public function finish(): never { echo "$this->passed passed, $this->failed failed\n";exit($this->failed?1:0); }
    public function start(string $script,array $env=[]): int
    {
        $sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if (!$sock) { throw new RuntimeException('Cannot bind loopback'); }
        $port=(int)substr(strrchr(stream_socket_get_name($sock,false),':'),1);fclose($sock);
        $this->processes[]=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$script],[0=>['pipe','r'],1=>['file',$this->dir.'/server-'.$port.'.log','a'],2=>['file',$this->dir.'/server-'.$port.'.log','a']],$pipes,dirname($script),$env?:$this->env);
        for($i=0;$i<80;$i++) { $fp=@fsockopen('127.0.0.1',$port);if($fp) { fclose($fp);return $port; }usleep(50000); }
        throw new RuntimeException('Test server did not start');
    }
    public function api(array $env=[]): int { return $this->start(dirname(__DIR__,2).'/server.php',$env+$this->env); }
    public function request(int $port,string $method,string $path,?array $body=null,array $headers=[],bool $form=false): array
    {
        $ch=curl_init('http://127.0.0.1:'.$port.$path);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>35,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers+[]]);
        if($body!==null) { curl_setopt($ch,CURLOPT_POSTFIELDS,$form?http_build_query($body):json_encode($body)); }
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$n=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);unset($ch);
        $text=substr((string)$raw,$n);return ['code'=>$status,'headers'=>substr((string)$raw,0,$n),'raw'=>$text,'body'=>json_decode($text,true)];
    }
    public function call(int $port,string $method,string $path,array $b=[],string $role='ui'): array
    {
        return $this->request($port,$method,$path,$method==='GET'?null:$b,['Authorization: Bearer '.$role.'-secret','Content-Type: application/json','X-FluxFiles-Actor: ui:owner']);
    }
    public function db(): PDO { return new PDO('sqlite:'.$this->env['FLUXFILES_LICENSE_DB'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
    public function hook(int $port,array $body,string $id='msg-1',string $source='polar'): array
    {
        $raw=json_encode($body);$ts=(string)time();$secret=$source==='polar'?'hook-secret':'resend-secret';$sig=base64_encode(hash_hmac('sha256',"$id.$ts.$raw",$secret,true));$prefix=$source==='polar'?'webhook':'svix';
        return $this->request($port,'POST','/webhook/'.$source,$body,["$prefix-id: $id","$prefix-timestamp: $ts","$prefix-signature: v1,$sig"]);
    }
}
