<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;

/** A key rotation must not turn an existing installation into a new customer site. */
final class InstallGroups
{
    public array $groups=[];
    private array $byJti=[];
    public int $migratedAt;

    public function __construct(private PDO $db)
    {
        $state=$db->query("SELECT value FROM ops_state WHERE key='telemetry_migration'")->fetchColumn();
        $this->migratedAt=(int)(json_decode((string)$state,true)['at']??time());
        $rows=$db->query('SELECT * FROM licenses WHERE is_test=0 AND archived_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        $all=array_column($rows,null,'jti');
        foreach ($rows as $row) {
            $root=$row;$seen=[];
            while (!empty($root['renews_jti']) && isset($all[$root['renews_jti']]) && !isset($seen[$root['jti']])) {
                $seen[$root['jti']]=true;$root=$all[$root['renews_jti']];
            }
            $key=$row['subscription_id'] ? 'sub:'.$row['subscription_id'] : 'root:'.$root['jti'];
            $this->byJti[$row['jti']]=$key;
            $this->groups[$key]??=['head'=>null,'created_at'=>(int)$row['created_at'],'first_seen_at'=>null,'last_seen_at'=>null];
            $g=&$this->groups[$key];$g['created_at']=min($g['created_at'],(int)$row['created_at']);
            foreach (['first_seen_at','last_seen_at'] as $field) {
                if ($row[$field]!==null) { $g[$field]=$g[$field]===null?(int)$row[$field]:($field==='first_seen_at'?min($g[$field],(int)$row[$field]):max($g[$field],(int)$row[$field])); }
            }
            if ($row['status']==='active' && $row['modules']!=='' && ($g['head']===null || [$row['issued'],$row['id']]>[$g['head']['issued'],$g['head']['id']])) { $g['head']=$row; }
            unset($g);
        }
        $this->groups=array_filter($this->groups,static fn(array $g):bool=>$g['head']!==null);
    }

    public function pings(int $since=0): array
    {
        $q=$this->db->prepare("SELECT p.*,l.status FROM install_pings p JOIN licenses l USING(jti) WHERE p.last_at>=? AND l.is_test=0 AND l.archived_at IS NULL AND l.status IN ('active','superseded') ORDER BY p.last_at,p.day,p.net,p.module");
        $q->execute([$since]);$out=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $ping) {
            $key=$this->byJti[$ping['jti']]??'';if (!isset($this->groups[$key])) { continue; }
            $ping['group']=$key;$out[]=$ping;
        }
        return $out;
    }

    public function never(array $group,?int $now=null): bool
    {
        return $group['head']['gateway']!=='manual' && $group['first_seen_at']===null
            && max($group['created_at'],$this->migratedAt)<($now??time())-7*86400;
    }
}
