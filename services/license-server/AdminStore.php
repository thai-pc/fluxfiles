<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;
use FluxFiles\LicenseServer\Client\OpsText;

require_once __DIR__ . '/client/Config.php';
require_once __DIR__ . '/client/Mask.php';
require_once __DIR__ . '/client/OpsText.php';

/** Administrative persistence shares the issuing connection and its transaction. */
trait AdminStore {
    public const EVENT_DAYS = 180;
    public const BODY_DAYS = 7;
    public const MAIL_DAYS = 365;
    public const AUDIT_DAYS = 730;
    public const PING_DAYS = 90;
    public const OPS_DAYS = 30;

    public function pdo(): PDO
    {
        return $this->db;
    }
    public function setActor(string $actor): void
    {
        $this->actor = mb_substr(Mask::clean($actor), 0, 120);
    }

    private function installHistoryTriggers(): void
    {
        $function=method_exists($this->db,'createFunction')?'createFunction':'sqliteCreateFunction';
        $this->db->{$function}('ff_sha256', static fn(string $v): string => hash('sha256', $v), 1);
        $this->db->{$function}('ff_kind', fn (): string => $this->historyKind, 0);
        $this->db->{$function}('ff_ref', fn (): ?string => $this->historyRef, 0);
        $this->db->{$function}('ff_actor', fn (): string => $this->actor, 0);
        // Connection-local triggers capture bulk supersedes and cascades as well as single
        // changes, atomically. They do not impose a PHP UDF on offline SQLite tooling.
        $this->db->exec(<<<'SQL'
CREATE TEMP TRIGGER IF NOT EXISTS ff_history_insert AFTER INSERT ON main.licenses BEGIN
 INSERT INTO license_history(jti,at,kind,to_status,actor) VALUES(NEW.jti,strftime('%s','now'),'issued',NEW.status,ff_actor());
END;
CREATE TEMP TRIGGER IF NOT EXISTS ff_history_status AFTER UPDATE OF status ON main.licenses WHEN OLD.status IS NOT NEW.status BEGIN
 INSERT INTO license_history(jti,at,kind,from_status,to_status,actor,ref)
 VALUES(NEW.jti,strftime('%s','now'),CASE WHEN ff_kind()!='' THEN ff_kind() WHEN NEW.status='superseded' THEN 'superseded' ELSE 'status' END,OLD.status,NEW.status,ff_actor(),ff_ref());
END;
CREATE TEMP TRIGGER IF NOT EXISTS ff_history_mail AFTER UPDATE OF mailed_at ON main.licenses WHEN OLD.mailed_at IS NOT NEW.mailed_at BEGIN
 INSERT INTO license_history(jti,at,kind,actor) VALUES(NEW.jti,strftime('%s','now'),'mailed',ff_actor());
END;
CREATE TEMP TRIGGER IF NOT EXISTS ff_history_flag AFTER UPDATE OF is_test,note,archived_at ON main.licenses
 WHEN OLD.is_test IS NOT NEW.is_test OR OLD.note IS NOT NEW.note OR OLD.archived_at IS NOT NEW.archived_at BEGIN
 INSERT INTO license_history(jti,at,kind,actor) VALUES(NEW.jti,strftime('%s','now'),CASE WHEN OLD.archived_at IS NOT NEW.archived_at THEN 'archived' ELSE 'flag' END,ff_actor());
END;
SQL);
    }

    public function audit(string $action, ?string $target = null, string $result = 'ok', ?string $reason = null, array $meta = []): void
    {
        $client = str_starts_with($this->actor, 'ui:') ? 'ui': ($this->actor === 'ops' ? 'ops': 'cli');
        $safe = static fn (?string $s): ?string => $s === null ? null: mb_substr(Mask::clean($s), 0, 1000);
        $this->db->prepare('INSERT INTO admin_audit(at,actor,client,action,target,result,reason,meta) VALUES(?,?,?,?,?,?,?,?)') ->execute([time(), $this->actor, $client, $safe($action), $safe($target), $safe($result), $safe($reason), $safe(json_encode($meta))]);
    }

    public function searchLicences(array $q): array
    {
        $where = ['1=1'];
        $params = [];
        foreach (['plan', 'status', 'gateway'] as $k) {
            if (($q[$k] ?? '') !== '') {
                $where[] = "l.$k = ?";
                $params[] = mb_substr((string) $q[$k], 0, 100);
            }
        }
        if (($q['test'] ?? 'hide') !== 'all') {
            $where[] = 'l.is_test = ?';
            $params[] = ($q['test'] ?? '') === 'only' ? 1: 0;
        }
        if (($q['archived'] ?? '') !== 'all') {
            $where[] = ($q['archived'] ?? '') === 'only' ? 'l.archived_at IS NOT NULL': 'l.archived_at IS NULL';
        }
        $search = mb_substr((string) ($q['q'] ?? ''), 0, 100);
        if ($search !== '') {
            Mask::safe($search);
            $where[] = "(l.email LIKE ? ESCAPE '\' OR l.jti LIKE ? ESCAPE '\' OR l.order_id=? OR l.checkout_id=? OR l.subscription_id=?)";
            $prefix = strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            array_push($params, $prefix, $prefix, $search, $search, $search);
        }
        if (($q['collapse'] ?? '1') !== '0') {
            $where[] = "(l.subscription_id IS NULL OR NOT EXISTS (SELECT 1 FROM licenses n WHERE n.subscription_id=l.subscription_id AND (n.issued>l.issued OR (n.issued=l.issued AND n.id>l.id))))";
        }
        if (($q['cursor'] ?? '') !== '') {
            $where[] = 'l.id < ?';
            $params[] = max(0, (int) $q['cursor']);
        }
        $limit = max(1, min(100, (int) ($q['limit'] ?? 50)));
        $sql = 'SELECT l.*, (SELECT COUNT(*) FROM licenses c WHERE c.subscription_id=l.subscription_id) AS cycles FROM licenses l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC LIMIT ?';
        $s = $this->db->prepare($sql);
        foreach ($params as $i => $v) {
            $s->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT: PDO::PARAM_STR);
        }
        $s->bindValue(count($params) + 1, $limit + 1, PDO::PARAM_INT);
        $s->execute();
        $rows = $s->fetchAll();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return ['rows' => array_map([Mask::class, 'row'], $rows), 'next_cursor' => $more ? (string) end($rows)['id']: null];
    }

    public function licenceDetail(string $jti): ?array
    {
        $r = $this->findByJti($jti);
        if ($r === null) {
            return null;
        }
        $sid = (string) ($r['subscription_id'] ?? '');
        $root = $r;
        $seen = [];
        while (!empty($root['renews_jti']) && !isset($seen[$root['jti']])) {
            $seen[$root['jti']] = true;
            $parent = $this->findByJti((string) $root['renews_jti']);
            if ($parent === null) {
                break;
            }
            $root = $parent;
        }
        if ($sid !== '') {
            $s = $this->db->prepare('SELECT * FROM licenses WHERE subscription_id=? ORDER BY issued,id');
            $s->execute([$sid]);
            $chain = $s->fetchAll();
        } else {
            $chain = array_merge([$root], $this->renewalDescendants((string) $root['jti']));
        }
        $timeline = [];
        foreach ([['license_history', 'at', 'jti=?', [$jti]], ['mail_log', 'at', 'jti=?', [$jti]], ['admin_audit', 'at', 'target=?', [$jti]], ['webhook_events', 'received_at', 'jti=? OR (order_id IS NOT NULL AND order_id=?) OR (subscription_id IS NOT NULL AND subscription_id=?)', [$jti, $r['order_id'], $sid]]] as [$table, $at, $cond, $params]) {
            $s = $this->db->prepare("SELECT * FROM $table WHERE $cond ORDER BY $at DESC,id DESC LIMIT 100");
            $s->execute($params);
            foreach ($s->fetchAll() as $item) {
                unset($item['body']);
                $timeline[] = ['at' => (int) $item[$at], 'source' => $table, 'item' => Mask::row($item)];
            }
        }
        $s = $this->db->prepare('SELECT day,SUM(n) AS n,MAX(last_at) AS at FROM install_pings WHERE jti=? GROUP BY day ORDER BY day DESC LIMIT 90');
        $s->execute([$jti]);
        foreach ($s->fetchAll() as $ping) {
            $timeline[] = ['at' => (int) $ping['at'], 'source' => 'install_pings', 'item' => $ping];
        }
        usort($timeline, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $s = $this->db->prepare("SELECT COUNT(DISTINCT NULLIF(net,'')) FROM install_pings WHERE jti=? AND last_at>=?");
        $s->execute([$jti, time() - 30 * 86400]);
        $nets = (int) $s->fetchColumn();
        $s = $this->db->prepare('SELECT module,version,core_version,MAX(last_at) AS last_at FROM install_pings WHERE jti=? GROUP BY module');
        $s->execute([$jti]);
        return ['licence' => Mask::row($r), 'chain' => array_map([Mask::class, 'row'], $chain), 'timeline' => $timeline, 'polar' => array_intersect_key($r, array_flip(['order_id', 'checkout_id', 'subscription_id', 'customer_id'])), 'install' => ['first_seen_at' => $r['first_seen_at'], 'last_seen_at' => $r['last_seen_at'], 'nets_30d' => $nets, 'versions' => $s->fetchAll()]];
    }

    public function cascadePreview(string $jti, string $status): array
    {
        $r = $this->findByJti($jti);
        if ($r === null) {
            return ['affected' => []];
        }
        $rows = [$r];
        $reactivates = null;
        if ($status !== 'active') {
            foreach ($this->renewalDescendants($jti) as $k) {
                if (in_array($k['status'], ['active', 'superseded'], true)) {
                    $rows[] = $k;
                }
            }
            if (!empty($r['renews_jti'])) {
                $parent = $this->findByJti((string) $r['renews_jti']);
                if ($parent !== null && $parent['status'] === 'superseded') {
                    $reactivates = Mask::row($parent);
                }
            }
        }
        return ['affected' => array_map([Mask::class, 'row'], $rows), 'reactivates' => $reactivates];
    }

    public function flags(string $jti, array $b): bool
    {
        if (isset($b['note']) && (!is_string($b['note']) || mb_strlen($b['note']) > 500)) {
            throw new \InvalidArgumentException('invalid_input');
        }
        if (isset($b['note'])) {
            Mask::safe($b['note']);
        }
        $r = $this->findByJti($jti);
        if ($r === null) {
            return false;
        }
        $this->db->prepare('UPDATE licenses SET is_test=?,note=? WHERE jti=?') ->execute([isset($b['is_test']) ? (int) (bool) $b['is_test']: $r['is_test'], $b['note'] ?? $r['note'], $jti]);
        return true;
    }

    public function archive(array $jtis): int
    {
        return $this->transaction(function () use ($jtis): int {
            if ($jtis === [] || count($jtis) > 100) {
                throw new \InvalidArgumentException('invalid_input');
            }
            foreach ($jtis as $jti) {
                $r = is_string($jti) ? $this->findByJti($jti): null;
                if ($r === null || !$r['is_test'] || $r['status'] === 'active') {
                    throw new \InvalidArgumentException('archive_not_allowed');
                }
            }
            $s = $this->db->prepare('UPDATE licenses SET archived_at=? WHERE jti=? AND archived_at IS NULL');
            $n = 0;
            foreach ($jtis as $jti) {
                $s->execute([time(), $jti]);
                $n += $s->rowCount();
            }
            return $n;
        });
    }

    public function recordInstallPing(string $jti, array $b, bool $active): void
    {
        $this->db->exec('PRAGMA busy_timeout=250');
        try {
            $module = is_string($b['module'] ?? null) && preg_match('/^[a-z0-9-]{1,64}$/D', $b['module']) ? $b['module']: '';
            $version = static fn ($v): ?string => is_string($v) && preg_match('/^[0-9]{1,4}(\.[0-9]{1,4}){1,3}([.+-][0-9A-Za-z.-]{1,20})?$/D', $v) ? $v: null;
            $net = '';
            $prefix = is_string($b['net'] ?? null) ? $b['net']: '';
            $parts = explode('/', $prefix);
            $addr = @inet_pton($parts[0]);
            if ($addr !== false && count($parts) === 2) {
                $canonical = strlen($addr) === 4 && $parts[1] === '24' ? substr($addr, 0, 3) . "\0": (strlen($addr) === 16 && $parts[1] === '64' ? substr($addr, 0, 8) . str_repeat("\0", 8): null);
                $pepper = Config::get('FLUXFILES_LICENSE_NET_PEPPER_FILE');
                $secret = $pepper !== '' ? @file_get_contents($pepper): false;
                if ($canonical !== null && $canonical === $addr && is_string($secret) && strlen($secret) >= 32) {
                    $net = substr(hash_hmac('sha256', inet_ntop($canonical) . '/' . $parts[1], $secret), 0, 16);
                }
            }
            $now = time();
            $this->transaction(function () use ($jti, $b, $active, $module, $version, $net, $now): void {
                // Unknown identifiers are never telemetry rows.
                if ($this->findByJti($jti) === null) {
                    return;
                }
                $this->db->prepare('INSERT INTO install_pings(day,jti,module,net,version,core_version,install,active,n,first_at,last_at) VALUES(?,?,?,?,?,?,?,?,1,?,?)
                    ON CONFLICT(day,jti,module,net) DO UPDATE SET n=n+1,last_at=excluded.last_at,version=excluded.version,core_version=excluded.core_version,active=excluded.active,install=MAX(install,excluded.install)') ->execute([gmdate('Y-m-d', $now), $jti, $module, $net, $version($b['current'] ?? null), $version($b['core_version'] ?? null), ($b['install'] ?? false) === true ? 1: 0, (int) $active, $now, $now]);
                $this->db->prepare('UPDATE licenses SET first_seen_at=COALESCE(first_seen_at,?),last_seen_at=? WHERE jti=? AND (last_seen_at IS NULL OR last_seen_at<?)') ->execute([$now, $now, $jti, strtotime(gmdate('Y-m-d', $now) . ' UTC')]);
            });
        } catch (\Throwable $e) {
            /* Pings are deliberately lossy under contention. */
        } finally {
            $this->db->exec('PRAGMA busy_timeout=5000');
        }
    }

    public function upsertOrder(array $facts): void
    {
        if (empty($facts['order_id'])) {
            return;
        }
        $this->transaction(function () use ($facts): void {
            $q=$this->db->prepare('SELECT * FROM orders WHERE order_id=?');$q->execute([$facts['order_id']]);$old=$q->fetch() ?: [];
            $facts['currency']??=$old['currency']??'unknown';
            $oldAmount=(int)($old['refunded_amount']??0);$oldTax=(int)($old['refunded_tax_amount']??0);
            $amount=max($oldAmount,(int)($facts['refunded_amount']??0));$tax=max($oldTax,(int)($facts['refunded_tax_amount']??0));
            $refundAt=$facts['refunded_at']??null;
            if ($refundAt!==null && (int)($facts['refunded_amount']??0)>0) {
                $this->db->prepare('INSERT OR IGNORE INTO order_refunds(order_id,total_amount,total_tax,amount,tax_amount,at) VALUES(?,?,?,?,?,?)')
                    ->execute([$facts['order_id'],(int)$facts['refunded_amount'],(int)($facts['refunded_tax_amount']??0),0,0,$refundAt]);
                // Cumulative snapshots may arrive out of order; each increment belongs
                // to the timestamp of the snapshot that first proves it occurred.
                $q=$this->db->prepare('SELECT * FROM order_refunds WHERE order_id=? ORDER BY total_amount,total_tax');$q->execute([$facts['order_id']]);
                $snapshots=$q->fetchAll();$beforeAmount=0;$beforeTax=0;
                foreach ($snapshots as $snapshot) {
                    $this->db->prepare('UPDATE order_refunds SET amount=?,tax_amount=? WHERE order_id=? AND total_amount=? AND total_tax=?')
                        ->execute([max(0,(int)$snapshot['total_amount']-$beforeAmount),max(0,(int)$snapshot['total_tax']-$beforeTax),$facts['order_id'],$snapshot['total_amount'],$snapshot['total_tax']]);
                    $beforeAmount=max($beforeAmount,(int)$snapshot['total_amount']);$beforeTax=max($beforeTax,(int)$snapshot['total_tax']);
                }
            }
            $facts['updated_at']=time();$cols=array_keys($facts);$updates=[];
            foreach ($cols as $c) {
                if (!preg_match('/^[a-z_]+$/D',$c)) { throw new \InvalidArgumentException('invalid_input'); }
                if ($c==='order_id') { continue; }
                $updates[]=match ($c) {
                    'refunded_at'=>"refunded_at=COALESCE(orders.refunded_at,excluded.refunded_at)",
                    'refunded_amount','refunded_tax_amount'=>"$c=MAX(COALESCE(orders.$c,0),COALESCE(excluded.$c,0))",
                    'status'=>"status=CASE WHEN orders.status='refunded' OR (orders.status='partially_refunded' AND excluded.status='paid') THEN orders.status ELSE excluded.status END",
                    default=>"$c=COALESCE(excluded.$c,orders.$c)",
                };
            }
            $sql='INSERT INTO orders('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).') ON CONFLICT(order_id) DO UPDATE SET '.implode(',',$updates);
            $this->db->prepare($sql)->execute(array_values($facts));
        });
    }

    public function expireWebhookBodies(?int $now=null): void
    {
        $this->db->prepare('UPDATE webhook_events SET body=NULL,body_expires_at=NULL WHERE body IS NOT NULL AND (body_expires_at<CAST(? AS INTEGER) OR resolved_at IS NOT NULL)')->execute([$now??time()]);
    }

    public function recordEvent(string $source, string $webhookId, string $verify, array $body, string $raw, string $outcome, int $http, ?string $jti = null): void
    {
        $now = time();
        $this->expireWebhookBodies($now);
        $trusted = $verify === 'ok';
        $d = $trusted && is_array($body['data'] ?? null) ? $body['data']: [];
        $facts = PolarWebhook::orderFacts($body) ?? [];
        $stored = strlen($raw)<=65536 && $trusted && in_array($outcome, ['unmapped_product', 'renewal_unmatched', 'error'], true) ? gzcompress($raw): null;
        $id = $trusted ? mb_substr(Mask::clean($webhookId), 0, 200): 'invalid:' . intdiv($now, 3600) . ':' . $verify;
        $values = [$source, $id, $now, $now, $trusted ? mb_substr(Mask::clean((string) ($body['type'] ?? '')), 0, 100): null, $verify, $outcome, $http, $facts['order_id'] ?? null, $trusted ? PolarWebhook::cleanId((string) ($d['checkout_id'] ?? '')): null, (str_starts_with((string)($body['type']??''),'subscription.') ? (PolarWebhook::cleanId((string)($d['id']??'')) ?: null) : ($facts['subscription_id']??null)), $facts['customer_id'] ?? null, $facts['product_id'] ?? null, $jti, in_array($outcome, ['error', 'unmapped_product', 'renewal_unmatched'], true) ? $outcome: null, $stored, $stored === null ? null: $now + self::BODY_DAYS * 86400];
        $this->db->prepare('INSERT INTO webhook_events(id,source,webhook_id,received_at,last_at,type,verify,outcome,http_status,order_id,checkout_id,subscription_id,customer_id,product_id,jti,error,body,body_expires_at)
            VALUES((SELECT id FROM webhook_events WHERE source=? AND webhook_id=? AND verify=? LIMIT 1),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(id) DO UPDATE SET attempts=attempts+1,last_at=excluded.last_at,outcome=excluded.outcome,http_status=excluded.http_status,
            jti=COALESCE(excluded.jti,jti),error=excluded.error,body=CASE WHEN resolved_at IS NULL THEN excluded.body ELSE NULL END,body_expires_at=excluded.body_expires_at') ->execute(array_merge([$source, $id, $verify], $values));
        if (!$trusted) {
            $s = $this->db->prepare('SELECT attempts FROM webhook_events WHERE source=? AND webhook_id=?');
            $s->execute([$source, $id]);
            if ((int) $s->fetchColumn() >= 10) {
                $this->enqueueAlert('webhook.bad_signature', 'warn', 'badsig:' . $source . ':' . intdiv($now, 3600), 'Webhook signature failures', 3600);
            }
        } elseif (in_array($outcome, ['unmapped_product', 'renewal_unmatched', 'error'], true)) {
            $this->enqueueAlert('webhook.failure', 'crit', 'hook:' . $outcome . ':' . ($facts['product_id'] ?? ''), 'Webhook: ' . $outcome . ' · ' . Mask::id((string) ($facts['product_id'] ?? '')), 3600);
        }
        if (random_int(1, 50) === 1) {
            $this->prune();
        }
    }

    public function logMail(string $kind, string $to, bool $ok, ?string $error, ?string $providerId, ?string $jti = null): void
    {
        $this->db->prepare('INSERT INTO mail_log(at,kind,jti,recipient,transport,ok,error,provider_id,status,status_at,actor) VALUES(?,?,?,?,?,?,?,?,?,?,?)') ->execute([time(), $kind, $jti, Mask::email($to), Config::get('FLUXFILES_MAIL_TRANSPORT', 'log'), (int) $ok, $error === null ? null: mb_substr(Mask::clean($error), 0, 300), $providerId, $ok ? 'accepted': 'failed', time(), $this->actor]);
        if (!$ok) {
            $this->opsItem('mail_failed', 'Mail failed · '.Mask::email($to), $jti);
            $this->enqueueAlert('mail.failed', 'warn', 'mailfail:' . Config::get('FLUXFILES_MAIL_TRANSPORT', 'log'), 'Mail failed · ' . Mask::email($to), 3600);
        }
    }

    public function enqueueAlert(string $kind, string $severity, string $dedupKey, string $text, int $windowSec = PHP_INT_MAX): void
    {
        $text=OpsText::text($text);
        Mask::safe($text, true);
        Mask::safe($dedupKey, true);
        $now = time();
        $this->db->prepare("INSERT INTO ops_outbox(id,created_at,channel,kind,severity,dedup_key,text,next_at)
            SELECT (SELECT id FROM ops_outbox WHERE dedup_key=? AND created_at>=? ORDER BY id DESC LIMIT 1),?,'telegram',?,?,?,?,?
            WHERE NOT EXISTS(SELECT 1 FROM ops_state WHERE key=?)
            ON CONFLICT(id) DO UPDATE SET suppressed=suppressed+1") ->execute([$dedupKey, max(0, $now - $windowSec), $now, mb_substr($kind, 0, 100), $severity, mb_substr($dedupKey, 0, 200), mb_substr($text, 0, 1000), $now, $windowSec === PHP_INT_MAX?'alert-dedup:' . hash('sha256', $dedupKey): 'no-receipt']);
    }

    public function claimOutbox(int $limit): array
    {
        return $this->transaction(function () use ($limit): array {
            $now = time();
            $s = $this->db->prepare("SELECT id,kind,severity,text,suppressed,attempts FROM ops_outbox WHERE channel='telegram' AND sent_at IS NULL AND dropped_at IS NULL AND next_at<=? AND COALESCE(lease_until,0)<=CAST(? AS INTEGER) ORDER BY id LIMIT ?");
            $s->bindValue(1, $now, PDO::PARAM_INT);
            $s->bindValue(2, $now, PDO::PARAM_INT);
            $s->bindValue(3, max(1, min(20, $limit)), PDO::PARAM_INT);
            $s->execute();
            $rows = $s->fetchAll();
            foreach ($rows as $i => $row) {
                try {
                    Mask::safe((string) $row['text'], true);
                } catch (\InvalidArgumentException $e) {
                    $this->db->prepare("UPDATE ops_outbox SET dropped_at=?,last_error='masking' WHERE id=?")->execute([$now, $row['id']]);
                    unset($rows[$i]);
                }
            }
            $rows = array_values($rows);
            if (count($rows) === max(1, min(20, $limit)) && $limit >= 2) {
                $count = $this->db->prepare("SELECT COUNT(*) FROM ops_outbox WHERE channel='telegram' AND sent_at IS NULL AND dropped_at IS NULL AND next_at<=? AND COALESCE(lease_until,0)<=CAST(? AS INTEGER)");
                $count->execute([$now, $now]);
                $pending = (int) $count->fetchColumn();
                if ($pending > $limit) {
                    $tail = array_pop($rows);
                    $tailId = (int) $tail['id'];
                    $pick = $this->db->prepare("SELECT COUNT(*) n,MAX(severity='crit') critical FROM ops_outbox WHERE id>=? AND channel='telegram' AND sent_at IS NULL AND dropped_at IS NULL AND next_at<=? AND COALESCE(lease_until,0)<=CAST(? AS INTEGER)");
                    $pick->execute([$tailId, $now, $now]);
                    $digest = $pick->fetch();
                    $this->db->prepare("UPDATE ops_outbox SET dropped_at=?,last_error='budget_digest' WHERE id>=? AND channel='telegram' AND sent_at IS NULL AND dropped_at IS NULL AND next_at<=? AND COALESCE(lease_until,0)<=CAST(? AS INTEGER)")->execute([$now, $tailId, $now, $now]);
                    $text = OpsText::text('Alert digest: ' . $digest['n'] . ' alerts; open the admin UI');
                    $this->db->prepare("INSERT INTO ops_outbox(created_at,channel,kind,severity,dedup_key,text,next_at) VALUES(?,'telegram','budget.digest',?,?,?,?)")->execute([$now, $digest['critical']?'crit': 'info', 'digest:' . bin2hex(random_bytes(8)), $text, $now]);
                    $rows[] = ['id' => (int) $this->db->lastInsertId(), 'kind' => 'budget.digest', 'severity' => $digest['critical']?'crit': 'info', 'text' => $text, 'suppressed' => 0, 'attempts' => 0];
                }
            }
            $u = $this->db->prepare('UPDATE ops_outbox SET lease_until=?,attempts=attempts+1 WHERE id=?');
            foreach ($rows as &$r) {
                $u->execute([$now + 120, $r['id']]);
                $r['attempts']++;
            }
            unset($r);
            return $rows;
        });
    }

    public function ackOutbox(int $id, bool $ok, string $error = '', ?int $retryAfter = null): void
    {
        $this->transaction(function () use ($id, $ok, $error, $retryAfter): void {
            $s = $this->db->prepare('SELECT attempts,lease_until FROM ops_outbox WHERE id=? AND sent_at IS NULL AND dropped_at IS NULL');
            $s->execute([$id]);
            $r = $s->fetch();
            if (!$r || empty($r['lease_until'])) {
                throw new \InvalidArgumentException('invalid_input');
            }
            $now = time();
            $drop = !$ok && ((int) $r['attempts'] >= 10 || in_array($error, ['masking', 'sandbox_filtered'], true));
            $delay = $retryAfter !== null ? max(1, min(86400, $retryAfter)): min(3600, 60 * (2 ** min(6, (int) $r['attempts'] - 1)));
            $this->db->prepare('UPDATE ops_outbox SET lease_until=NULL,sent_at=?,dropped_at=?,next_at=?,last_error=? WHERE id=?') ->execute([$ok ? $now: null, $drop ? $now: null, $now + $delay, $ok ? null: mb_substr(Mask::clean($error), 0, 300), $id]);
        });
    }

    public function state(string $key, ?array $value = null): ?array
    {
        if ($value !== null) {
            $this->db->prepare('INSERT INTO ops_state(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at')->execute([$key, json_encode($value), time()]);
            return $value;
        }
        $s = $this->db->prepare('SELECT value FROM ops_state WHERE key=?');
        $s->execute([$key]);
        $v = json_decode((string) $s->fetchColumn(), true);
        return is_array($v)?$v: null;
    }

    public function backupStatus(): array
    {
        $path = Config::get('FLUXFILES_LICENSE_BACKUP_STATUS', '/var/lib/fluxfiles-license' . (Config::sandbox() ? '-sbx' : '') . '/backup-status.json');
        $v = json_decode((string) @file_get_contents($path), true);
        if (!is_array($v)) {
            return ['ok' => false, 'at' => null];
        }
        return ['ok' => array_key_exists('ok',$v) ? $v['ok'] === true : ($v['integrity'] ?? '') === 'ok', 'at' => is_int($v['last_ok_at'] ?? $v['at'] ?? null) ? ($v['last_ok_at'] ?? $v['at']) : null, 'file' => isset($v['file']) ? basename((string)$v['file']) : null, 'bytes' => max(0,(int)($v['bytes'] ?? 0)), 'integrity' => ($v['integrity'] ?? '') === 'ok' ? 'ok' : null, 'kept' => max(0,(int)($v['kept'] ?? 0)), 'oldest_at' => is_int($v['oldest_at'] ?? null) ? $v['oldest_at'] : null, 'last_error' => isset($v['last_error']) ? mb_substr(Mask::clean((string)$v['last_error']),0,300) : null];
    }

    public function prune(): void
    {
        $now = time();
        $this->expireWebhookBodies($now);
        foreach (['webhook_events' => ['received_at', self::EVENT_DAYS], 'mail_log' => ['at', self::MAIL_DAYS], 'admin_audit' => ['at', self::AUDIT_DAYS], 'install_pings' => ['last_at', self::PING_DAYS], 'health_checks' => ['at', self::OPS_DAYS]] as $table => [$col, $days]) {
            $this->db->prepare("DELETE FROM $table WHERE $col<?")->execute([$now - $days * 86400]);
        }
        $this->transaction(function () use ($now): void {
            $q=$this->db->prepare("SELECT id,payload FROM ops_outbox WHERE channel='email' AND sent_at IS NULL AND dropped_at IS NULL AND created_at<CAST(? AS INTEGER) AND COALESCE(lease_until,0)<=CAST(? AS INTEGER)");$q->execute([$now-self::OPS_DAYS*86400,$now]);
            foreach ($q->fetchAll() as $job) {
                $u=$this->db->prepare("UPDATE ops_outbox SET dropped_at=?,lease_until=NULL,last_error='expired' WHERE id=? AND sent_at IS NULL AND dropped_at IS NULL");$u->execute([$now,$job['id']]);
                if ($u->rowCount()) {
                    $id=(int)(json_decode((string)$job['payload'],true)['notice_id']??0);
                    $this->db->prepare('UPDATE notices SET failed=failed+1 WHERE id=?')->execute([$id]);
                    $this->db->prepare("UPDATE notices SET status='done' WHERE id=? AND sent+failed>=recipients")->execute([$id]);
                }
            }
        });
        $this->db->prepare("UPDATE ops_outbox SET dropped_at=?,lease_until=NULL,last_error='expired' WHERE channel='telegram' AND sent_at IS NULL AND dropped_at IS NULL AND created_at<CAST(? AS INTEGER) AND COALESCE(lease_until,0)<=CAST(? AS INTEGER)")->execute([$now,$now-self::OPS_DAYS*86400,$now]);
        $this->db->prepare("INSERT OR IGNORE INTO ops_state(key,value,updated_at) SELECT 'alert-dedup:' || ff_sha256(dedup_key),NULL,? FROM ops_outbox WHERE COALESCE(sent_at,dropped_at)>0 AND COALESCE(sent_at,dropped_at)<CAST(? AS INTEGER)")->execute([$now, $now - self::OPS_DAYS * 86400]);
        $this->db->prepare("DELETE FROM ops_state WHERE (key LIKE 'alert-dedup:%' OR key LIKE 'notice:%' OR key LIKE 'weekly:%') AND updated_at<CAST(? AS INTEGER)")->execute([$now-self::AUDIT_DAYS*86400]);
        foreach (['activity_daily'=>90,'downloads_daily'=>730,'public_counters'=>730,'demo_daily'=>730] as $table=>$days) { $this->db->prepare("DELETE FROM $table WHERE day<?")->execute([gmdate('Y-m-d',$now-$days*86400)]); }
        $this->db->prepare('DELETE FROM ops_items WHERE resolved_at<?')->execute([$now-180*86400]);
        $this->db->prepare('DELETE FROM ops_outbox WHERE COALESCE(sent_at,dropped_at)>0 AND COALESCE(sent_at,dropped_at)<CAST(? AS INTEGER)')->execute([$now - self::OPS_DAYS * 86400]);
    }
}
