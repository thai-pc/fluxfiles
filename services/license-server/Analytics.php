<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/VersionRange.php';

final class Analytics
{
    public const SPREAD_NETWORKS = 20;
    public const REFRESH_LIMITS = 3;
    public const UNKNOWN_REFUSALS = 20;
    public const GUESSING_LIMIT = 20;
    public const BAD_SIGNATURES = 10;

    public function __construct(private LicenseStore $store) {}

    private function rows(string $sql, array $params = []): array
    {
        $q = $this->store->pdo()->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function orders(): array
    {
        return $this->rows("SELECT o.* FROM orders o WHERE o.gateway!='manual' AND NOT EXISTS(SELECT 1 FROM licenses l WHERE l.jti=o.jti AND (l.is_test=1 OR l.archived_at IS NOT NULL))");
    }

    public function customers(array $query = []): array
    {
        $groups = [];
        $licences = $this->rows('SELECT * FROM licenses WHERE is_test=0 AND archived_at IS NULL ORDER BY issued,id');
        foreach ($licences as $row) {
            $email = strtolower(trim((string) $row['email']));
            $groups[$email] ??= $this->emptyCustomer($email);
            $c = &$groups[$email]; $c['total']++; $c['active'] += $row['status'] === 'active' ? 1 : 0;
            $c['plans'][$row['plan']] = true; if ($row['customer_id']) { $c['customer_ids'][$row['customer_id']] = true; }
            if ($row['customer']) { $c['customer'] = $row['customer']; }
            $c['last_seen_at'] = max((int) $c['last_seen_at'], (int) $row['last_seen_at']);
            unset($c);
        }
        foreach ($this->orders() as $order) {
            $email = (string) $order['email_lower']; if ($email === '') { continue; }
            $groups[$email] ??= $this->emptyCustomer($email); $c = &$groups[$email];
            $at = (int) $order['paid_at']; if ($at > 0) { $c['first_purchase'] = $c['first_purchase'] === null ? $at : min($c['first_purchase'], $at); $c['last_purchase'] = max((int) $c['last_purchase'], $at); }
            if ($order['customer_id']) { $c['customer_ids'][$order['customer_id']] = true; }
            $currency = $order['currency']; $c['net'][$currency] = ($c['net'][$currency] ?? 0) + (int) $order['net_amount'] - (int) $order['refunded_amount'] + (int) $order['refunded_tax_amount'];
            unset($c);
        }
        foreach ($this->rows('SELECT * FROM customer_notes') as $note) { if (isset($groups[$note['email_lower']])) { $groups[$note['email_lower']]['note'] = $note['note']; } }
        $result = [];
        foreach ($groups as $c) {
            if (($query['q'] ?? '') !== '' && stripos($c['email'], (string) $query['q']) !== 0 && stripos($c['customer'], (string) $query['q']) !== 0) { continue; }
            $c['plans'] = array_keys($c['plans']); $c['customer_ids'] = array_keys($c['customer_ids']); $c['identity_conflict'] = count($c['customer_ids']) > 1;
            $result[$c['chash']] = Mask::row($c);
        }
        ksort($result);
        if (isset($query['cursor']) && $query['cursor'] !== '') { $result = array_filter($result, static fn (array $c): bool => strcmp($c['chash'], (string) $query['cursor']) > 0); }
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50))); $more = count($result) > $limit; $result = array_values(array_slice($result, 0, $limit));
        return ['rows' => $result, 'next_cursor' => $more ? end($result)['chash'] : null];
    }

    private function emptyCustomer(string $email): array
    {
        return ['chash' => substr(hash('sha256', $email), 0, 16), 'email' => $email, 'customer' => '', 'active' => 0, 'total' => 0, 'plans' => [], 'customer_ids' => [], 'first_purchase' => null, 'last_purchase' => null, 'last_seen_at' => null, 'net' => [], 'note' => null];
    }

    public function customer(string $hash): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $hash)) { return null; }
        $found = [];
        foreach ($this->rows('SELECT email FROM licenses UNION SELECT email_lower AS email FROM orders WHERE email_lower IS NOT NULL') as $r) {
            $email = strtolower(trim((string) $r['email'])); if (substr(hash('sha256', $email), 0, 16) === $hash) { $found[$email] = true; }
        }
        if (count($found) !== 1) { return null; }
        $email = (string) array_key_first($found);
        $licences = $this->rows('SELECT * FROM licenses WHERE lower(email)=? ORDER BY issued DESC,id DESC', [$email]);
        $orders = $this->rows('SELECT * FROM orders WHERE email_lower=? ORDER BY paid_at DESC,order_id', [$email]);
        $note = $this->rows('SELECT * FROM customer_notes WHERE email_lower=?', [$email])[0] ?? null;
        $timeline = [];
        foreach ($licences as $l) {
            foreach ($this->rows('SELECT * FROM license_history WHERE jti=? ORDER BY at DESC,id DESC LIMIT 50', [$l['jti']]) as $event) { $timeline[] = $event; }
        }
        usort($timeline, static fn (array $a, array $b): int => [$b['at'],$b['id']] <=> [$a['at'],$a['id']]);
        return ['chash' => $hash, 'email' => $email, 'licences' => array_map([Mask::class,'row'],$licences), 'orders' => array_map([Mask::class,'row'],$orders), 'timeline' => array_slice($timeline,0,200), 'note' => $note];
    }

    public function stats(string $kind, array $query = []): array
    {
        [$from,$to] = (new Stats($this->store->pdo()))->range($query);
        return match ($kind) {
            'customers' => $this->customerStats($from,$to),
            'versions' => $this->versions(),
            'downloads' => $this->downloads($from,$to),
            'growth' => $this->growth($from,$to),
            'demo' => ['series'=>$this->rows('SELECT * FROM demo_daily WHERE day>=? AND day<? ORDER BY day',[$from->format('Y-m-d'),$to->format('Y-m-d')]), 'budget_bytes'=>max(0,(int)Config::get('FLUXFILES_ADMIN_DEMO_BUDGET_BYTES','0'))],
            'anomalies' => $this->anomalies(),
            default => throw new \InvalidArgumentException('invalid_input'),
        };
    }

    private function customerStats(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tz = new \DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ','Asia/Ho_Chi_Minh'));
        $monthOf = static fn (int $ts): string => (new \DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m');
        $customers=[]; $monthly=[]; $licences=$this->rows('SELECT * FROM licenses WHERE is_test=0 AND archived_at IS NULL');
        foreach ($this->orders() as $o) {
            $at=(int)$o['paid_at']; if ($at<=0) { continue; }
            $id=$o['customer_id'] ?: $o['email_lower']; if (!$id) { continue; }
            $customers[$id] ??= ['first'=>$at,'emails'=>[],'jtis'=>[],'customer_id'=>$o['customer_id']];$c=&$customers[$id];$c['first']=min($c['first'],$at);$c['emails'][(string)$o['email_lower']]=true;if ($o['jti']) { $c['jtis'][$o['jti']]=true; } unset($c);
            if ($at>=$from->getTimestamp() && $at<$to->getTimestamp() && ($o['billing_reason']==='subscription_cycle' || str_ends_with((string)$o['plan'],'-renewal'))) { $monthly[$monthOf($at)]['renewed'][$id]=true; }
        }
        $cohorts=[];
        foreach ($customers as $id=>$c) {
            $cohort=$monthOf($c['first']);$cohorts[$cohort][$id]=$c;
            if ($c['first']>=$from->getTimestamp() && $c['first']<$to->getTimestamp()) { $monthly[$cohort]['new'][$id]=true; }
        }
        foreach ($this->rows("SELECT subscription_id,revoked_at FROM subscriptions WHERE status='revoked' AND revoked_at>=? AND revoked_at<?",[$from->getTimestamp(),$to->getTimestamp()]) as $r) { $monthly[$monthOf((int)$r['revoked_at'])]['churned'][$r['subscription_id']]=true; }
        foreach ($licences as $l) {
            $at=(int)$l['expires']; if ($l['subscription_id'] || !$at || $at<$from->getTimestamp() || $at>=$to->getTimestamp()) { continue; }
            $renewed=false;
            foreach ($licences as $n) { if (strtolower($n['email'])===strtolower($l['email']) && $n['jti']!==$l['jti'] && (int)$n['issued']>(int)$l['issued'] && (int)$n['expires']>$at && !in_array($n['status'],['revoked','refunded'],true)) { $renewed=true;break; } }
            if (!$renewed) { $monthly[$monthOf($at)]['update_window_ended'][$l['jti']]=true; }
        }
        $series=[];
        for ($m=$from->modify('first day of this month');$m<$to;$m=$m->modify('+1 month')) {
            $key=$m->format('Y-m');$row=['month'=>$key];foreach (['new','renewed','churned','update_window_ended'] as $metric) { $row[$metric]=count($monthly[$key][$metric]??[]); }$series[]=$row;
        }
        $retention=[];
        foreach ($cohorts as $cohort=>$members) {
            $start=new \DateTimeImmutable($cohort.'-01',$tz);if ($start<$from->modify('first day of this month') || $start>=$to) { continue; }
            for ($offset=0;$offset<=12;$offset++) {
                $end=$start->modify('+'.($offset+1).' months')->getTimestamp()-1;if ($end>time()) { continue; }$active=0;
                foreach ($members as $c) {
                    foreach ($licences as $l) {
                        if ((!isset($c['jtis'][$l['jti']]) && ($c['customer_id'] ? $l['customer_id'] !== $c['customer_id'] : !isset($c['emails'][strtolower($l['email'])]))) || (int)$l['issued']>$end || ($l['expires']!==null && (int)$l['expires']<=$end)) { continue; }
                        $status=$this->statusAt($l,$end);if (!in_array($status,['active','superseded'],true)) { continue; }$active++;break;
                    }
                }
                $retention[]=['cohort'=>$cohort,'month'=>$offset,'customers'=>count($members),'retained'=>$active,'rate'=>count($members)?round($active/count($members)*100,2):0];
            }
        }
        return ['series'=>$series,'cohorts'=>$retention];
    }

    private function statusAt(array $licence,int $at): string
    {
        $row=$this->rows("SELECT to_status FROM license_history WHERE jti=? AND at<=? AND to_status IS NOT NULL ORDER BY at DESC,id DESC LIMIT 1",[$licence['jti'],$at])[0]??null;
        return $row ? (string)$row['to_status'] : (string)$licence['status'];
    }

    public function versions(): array
    {
        $latest=$this->rows("SELECT p.jti,p.module,p.version,p.core_version,p.last_at,l.email FROM install_pings p JOIN licenses l USING(jti) WHERE p.last_at>=? AND l.is_test=0 AND l.archived_at IS NULL AND l.status IN ('active','superseded') AND NOT EXISTS(SELECT 1 FROM install_pings n WHERE n.jti=p.jti AND n.module=p.module AND (n.last_at>p.last_at OR (n.last_at=p.last_at AND (n.day>p.day OR (n.day=p.day AND n.net>p.net)))))",[time()-30*86400]);
        $core=[];$installs=[];
        foreach ($latest as $row) {
            $version=is_string($row['version']) && VersionRange::validVersion($row['version'])?$row['version']:null;
            $installs[]=['jti'=>$row['jti'],'component'=>$row['module'],'version'=>$version,'last_at'=>(int)$row['last_at'],'email'=>$row['email']];
            if (!isset($core[$row['jti']]) || $core[$row['jti']]['last_at']<(int)$row['last_at']) { $core[$row['jti']]=['jti'=>$row['jti'],'component'=>'core','version'=>is_string($row['core_version'])&&VersionRange::validVersion($row['core_version'])?$row['core_version']:null,'last_at'=>(int)$row['last_at'],'email'=>$row['email']]; }
        }
        $installs=array_merge($installs,array_values($core));$counts=[];
        foreach ($installs as $row) { $key=$row['component'].':'.($row['version']??'unknown');$counts[$key]??=['component'=>$row['component'],'version'=>$row['version'],'n'=>0];$counts[$key]['n']++; }
        $advisories=[];
        foreach ($this->rows('SELECT * FROM advisories ORDER BY created_at DESC,id') as $advisory) {
            $affected=0;$unknown=0;
            foreach ($installs as $row) { if ($row['component']!==$advisory['component']) { continue; }if ($row['version']===null) { $unknown++; }elseif (VersionRange::matches($row['version'],$advisory['affected'])) { $affected++; } }
            $advisory['affected_count']=$affected;$advisory['unknown_count']=$unknown;$advisories[]=$advisory;
        }
        return ['components'=>array_values($counts),'installs'=>$installs,'advisories'=>$advisories];
    }

    public function affected(string $id,bool $includeUnknown): array
    {
        $advisory=$this->rows('SELECT * FROM advisories WHERE id=?',[$id])[0]??null;if (!$advisory) { throw new \InvalidArgumentException('not_found'); }
        $rows=[];
        foreach ($this->versions()['installs'] as $r) { if ($r['component']===$advisory['component'] && ($r['version']===null?$includeUnknown:VersionRange::matches($r['version'],$advisory['affected']))) { $rows[]=$r; } }
        return ['advisory'=>$advisory,'installs'=>$rows];
    }

    private function downloads(\DateTimeImmutable $from,\DateTimeImmutable $to): array
    {
        $params=[$from->format('Y-m-d'),$to->format('Y-m-d')];
        $rows=$this->rows('SELECT * FROM downloads_daily WHERE day>=? AND day<? ORDER BY day,module,source,version',$params);
        $checks=$this->rows("SELECT p.day,p.module,SUM(p.n) n FROM install_pings p JOIN licenses l USING(jti) WHERE p.day>=? AND p.day<? AND l.is_test=0 AND l.archived_at IS NULL GROUP BY p.day,p.module ORDER BY p.day,p.module",$params);
        return ['series'=>$rows,'checks'=>$checks,'versions'=>$this->rows('SELECT module,version,source,SUM(n) n FROM downloads_daily WHERE day>=? AND day<? GROUP BY module,version,source ORDER BY module,version,source',$params)];
    }

    private function growth(\DateTimeImmutable $from,\DateTimeImmutable $to): array
    {
        $rows=$this->rows('SELECT * FROM public_counters WHERE day>=? AND day<? ORDER BY day,source,subject,metric',[$from->format('Y-m-d'),$to->format('Y-m-d')]);
        $latest=[];foreach ($rows as $r) { $latest[$r['source'].':'.$r['subject'].':'.$r['metric']]=$r; }
        $url=Config::get('FLUXFILES_ADMIN_ANALYTICS_URL','https://dash.cloudflare.com/');
        if (parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_HOST)!=='dash.cloudflare.com' || parse_url($url,PHP_URL_USER)!==null) { $url='https://dash.cloudflare.com/'; }
        return ['series'=>$rows,'subjects'=>array_values($latest),'analytics_url'=>$url];
    }

    public function anomalies(): array
    {
        $spread=$this->rows("SELECT p.jti,COUNT(DISTINCT p.net) networks FROM install_pings p JOIN licenses l USING(jti) WHERE p.last_at>=? AND p.net!='' AND l.is_test=0 AND l.archived_at IS NULL GROUP BY p.jti HAVING COUNT(DISTINCT p.net)>CAST(? AS INTEGER)",[time()-30*86400,self::SPREAD_NETWORKS]);
        // Bind expression comparisons explicitly: SQLite otherwise compares numbers to text.
        $refresh=$this->rows("SELECT day,jti,SUM(n) n FROM activity_daily WHERE kind='refresh' AND result='rate_limited' GROUP BY day,jti HAVING SUM(n)>CAST(? AS INTEGER)",[self::REFRESH_LIMITS]);
        $unknown=$this->rows("SELECT day,kind,SUM(n) n FROM activity_daily WHERE jti='' AND result NOT IN ('ok','current','refreshed') GROUP BY day,kind HAVING SUM(n)>CAST(? AS INTEGER)",[self::UNKNOWN_REFUSALS]);
        $guessing=$this->rows("SELECT day,SUM(n) n FROM activity_daily WHERE kind='pro_download' AND jti='' AND result!='ok' GROUP BY day HAVING SUM(n)>CAST(? AS INTEGER)",[self::GUESSING_LIMIT]);
        $signatures=$this->rows("SELECT CAST(received_at/3600 AS INTEGER)*3600 at,SUM(attempts) n FROM webhook_events WHERE verify!='ok' GROUP BY CAST(received_at/3600 AS INTEGER) HAVING SUM(attempts)>CAST(? AS INTEGER)",[self::BAD_SIGNATURES]);
        return ['thresholds'=>['networks_30d'=>self::SPREAD_NETWORKS,'refresh_limits_day'=>self::REFRESH_LIMITS,'unknown_refusals_day'=>self::UNKNOWN_REFUSALS,'guessing_day'=>self::GUESSING_LIMIT,'bad_signatures_hour'=>self::BAD_SIGNATURES], 'spread'=>$spread,'refresh'=>$refresh,'unknown'=>$unknown,'guessing'=>$guessing,'signatures'=>$signatures,'automatic_revocation'=>false];
    }

    public function health(): array
    {
        return ['targets'=>$this->rows('SELECT target,COUNT(*) samples,SUM(ok) successes,ROUND(AVG(ok)*100,2) uptime,MIN(at) first_at,MAX(at) last_at FROM health_checks WHERE at>=? GROUP BY target ORDER BY target',[time()-30*86400]),'latest'=>$this->rows('SELECT h.* FROM health_checks h WHERE NOT EXISTS(SELECT 1 FROM health_checks n WHERE n.target=h.target AND n.at>h.at) ORDER BY h.target'),'heartbeat'=>$this->store->state('heartbeat')];
    }
}
