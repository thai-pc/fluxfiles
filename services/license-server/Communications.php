<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;
use FluxFiles\LicenseServer\Client\OpsText;

/** Mail credentials stay in the API process. The worker only requests bounded jobs. */
final class Communications
{
    public function __construct(private LicenseStore $store,private string $role) {}

    private function rows(string $sql,array $params=[]): array
    {
        $q=$this->store->pdo()->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function handle(string $method,string $path,array $body,array $query): ?array
    {
        if ($method==='GET' && $path==='/admin/notices') { return [200,['rows'=>$this->rows('SELECT * FROM notices ORDER BY id DESC LIMIT 100')]]; }
        if ($method==='GET' && $path==='/admin/notices/preview') { return [200,$this->preview($query)]; }
        if ($method==='POST' && $path==='/admin/notices/send') { return [200,$this->queue($body)]; }
        return [404,['error'=>'not_found']];
    }

    private function content(array $body): array
    {
        $id=$body['advisory']??$body['advisory_id']??'';$unknown=($body['include_unknown']??false)===true || ($body['include_unknown']??'')==='1';
        if (!is_string($id)) { throw new \InvalidArgumentException(); }
        $affected=(new Analytics($this->store))->affected($id,$unknown);$advisory=$affected['advisory'];
        $subject=$body['subject']??('FluxFiles security update: '.$advisory['id'].' — {fixed_in}');
        $text=$body['body_text']??'A security update is available for FluxFiles {edition}. Your reported version is {version}. Please update to {fixed_in}. Details: {url}';
        if (!is_string($subject) || $subject==='' || mb_strlen($subject)>200 || strpbrk($subject,"\r\n")!==false || !is_string($text) || $text==='' || mb_strlen($text)>10000) { throw new \InvalidArgumentException(); }
        Mask::safe($subject);Mask::safe($text);
        $recipients=[];
        foreach ($affected['installs'] as $install) {
            // An old superseded key may report a vulnerable install; use the buyer's
            // current active licence for delivery and never mail a revoked-only buyer.
            $rows=$this->rows("SELECT jti,email,edition,modules FROM licenses WHERE lower(email)=lower(?) AND status='active' AND is_test=0 AND archived_at IS NULL ORDER BY issued DESC,id DESC",[$install['email']]);
            foreach ($rows as $row) {
                if ($advisory['component']!=='core' && !in_array($advisory['component'],explode(',',(string)$row['modules']),true) && $advisory['component']!=='fluxfiles-pro') { continue; }
                if ($advisory['component']==='fluxfiles-pro' && !array_intersect(['share','intake'],explode(',',(string)$row['modules']))) { continue; }
                $email=strtolower($row['email']);
                $recipients[$email]??=$row+['version'=>$install['version']??'unknown'];break;
            }
        }
        return ['advisory'=>$advisory,'include_unknown'=>$unknown,'subject'=>$subject,'body_text'=>$text,'recipients'=>array_values($recipients)];
    }

    private function interpolate(string $text,array $recipient,array $advisory): string
    {
        return strtr($text,['{edition}'=>(string)$recipient['edition'],'{version}'=>(string)($recipient['version']??'unknown'),'{fixed_in}'=>(string)($advisory['fixed_in']??'the latest release'),'{url}'=>(string)($advisory['url']??'https://fluxfiles.thai-pc.com/docs')]);
    }

    public function preview(array $body): array
    {
        $content=$this->content($body);$sample=$content['recipients'][0]??['edition'=>'Pro','version'=>'unknown'];
        return ['advisory'=>$content['advisory'],'include_unknown'=>$content['include_unknown'],'recipients'=>count($content['recipients']),'samples'=>array_map(static fn(array $r):string=>Mask::email($r['email']),array_slice($content['recipients'],0,5)),
            'subject'=>$this->interpolate($content['subject'],$sample,$content['advisory']),'body_text'=>$this->interpolate($content['body_text'],$sample,$content['advisory'])."\n\nReply to stop receiving these security notices.",
            'template_subject'=>$content['subject'],'template_body'=>$content['body_text'],'preview_fingerprint'=>$this->previewFingerprint($content),'example'=>['edition'=>$sample['edition'],'version'=>$sample['version']??'unknown','fixed_in'=>$content['advisory']['fixed_in']??'the latest release','url'=>$content['advisory']['url']??'https://fluxfiles.thai-pc.com/docs']];
    }

    private function previewFingerprint(array $content): string
    {
        $recipients=array_map(static fn(array $r):array=>[$r['jti'],$r['email'],$r['edition'],$r['version']],$content['recipients']);sort($recipients);
        return hash('sha256',json_encode([$content['advisory'],$content['include_unknown'],$recipients]));
    }

    private function queue(array $body): array
    {
        $request=$body['request_id']??'';if (!is_string($request) || !preg_match('/^[a-f0-9]{32}$/D',$request)) { throw new \InvalidArgumentException(); }
        $requestFingerprint=hash('sha256',json_encode([$body['advisory']??$body['advisory_id']??null,($body['include_unknown']??false)===true || ($body['include_unknown']??'')==='1',$body['subject']??null,$body['body_text']??null,$body['confirmation']??null,$body['preview_fingerprint']??null]));
        $receipt=$this->store->state('notice:'.$request);
        if ($receipt) {
            if (($receipt['request_fingerprint']??'')!==$requestFingerprint) { throw new \InvalidArgumentException(); }
            return ['id'=>$receipt['id'],'queued'=>$receipt['recipients'],'reused'=>true];
        }
        $content=$this->content($body);$advisory=$content['advisory'];
        if (($body['confirmation']??'')!==$advisory['id'] || count($content['recipients'])>10000) { throw new \InvalidArgumentException(); }
        if (!is_string($body['preview_fingerprint']??null) || !hash_equals($this->previewFingerprint($content),$body['preview_fingerprint'])) { throw new \InvalidArgumentException('preview_changed'); }
        $fingerprint=hash('sha256',json_encode([$advisory['id'],$content['subject'],$content['body_text'],$content['include_unknown']]));
        return $this->store->transaction(function() use($content,$advisory,$request,$fingerprint,$requestFingerprint):array {
            $state=$this->store->state('notice:'.$request);
            if ($state) { if (($state['request_fingerprint']??'')!==$requestFingerprint) { throw new \InvalidArgumentException(); }return ['id'=>$state['id'],'queued'=>$state['recipients'],'reused'=>true]; }
            $db=$this->store->pdo();$actor=MachineAuth::actor($this->role,$_SERVER);$n=count($content['recipients']);
            $db->prepare('INSERT INTO notices(advisory_id,created_at,created_by,subject,body_text,recipients,status,include_unknown,context_json) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$advisory['id'],time(),$actor,$content['subject'],$content['body_text'],$n,$n?'sending':'done',(int)$content['include_unknown'],json_encode(['advisory'=>$advisory,'versions'=>array_column($content['recipients'],'version','jti')])]);$id=(int)$db->lastInsertId();
            $q=$db->prepare("INSERT INTO ops_outbox(created_at,channel,kind,severity,dedup_key,text,payload,next_at) VALUES(?,'email','notice','info',?,?,?,?)");
            foreach ($content['recipients'] as $recipient) { $q->execute([time(),'notice:'.$id.':'.hash('sha256',strtolower($recipient['email'])),'Security notice · '.Mask::email($recipient['email']),json_encode(['notice_id'=>$id,'jti'=>$recipient['jti']]),time()]); }
            $this->store->state('notice:'.$request,['id'=>$id,'fingerprint'=>$fingerprint,'request_fingerprint'=>$requestFingerprint,'recipients'=>$n]);$this->store->audit('notice.send',(string)$id,'ok',null,['recipients'=>$n,'advisory'=>$advisory['id']]);
            return ['id'=>$id,'queued'=>$n,'reused'=>false];
        });
    }

    public function jobs(int $limit): array
    {
        $db=$this->store->pdo();$deadline=microtime(true)+12;
        $jobs=$this->store->transaction(function() use($db,$limit):array {
            $q=$db->prepare("SELECT * FROM ops_outbox WHERE channel='email' AND sent_at IS NULL AND dropped_at IS NULL AND next_at<=? AND COALESCE(lease_until,0)<=CAST(? AS INTEGER) ORDER BY id LIMIT ?");
            $q->bindValue(1,time(),PDO::PARAM_INT);$q->bindValue(2,time(),PDO::PARAM_INT);$q->bindValue(3,max(1,min(10,$limit)),PDO::PARAM_INT);$q->execute();$rows=$q->fetchAll();
            foreach ($rows as &$row) { $db->prepare('UPDATE ops_outbox SET lease_until=?,attempts=attempts+1 WHERE id=?')->execute([time()+120,$row['id']]);$row['attempts']++; }unset($row);return $rows;
        });
        $sent=0;$failed=0;
        foreach ($jobs as $job) {
            $left=(int)floor($deadline-microtime(true));
            if ($left<1) { $db->prepare('UPDATE ops_outbox SET lease_until=NULL,attempts=MAX(attempts-1,0) WHERE id=?')->execute([$job['id']]);continue; }
            $payload=json_decode((string)$job['payload'],true);$noticeId=(int)($payload['notice_id']??0);$notice=$this->rows('SELECT * FROM notices WHERE id=?',[$noticeId])[0]??null;
            $record=is_string($payload['jti']??null)?$this->store->findByJti($payload['jti']):null;$ok=false;$drop=false;$error='';
            if (!$notice || !$record || $record['status']!=='active' || $record['is_test'] || $record['archived_at']) { $drop=true;$error='recipient_ineligible'; }
            else {
                $context=json_decode((string)($notice['context_json']??''),true);$advisory=$context['advisory']??null;
                if (!$advisory) { $drop=true;$error='advisory_missing'; }
                else {
                    $version=$context['versions'][$record['jti']]??'unknown';
                    $recipient=$record+['version'=>$version];$subject=$this->interpolate($notice['subject'],$recipient,$advisory);$body=$this->interpolate($notice['body_text'],$recipient,$advisory)."\n\nReply to stop receiving these security notices.";
                    try {
                        Mask::safe($subject);Mask::safe($body);
                        $mailer=(new AdminApi($this->store,'ops'))->mailer($record['jti']);$mailer->setTimeout($left);$mailer->setIdempotencyKey('notice-'.$noticeId.'-'.$job['id']);
                        $ok=$mailer->sendStructured('notice',$record['email'],$subject,$body);
                    } catch (\Throwable $e) { $error='notice_failed'; }
                    if (!$ok && $error==='') { $error='mail_failed'; }
                }
            }
            $drop=$drop || (!$ok && (int)$job['attempts']>=10);$delay=min(3600,60*(2**min(6,(int)$job['attempts']-1)));
            $this->store->transaction(function() use($db,$job,$noticeId,$ok,$drop,$error,$delay):void {
                $q=$db->prepare('UPDATE ops_outbox SET lease_until=NULL,sent_at=?,dropped_at=?,next_at=?,last_error=? WHERE id=? AND sent_at IS NULL AND dropped_at IS NULL');$q->execute([$ok?time():null,$drop?time():null,time()+$delay,$ok?null:$error,$job['id']]);
                if ($q->rowCount() && ($ok || $drop)) { $db->prepare('UPDATE notices SET sent=sent+?,failed=failed+? WHERE id=?')->execute([$ok?1:0,$drop?1:0,$noticeId]);$db->prepare("UPDATE notices SET status='done' WHERE id=? AND sent+failed>=recipients")->execute([$noticeId]); }
            });
            $sent+=$ok?1:0;$failed+=$drop?1:0;
        }
        return ['sent'=>$sent,'failed'=>$failed,'claimed'=>count($jobs)];
    }

    public function weekly(string $week): array
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/D',$week,$m)) { throw new \InvalidArgumentException(); }
        $tz=new \DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ','Asia/Ho_Chi_Minh'));$start=(new \DateTimeImmutable('now',$tz))->setISODate((int)$m[1],(int)$m[2],1)->setTime(0,0);$end=$start->modify('+1 week');
        if ($start->format('o-\WW')!==$week || $end->setTime(8,0)->getTimestamp()>time()) { throw new \InvalidArgumentException(); }
        $email=Config::get('FLUXFILES_OPS_EMAIL');if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { return ['ok'=>false,'configured'=>false]; }
        $receipt=$this->store->transaction(function() use($week):array {
            $old=$this->store->state('weekly:'.$week)??[];
            if (in_array($old['status']??'',['sending','done'],true)) { return ['ok'=>true,'reused'=>true]; }
            if ((int)($old['next_at']??0)>time()) { return ['ok'=>false,'retry_at'=>$old['next_at']]; }
            $state=['status'=>'preparing','at'=>time(),'attempts'=>(int)($old['attempts']??0)+1,'next_at'=>time()+60];
            $this->store->state('weekly:'.$week,$state);return $state;
        });
        if (array_key_exists('ok',$receipt)) { return $receipt; }
        try {
        $stats=new Stats($this->store->pdo());$data=$stats->dashboard();$weekRows=$this->rows("SELECT currency,SUM(net) net FROM (
            SELECT o.currency,COALESCE(o.net_amount,0) net FROM orders o WHERE o.paid_at>=? AND o.paid_at<? AND o.gateway!='manual' AND NOT EXISTS(SELECT 1 FROM licenses l WHERE l.jti=o.jti AND (l.is_test=1 OR l.archived_at IS NOT NULL))
            UNION ALL SELECT o.currency,-(r.amount-r.tax_amount) net FROM order_refunds r JOIN orders o USING(order_id) WHERE r.at>=? AND r.at<? AND o.gateway!='manual' AND NOT EXISTS(SELECT 1 FROM licenses l WHERE l.jti=o.jti AND (l.is_test=1 OR l.archived_at IS NOT NULL))
        ) GROUP BY currency",[$start->getTimestamp(),$end->getTimestamp(),$start->getTimestamp(),$end->getTimestamp()]);
        $previous=$this->store->state('weekly_report')??[];$mrr=[];$lines=['FluxFiles weekly report '.$week];
        foreach ($weekRows as $row) { $lines[]='Week net: '.$row['net'].' '.$row['currency'].' minor units'; }
        foreach ($data['revenue']['currencies'] as $row) { $mrr[$row['currency']]=$row['mrr'];$lines[]='Month-to-date net: '.$row['net_month'].' '.$row['currency'].' minor units; MRR '.$row['mrr'].'; change '.($row['mrr']-($previous['mrr'][$row['currency']]??0)).' since previous report'; }
        $counts=(new Analytics($this->store))->stats('customers',['from'=>$start->format('Y-m-d'),'to'=>$end->modify('-1 day')->format('Y-m-d')]);$new=array_sum(array_column($counts['series'],'new'));
        $refunds=$this->rows('SELECT COUNT(*) n FROM order_refunds WHERE at>=? AND at<?',[$start->getTimestamp(),$end->getTimestamp()])[0]['n'];
        $open=$this->rows('SELECT kind,summary FROM ops_items WHERE resolved_at IS NULL ORDER BY id DESC LIMIT 20');
        $lines[]='New customers: '.$new.'; refunds: '.$refunds;$lines[]='Active installs: '.$data['installs']['active_30d'].'; never installed: '.$data['installs']['never_installed'];
        foreach ($data['revenue']['founding'] as $discount) { $lines[]=$discount['code'].': '.$discount['paid'].' / '.$discount['cap'].'; refunded '.$discount['refunded']; }
        foreach ($open as $item) { $lines[]='Needs attention: '.Mask::clean($item['summary']); }
        $lines[]='Webhook failures (7d): '.$data['webhook_errors_7d'];foreach ($data['mail']['periods'][30] as $mail) { $lines[]='Mail (30d): '.$mail['kind'].'; sent: '.$mail['sent'].'; accepted: '.$mail['accepted'].'; delivered: '.$mail['delivered'].'; bounced: '.$mail['bounced'].'; failed: '.$mail['failed']; }
        $backup=$this->store->backupStatus();$lines[]='Backup last OK: '.($backup['at']?gmdate('c',(int)$backup['at']):'missing');
        foreach ((new Analytics($this->store))->health()['latest'] as $health) { $lines[]='Health '.$health['target'].': '.($health['ok']?'OK':'DOWN').'; TLS days '.($health['tls_days']??'unknown'); }
        $body=Mask::clean(OpsText::text(implode("\n",$lines)));$mailer=(new AdminApi($this->store,'ops'))->mailer();$mailer->setTimeout(12);$mailer->setIdempotencyKey('weekly-'.$week);
        $this->store->state('weekly:'.$week,array_replace($receipt,['status'=>'sending']));
        $ok=$mailer->sendStructured('report',$email,OpsText::text('FluxFiles weekly report '.$week),$body);
        $delay=min(3600,60*(2**min(6,$receipt['attempts']-1)));
        $this->store->state('weekly:'.$week,['status'=>$ok?'done':'failed','at'=>time(),'attempts'=>$receipt['attempts'],'next_at'=>$ok?0:time()+$delay]);
        if ($ok) {
            $this->store->state('weekly_report',['week'=>$week,'at'=>time(),'mrr'=>$mrr]);
            $digest='Weekly '.$week."\n".implode('; ',array_map(static fn(array $r):string=>$r['currency'].' net '.$r['net'],$weekRows))."\nNew customers: ".$new.'; refunds: '.$refunds."\nActive installs: ".$data['installs']['active_30d']."\nNeeds attention: ".count($open);
            $this->store->enqueueAlert('report.weekly','info','report:'.$week,Mask::safe($digest,true));
        }
        return ['ok'=>$ok,'reused'=>false];
        } catch (\Throwable $e) {
            $delay=min(3600,60*(2**min(6,$receipt['attempts']-1)));
            $this->store->state('weekly:'.$week,['status'=>'failed','at'=>time(),'attempts'=>$receipt['attempts'],'next_at'=>time()+$delay]);
            error_log('weekly report preparation failed');
            return ['ok'=>false,'retry_at'=>time()+$delay];
        }
    }
}
