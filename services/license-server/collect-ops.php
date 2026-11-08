<?php

declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__ . '/collector/LogCollector.php';
require_once __DIR__ . '/client/Config.php';
require_once __DIR__ . '/client/Ingest.php';

use FluxFiles\LicenseServer\Collector\LogCollector;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Ingest;

try {
    $opts=getopt('',['day:','update-log:','demo-log:','cleaner-log:','mint-path:','output:']);
    $tz=new DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ','Asia/Ho_Chi_Minh'));$day=(string)($opts['day']??(new DateTimeImmutable('yesterday',$tz))->format('Y-m-d'));
    if (!Ingest::day($day)) { throw new RuntimeException(); }$parser=new LogCollector($day,$tz);$data=['day'=>$day];
    $open=static function(string $path) { $fp=@fopen($path,'r');if (!$fp) { throw new RuntimeException(); }return $fp; };
    if (isset($opts['update-log'])) { $fp=$open($opts['update-log']);try { $data['downloads']=$parser->updates($fp); }finally { fclose($fp); } }
    if (isset($opts['demo-log'])) { if (!isset($opts['mint-path'])) { throw new RuntimeException(); }$fp=$open($opts['demo-log']);try { $data['demo']=$parser->demo($fp,$opts['mint-path']); }finally { fclose($fp); } }
    if (isset($opts['cleaner-log'])) { $fp=$open($opts['cleaner-log']);try { $live=$parser->cleaner($fp);$data['demo']=array_merge($data['demo']??['sandboxes_created'=>0,'sandbox_limit_hits'=>0,'page_limit_hits'=>0],$live); }finally { fclose($fp); } }
    $file=(string)($opts['output']??'');if ($file==='' || basename($file)!==$day.'.json' || is_link($file) || !is_dir(dirname($file)) || count($data)<2) { throw new RuntimeException(); }
    if (isset($data['downloads'])) { Ingest::rows('downloads_daily',$day,$data['downloads']); }if (isset($data['demo'])) { Ingest::rows('demo_daily',$day,[$data['demo']]); }
    // The inbox is worker-writable. Drop root before creating or chmodding paths
    // there, so a compromised worker cannot turn a path race into a root write.
    if (!function_exists('posix_geteuid')) { throw new RuntimeException(); }
    if (posix_geteuid()===0) {
        $uid=(int)fileowner(dirname($file));$gid=(int)filegroup(dirname($file));$user=posix_getpwuid($uid);
        if ($uid===0 || !$user || !posix_initgroups($user['name'],$gid) || !posix_setgid($gid) || !posix_setuid($uid)) { throw new RuntimeException(); }
    }
    umask(0027);$temp=dirname($file).'/.counts-'.bin2hex(random_bytes(8));
    if (file_put_contents($temp,json_encode($data,JSON_UNESCAPED_SLASHES),LOCK_EX)===false || !chmod($temp,0640) || !chgrp($temp,(int)filegroup(dirname($file))) || !rename($temp,$file)) { @unlink($temp);throw new RuntimeException(); }
    echo "Counts-only inbox file written.\n";
} catch (Throwable $e) { fwrite(STDERR,"Counts collector failed; check the root job's paths and log format.\n");exit(1); }
