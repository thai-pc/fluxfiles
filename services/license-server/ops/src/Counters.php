<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Ops;

use FluxFiles\LicenseServer\Client\Config;

require_once __DIR__ . '/../../client/Config.php';
require_once __DIR__ . '/../../client/Ingest.php';

final class Counters
{
    public function targets(): array
    {
        $config=json_decode(Config::get('FLUXFILES_OPS_PUBLIC_COUNTERS','{}'),true);
        if (!is_array($config) || array_diff(array_keys($config),['wporg','github','packagist','npm'])) { throw new \InvalidArgumentException('invalid counter configuration'); }
        $targets=[];
        foreach ($config as $source=>$subjects) {
            if (!is_array($subjects) || !array_is_list($subjects)) { throw new \InvalidArgumentException('invalid counter configuration'); }
            foreach ($subjects as $subject) {
                if (!is_string($subject) || strlen($subject)>200 || !preg_match(match($source){'wporg'=>'~^[a-z0-9-]{1,100}$~D','github','packagist'=>'~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D',default=>'~^(?:@[a-z0-9_.-]+/)?[a-z0-9_.-]+$~D'},$subject)) { throw new \InvalidArgumentException('invalid counter subject'); }
                $targets[$source.':'.$subject]=['source'=>$source,'subject'=>$subject];
            }
        }
        if (count($targets)>20) { throw new \InvalidArgumentException('at most twenty counter requests'); }
        return array_values($targets);
    }

    public function fetch(array $target,int $timeout=5): array
    {
        $source=$target['source'];$subject=$target['subject'];
        $url=match($source){
            'wporg'=>'https://api.wordpress.org/plugins/info/1.2/?'.http_build_query(['action'=>'plugin_information','request'=>['slug'=>$subject,'fields'=>['sections'=>false,'reviews'=>false,'screenshots'=>false,'banners'=>false,'icons'=>false]]]),
            'github'=>'https://api.github.com/repos/'.implode('/',array_map('rawurlencode',explode('/',$subject))),
            'packagist'=>'https://packagist.org/packages/'.implode('/',array_map('rawurlencode',explode('/',$subject))).'/stats.json',
            'npm'=>'https://api.npmjs.org/downloads/point/last-week/'.rawurlencode($subject),
        };
        $override=Config::get('FLUXFILES_OPS_COUNTER_API_BASE');if ($override!=='') { $url=Config::loopback($override).'/'.$source.'/'.rawurlencode($subject); }
        $buffer='';$start=microtime(true);$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>max(1,min(5,$timeout)),CURLOPT_USERAGENT=>'FluxFiles-Ops/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use(&$buffer):int { if (strlen($buffer)+strlen($chunk)>1048576) { return 0; }$buffer.=$chunk;return strlen($chunk); }]);
        $result=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);unset($ch);
        $data=$result!==false && $status===200?json_decode($buffer,true):null;$rows=[];
        if (is_array($data)) {
            $values=match($source){'wporg'=>['active_installs'=>$data['active_installs']??null,'downloaded'=>$data['downloaded']??null],'github'=>['stargazers_count'=>$data['stargazers_count']??null,'forks_count'=>$data['forks_count']??null],'packagist'=>['downloads.total'=>$data['downloads']['total']??null,'downloads.monthly'=>$data['downloads']['monthly']??null],default=>['downloads'=>$data['downloads']??null]};
            foreach ($values as $metric=>$n) {
                if (is_string($n) && preg_match('/^[0-9]{1,15}$/D',$n)) { $n=(int)$n; }
                if (!is_int($n) || $n<0 || $n>9007199254740991) { $rows=[];break; }
                $rows[]=['source'=>$source,'subject'=>$subject,'metric'=>$metric,'value'=>$n];
            }
        }
        return ['ok'=>$rows!==[],'rows'=>$rows,'ms'=>(int)round((microtime(true)-$start)*1000),'detail'=>$rows!==[]?'ok':'counter_http_'.$status];
    }
}
