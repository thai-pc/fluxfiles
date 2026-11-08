<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Collector;

/** Root-side parser only. Workers consume its counts and never import this class. */
final class LogCollector
{
    public function __construct(private string $day,private \DateTimeZone $timezone) {}

    private function request(string $line): ?array
    {
        $data=json_decode($line,true);
        if (is_array($data)) { $uri=$data['uri']??$data['request_uri']??'';$status=$data['status']??0;$time=$data['time_iso8601']??$data['time']??''; }
        elseif (preg_match('~\[([^\]]+)\] "(?:GET|POST) ([^ ]+) HTTP/[^"]+" ([0-9]{3}) ~',$line,$m)) { $time=$m[1];$uri=$m[2];$status=(int)$m[3]; }
        else { return null; }
        if (!is_string($uri) || !is_string($time) || trim($time)==='' || strlen($uri)>8192) { return null; }
        try { $at=\DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O',$time) ?: new \DateTimeImmutable($time); }catch(\Throwable $e) { return null; }
        if ($at->setTimezone($this->timezone)->format('Y-m-d')!==$this->day) { return null; }
        // Discard the query before considering filenames. No caller identity survives.
        $path=explode('?',$uri,2)[0];return ['uri'=>$path,'status'=>(int)$status];
    }

    public function updates($stream): array
    {
        $counts=[];
        while (($line=fgets($stream,16384))!==false) {
            $r=$this->request($line);if (!$r || !in_array($r['status'],[200,206],true) || !preg_match('~^/dl/(?:m|free)/([^/]+)\.zip$~D',$r['uri'],$m)) { continue; }
            $file=rawurldecode($m[1]);
            if (!preg_match('/^([a-z0-9-]{1,64})-([0-9]{1,4}(?:\.[0-9]{1,4}){1,3}(?:[.+-][0-9A-Za-z.-]{1,20})?)$/D',$file,$parts)) { continue; }
            $key=$parts[1].':'.$parts[2];$counts[$key]??=['module'=>$parts[1],'version'=>$parts[2],'n'=>0];$counts[$key]['n']++;
        }
        return array_values($counts);
    }

    public function demo($stream,string $mintPath): array
    {
        if (!preg_match('~^/[A-Za-z0-9/_-]{1,200}$~D',$mintPath)) { throw new \InvalidArgumentException('invalid mint path'); }
        $counts=['sandboxes_created'=>0,'sandbox_limit_hits'=>0,'page_limit_hits'=>0,'sandboxes_live'=>0,'uploads_bytes'=>0];
        while (($line=fgets($stream,16384))!==false) { $r=$this->request($line);if (!$r) { continue; }if ($r['uri']===$mintPath && in_array($r['status'],[200,201],true)) { $counts['sandboxes_created']++; }if ($r['status']===429) { $counts[$r['uri']===$mintPath?'sandbox_limit_hits':'page_limit_hits']++; } }
        return $counts;
    }

    public function cleaner($stream): array
    {
        $counts=['sandboxes_live'=>0,'uploads_bytes'=>0];
        while (($line=fgets($stream,16384))!==false) {
            $data=json_decode($line,true);
            if (!is_array($data) && preg_match('/sandboxes_live=([0-9]{1,12}).*uploads_bytes=([0-9]{1,15})/',$line,$m)) { $data=['sandboxes_live'=>(int)$m[1],'uploads_bytes'=>(int)$m[2]]; }
            if (!is_array($data) || (isset($data['day']) && $data['day']!==$this->day)) { continue; }
            if (is_int($data['sandboxes_live']??null) && $data['sandboxes_live']>=0 && is_int($data['uploads_bytes']??null) && $data['uploads_bytes']>=0) { $counts=array_intersect_key($data,$counts); }
        }
        return $counts;
    }
}
