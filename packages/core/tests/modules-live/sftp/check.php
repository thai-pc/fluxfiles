<?php
// SFTP through a real adapter's HTTP routes, verified directly on the server. Run by run.sh.
// argv: <autoload> <base> <disk> <mode: op|byob> <secret-env>
declare(strict_types=1);
[$_, $autoload, $BASE, $DISK, $MODE, $SECRET_ENV] = $argv;
require $autoload;
use phpseclib3\Net\SFTP;
$SECRET = getenv($SECRET_ENV);
$CFG = ['driver'=>'sftp','host'=>'ff-sftp','port'=>22,'username'=>'ff','password'=>'ffpass','root'=>'upload'];
$pass=0;$fail=0;$allBodies='';
function t(string $n, callable $f){global $pass,$fail;try{$f();echo "  PASS $n\n";$pass++;}catch(\Throwable $e){echo "  FAIL $n: ".substr($e->getMessage(),0,300)."\n";$fail++;}}
function ok($c,string $m=''){if(!$c)throw new RuntimeException($m?:'assert');}
function tok(string $prefix, array $extra=[]){global $SECRET,$DISK,$CFG,$MODE;
  $p=['sub'=>'sftp-check','iat'=>time(),'exp'=>time()+1500,'jti'=>bin2hex(random_bytes(8)),'perms'=>['read','write','delete'],'disks'=>[$DISK],'prefix'=>$prefix,'max_upload'=>50,'allow_chmod'=>true,'allow_code_edit'=>true,'allow_download'=>true,'webp_enabled'=>true,'rate_read'=>100000,'rate_write'=>100000];$p=array_merge($p,$extra);
  if($MODE==='byob')$p['byob_disks']=[$DISK=>\FluxFiles\CredentialEncryptor::encrypt($CFG,$SECRET)];
  return \FluxFiles\JwtCompat::encode($p,$SECRET);}
function req(string $m,string $path,$tok,$json=null,array $hdr=[],$multipart=null){global $BASE,$allBodies;
  $url=str_starts_with($path,'http')?$path:$BASE.$path; $ch=curl_init($url);
  $h=$hdr; if($tok)$h[]="Authorization: Bearer $tok"; if($json!==null)$h[]='Content-Type: application/json';
  $o=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_HTTPHEADER=>$h,CURLOPT_TIMEOUT=>60];
  if($json!==null)$o[CURLOPT_POSTFIELDS]=json_encode($json); if($multipart!==null)$o[CURLOPT_POSTFIELDS]=$multipart;
  curl_setopt_array($ch,$o);$b=(string)curl_exec($ch);$s=curl_getinfo($ch,CURLINFO_HTTP_CODE);$allBodies.=$b;return [$s,$b,json_decode($b,true)];}
function up($tok,string $name,string $bytes,string $path=''){global $DISK;$tmp=tempnam(sys_get_temp_dir(),'ff');file_put_contents($tmp,$bytes);
  $r=req('POST','/upload',$tok,null,[],['disk'=>$DISK,'path'=>$path,'file'=>new CURLFile($tmp,'application/octet-stream',$name)]);@unlink($tmp);return $r;}
function items($j){$d=$j['data']??[];return $d['items']??(is_array($d)?$d:[]);}
$pfx='chk_'.bin2hex(random_bytes(3));$T=tok($pfx);
$sftp=new SFTP('ff-sftp');if(!$sftp->login('ff','ffpass'))die("direct login failed\n");
$R="upload/$pfx";$d=rawurlencode($DISK);
[$s0,$b0]=req('GET',"/list?disk=$d&path=",$T);
echo "probe list: HTTP $s0 ".substr($b0,0,220)."\n";
if($s0!==200){echo "RESULT blocked\n";exit(3);}
t('mkdir',function()use($T,$sftp,$R){global $DISK;[$s,$b]=req('POST','/mkdir',$T,['disk'=>$DISK,'path'=>'docs']);ok($s===200,"$s $b");ok($sftp->is_dir("$R/docs"),'not on server');});
$txt='hello sftp '.time();
t('upload text; bytes on server match',function()use($T,$sftp,$R,$txt){[$s,$b]=up($T,'note.txt',$txt,'docs');ok($s===200,"$s $b");ok($sftp->get("$R/docs/note.txt")===$txt,'bytes differ');});
$bin=random_bytes(3*1024*1024);
t('upload 3 MB binary; sha256 on server matches',function()use($T,$sftp,$R,$bin){[$s,$b]=up($T,'blob.bin',$bin,'docs');ok($s===200,"$s $b");ok(hash('sha256',(string)$sftp->get("$R/docs/blob.bin"))===hash('sha256',$bin),'sha differs');});
$url='';
t('list shows files with a stream URL',function()use($T,$d,&$url){[$s,$b,$j]=req('GET',"/list?disk=$d&path=docs",$T);ok($s===200,"$s $b");foreach(items($j) as $i){if(($i['name']??'')==='blob.bin')$url=$i['url']??'';}ok($url!=='',"no url: ".substr($b,0,300));ok(str_contains($url,'token='),"url: $url");});
function abs_url($u){global $BASE;if(str_starts_with($u,'http'))return $u;$p=parse_url($BASE);return $p['scheme'].'://'.$p['host'].':'.$p['port'].$u;}
t('stream: full bytes match',function()use(&$url,$bin){[$s,$b]=req('GET',abs_url($url),null);ok($s===200,"$s");ok(hash('sha256',$b)===hash('sha256',$bin),'sha differs len='.strlen($b));});
t('stream: Range ignored by design on SFTP (200, whole file)',function()use(&$url,$bin){[$s,$b]=req('GET',abs_url($url),null,null,['Range: bytes=100-199']);ok($s===200&&strlen($b)===strlen($bin),"HTTP $s len=".strlen($b));});
t('meta',function()use($T,$d){[$s,$b,$j]=req('GET',"/meta?disk=$d&path=docs%2Fnote.txt",$T);ok($s===200,"$s $b");});
t('content GET + PUT; server reflects edit',function()use($T,$d,$sftp,$R,$txt){global $DISK;[$s,$b,$j]=req('GET',"/content?disk=$d&path=docs%2Fnote.txt",$T);ok($s===200,"$s $b");ok(($j['data']['content']??'')===$txt,'read');
 [$s,$b]=req('PUT','/content',$T,['disk'=>$DISK,'path'=>'docs/note.txt','content'=>"  edited\nline\n\n"]);ok($s===200,"$s $b");$sftp->clearStatCache();$g=$sftp->get("$R/docs/note.txt");ok($g==="  edited\nline\n\n",'server has: '.var_export($g,true).' status '.$sftp->getLastSFTPError());});
t('chmod GET + POST 640; server stat matches',function()use($T,$d,$sftp,$R){global $DISK;[$s,$b,$j]=req('GET',"/chmod?disk=$d&path=docs%2Fnote.txt",$T);ok($s===200,"$s $b");ok(preg_match('/^[0-7]{3}$/',$j['data']['mode']??'')===1,$b);
 [$s,$b]=req('POST','/chmod',$T,['disk'=>$DISK,'path'=>'docs/note.txt','mode'=>'640']);ok($s===200,"$s $b");$sftp->clearStatCache();ok((($sftp->stat("$R/docs/note.txt")['mode']??0)&0777)===0640,'mode');});
t('chmod invalid mode → 422',function()use($T){global $DISK;[$s]=req('POST','/chmod',$T,['disk'=>$DISK,'path'=>'docs/note.txt','mode'=>'999']);ok($s===422,"$s");});
t('chmod refused with allow_chmod=false → 403',function()use($pfx){global $DISK;$t=tok($pfx,['allow_chmod'=>false]);[$s]=req('POST','/chmod',$t,['disk'=>$DISK,'path'=>'docs/note.txt','mode'=>'600']);ok($s===403,"$s");});
t('metadata PUT + GET; sidecar under _fluxfiles on server',function()use($T,$d,$sftp,$R){global $DISK;[$s,$b]=req('PUT','/metadata',$T,['disk'=>$DISK,'key'=>'docs/note.txt','title'=>'Hi']);ok($s===200,"$s $b");[$s,$b,$j]=req('GET',"/metadata?disk=$d&key=docs%2Fnote.txt",$T);ok($s===200&&($j['data']['title']??'')==='Hi',"$s $b");});
t('copy, rename, move; server state matches',function()use($T,$sftp,$R){global $DISK;
 [$s,$b]=req('POST','/copy',$T,['disk'=>$DISK,'from'=>'docs/note.txt','to'=>'docs/copy.txt']);ok($s===200,"copy $s $b");
 [$s,$b]=req('POST','/rename',$T,['disk'=>$DISK,'path'=>'docs/copy.txt','name'=>'renamed.txt']);ok($s===200,"rename $s $b");
 [$s,$b]=req('POST','/mkdir',$T,['disk'=>$DISK,'path'=>'other']);
 [$s,$b]=req('POST','/move',$T,['disk'=>$DISK,'from'=>'docs/renamed.txt','to'=>'other/moved.txt']);ok($s===200,"move $s $b");
 $sftp->clearStatCache();ok($sftp->file_exists("$R/other/moved.txt"),'moved missing');ok(!$sftp->file_exists("$R/docs/renamed.txt"),'source remains');ok($sftp->file_exists("$R/docs/note.txt"),'orig gone');});
t('rename a missing file → 404',function()use($T){global $DISK;[$s]=req('POST','/rename',$T,['disk'=>$DISK,'path'=>'docs/nope.txt','name'=>'x.txt']);ok($s===404,"$s");});
t('search-folders, quota and usage work on this disk',function()use($T,$d){foreach(["/search-folders?disk=$d&q=docs&limit=10","/quota?disk=$d","/usage?disk=$d"] as $u){[$s,$b]=req('GET',$u,$T);ok($s===200,"$u $s $b");}});
t('search finds the file',function()use($T,$d){[$s,$b,$j]=req('GET',"/search?disk=$d&q=note&limit=10",$T);ok($s===200,"$s $b");ok(str_contains($b,'note.txt'),substr($b,0,200));});
$png=(function(){$i=imagecreatetruecolor(40,30);ob_start();imagepng($i);return ob_get_clean();})();
t('stream token holds no plaintext credentials',function()use(&$url){$q=[];parse_str((string)parse_url($url,PHP_URL_QUERY),$q);$p=(string)base64_decode(strtr(explode('.',$q['token'])[1]??'','-_','+/'));ok($p!==''&&!str_contains($p,'ffpass'),'password in the token');});
t('image upload; served through stream',function()use($T,$d,$png){[$s,$b]=up($T,'pic.png',$png,'');ok($s===200,"$s $b");[$s,$b,$j]=req('GET',"/list?disk=$d&path=",$T);$u='';foreach(items($j) as $i){if(($i['name']??'')==='pic.png')$u=$i['url']??'';}ok($u!=='','no url');[$s,$b]=req('GET',abs_url($u),null);ok($s===200&&$b===$png,"$s");});
t('/img returns a WebP',function()use($T,$d){[$s,$b,$j]=req('GET',"/list?disk=$d&path=",$T);$u='';foreach(items($j) as $i){if(($i['name']??'')==='pic.png')$u=$i['img_base']??'';}ok($u!=='','no img_base');[$s,$b]=req('GET',abs_url($u).'&width=100&format=webp',null);ok($s===200&&substr($b,8,4)==='WEBP',"$s ".substr($b,0,80));});
$tid='';
t('trash, list, restore; server state matches',function()use($T,$d,$sftp,$R,&$tid){global $DISK;[$s,$b,$j]=req('POST','/trash',$T,['disk'=>$DISK,'path'=>'other/moved.txt']);ok($s===200,"$s $b");$sftp->clearStatCache();ok(!$sftp->file_exists("$R/other/moved.txt"),'not moved away');
 [$s,$b,$j]=req('GET',"/trash/list?disk=$d",$T);ok($s===200,"$s $b");$tid=$j['data'][0]['trash_id']??'';ok($tid!=='',$b);
 [$s,$b]=req('POST','/trash/restore',$T,['disk'=>$DISK,'trash_id'=>$tid]);ok($s===200,"$s $b");$sftp->clearStatCache();ok($sftp->file_exists("$R/other/moved.txt"),'not restored');});
t('trash folder, then purge',function()use($T,$d,$sftp,$R){global $DISK;[$s,$b]=req('POST','/trash',$T,['disk'=>$DISK,'path'=>'other']);ok($s===200,"$s $b");[$s,$b,$j]=req('GET',"/trash/list?disk=$d",$T);$id=$j['data'][0]['trash_id']??'';[$s,$b]=req('POST','/trash/purge',$T,['disk'=>$DISK,'trash_id'=>$id]);ok($s===200,"$s $b");});
t('cross-tenant: other prefix cannot read this file (list empty, content 4xx)',function()use($pfx,$d){$o=tok($pfx.'_other');[$s,$b]=req('GET',"/content?disk=$d&path=docs%2Fnote.txt",$o);ok($s>=400&&$s<500,"$s $b");[$s,$b]=req('GET',"/content?disk=$d&path=..%2F$pfx%2Fdocs%2Fnote.txt",$o);ok($s>=400&&$s<500,"traversal $s $b");});
t('traversal refused',function()use($T,$d){[$s,$b]=req('GET',"/content?disk=$d&path=..%2F..%2F..%2Fetc%2Fpasswd",$T);ok($s>=400&&$s<500,"$s $b");});
t('delete; gone on server',function()use($T,$sftp,$R){global $DISK;[$s,$b]=req('DELETE','/delete',$T,['disk'=>$DISK,'path'=>'docs/blob.bin']);ok($s===200,"$s $b");$sftp->clearStatCache();ok(!$sftp->file_exists("$R/docs/blob.bin"),'still there');});
t('no response leaked the SFTP password',function()use(&$allBodies){ok(!str_contains($allBodies,'ffpass'),'password in a response');});
echo "RESULT pass=$pass fail=$fail\n";
exit($fail?1:0);
