<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

use FluxFiles\LicenseServer\Client\Config;

/** Optional additional gate. App password/TOTP is always required independently. */
final class Access
{
    public static function enabled(): bool { return Config::get('FLUXFILES_ADMIN_ACCESS_ENABLED')==='1'; }

    public static function issuer(string $issuer): bool
    {
        return preg_match('~^https://[a-z0-9-]+\.cloudflareaccess\.com$~D',$issuer)===1;
    }

    public static function verify(string $token,string $issuer,string $audience,array $jwks,?int $now=null): bool
    {
        $now??=time();
        if (!self::issuer($issuer) || $audience==='' || strlen($token)>16384 || count($parts=explode('.',$token))!==3) { return false; }
        $header=json_decode(self::decode($parts[0])??'',true);$claims=json_decode(self::decode($parts[1])??'',true);$signature=self::decode($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature===null || ($header['alg']??'')!=='RS256' || !is_string($header['kid']??null)) { return false; }
        if (!is_array($jwks['keys']??null)) { return false; }
        $keys=[];foreach ($jwks['keys'] as $key) { if (is_array($key) && ($key['kid']??null)===$header['kid'] && ($key['kty']??'')==='RSA' && ($key['use']??'sig')==='sig' && ($key['alg']??'RS256')==='RS256') { $keys[]=$key; } }
        if (count($keys)!==1) { return false; }$key=$keys[0];
        $n=is_string($key['n']??null)?self::decode($key['n']):null;$e=is_string($key['e']??null)?self::decode($key['e']):null;
        if ($n===null || $e===null || strlen($n)<256 || strlen($n)>1024 || strlen($e)>8 || $e==='') { return false; }
        $rsa=self::der(0x30,self::integer($n).self::integer($e));
        $spki=self::der(0x30,hex2bin('300d06092a864886f70d0101010500').self::der(0x03,"\0".$rsa));
        $pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
        if (@openssl_verify($parts[0].'.'.$parts[1],$signature,$pem,OPENSSL_ALGO_SHA256)!==1) { return false; }
        $aud=$claims['aud']??[];$aud=is_string($aud)?[$aud]:$aud;$matches=false;
        if (!is_array($aud)) { return false; }foreach ($aud as $value) { if (is_string($value) && hash_equals($audience,$value)) { $matches=true; } }
        return $matches && is_string($claims['iss']??null) && hash_equals($issuer,$claims['iss']) && is_int($claims['exp']??null) && $claims['exp']>$now
            && (!isset($claims['nbf']) || (is_int($claims['nbf']) && $claims['nbf']<=$now+60)) && is_int($claims['iat']??null) && $claims['iat']<=$now+60 && $claims['iat']<$claims['exp'];
    }

    public static function allows(array $server): bool
    {
        if (!self::enabled()) { return true; }
        $file=Config::get('FLUXFILES_ADMIN_ACCESS_JWKS_FILE');
        if ($file==='' || is_link($file) || !is_file($file) || filesize($file)>65536 || (int)filemtime($file)<time()-86400) { return false; }
        $keys=json_decode((string)@file_get_contents($file),true);
        return is_array($keys) && self::verify((string)($server['HTTP_CF_ACCESS_JWT_ASSERTION']??''),Config::get('FLUXFILES_ADMIN_ACCESS_ISSUER'),Config::get('FLUXFILES_ADMIN_ACCESS_AUD'),$keys);
    }

    public static function downloadKeys(string $issuer): ?array
    {
        if (!self::issuer($issuer)) { Config::loopback($issuer); }
        $raw='';$ch=curl_init($issuer.'/cdn-cgi/access/certs');
        curl_setopt_array($ch,[CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$raw):int {
                if (strlen($raw)+strlen($chunk)>65536) { return 0; }$raw.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);unset($ch);
        $data=$ok!==false && $status===200?json_decode($raw,true):null;
        return is_array($data) && is_array($data['keys']??null) && $data['keys']!==[]?$data:null;
    }

    private static function decode(string $value): ?string
    {
        if ($value==='' || preg_match('/[^A-Za-z0-9_-]/',$value)) { return null; }$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);return $decoded===false?null:$decoded;
    }
    private static function integer(string $value): string
    {
        $value=ltrim($value,"\0");if ($value==='' || (ord($value[0])&0x80)) { $value="\0".$value; }return self::der(0x02,$value);
    }
    private static function der(int $type,string $value): string
    {
        $length=strlen($value);$encoded='';if ($length<128) { $encoded=chr($length); }else { for($n=$length;$n>0;$n>>=8) { $encoded=chr($n&255).$encoded; }$encoded=chr(128|strlen($encoded)).$encoded; }return chr($type).$encoded.$value;
    }
}
