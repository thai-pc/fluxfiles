<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use FluxFiles\LicenseServer\Client\Ingest;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/PolarWebhook.php';
require_once __DIR__ . '/client/Ingest.php';
require_once __DIR__ . '/VersionRange.php';
require_once __DIR__ . '/InstallGroups.php';

trait OperationsStore
{
    public static function eventTime($value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function subscriptionEvent(array $body, string $webhookId): bool
    {
        $type = $body['type'] ?? '';
        $data = $body['data'] ?? null;
        if (
            !in_array(
                $type,
                [
                    'subscription.canceled',
                    'subscription.uncanceled',
                    'subscription.revoked',
                    'subscription.past_due',
                    'subscription.updated',
                    'subscription.created',
                ],
                true,
            ) ||
            !is_array($data)
        ) {
            return false;
        }
        $sid = PolarWebhook::cleanId(is_string($data['id'] ?? null) ? $data['id'] : '');
        if ($sid === '') {
            return false;
        }
        $known = $this->db->prepare(
            'SELECT 1 FROM licenses WHERE subscription_id=? UNION ALL SELECT 1 FROM orders WHERE subscription_id=? LIMIT 1',
        );
        $known->execute([$sid, $sid]);
        if ($known->fetchColumn() === false) {
            return false;
        }
        $known->closeCursor();
        $at = self::eventTime($data['modified_at'] ?? ($body['timestamp'] ?? null)) ?? time();
        if ($at > time() + 300) {
            $at = time();
        }
        return $this->transaction(function () use ($type, $data, $sid, $at, $webhookId): bool {
            $q = $this->db->prepare('SELECT * FROM subscriptions WHERE subscription_id=?');
            $q->execute([$sid]);
            $old = $q->fetch() ?: [];
            if (
                ((int) ($old['updated_at'] ?? 0) > $at && $type !== 'subscription.revoked') ||
                (($old['last_webhook_id'] ?? '') === $webhookId && $webhookId !== '')
            ) {
                return true;
            }
            $at = max($at, (int) ($old['updated_at'] ?? 0));
            $status = match ($type) {
                'subscription.revoked' => 'revoked',
                'subscription.past_due' => 'past_due',
                'subscription.uncanceled' => 'active',
                'subscription.canceled' => 'canceled',
                default => (string) ($data['status'] ?? ($old['status'] ?? 'active')),
            };
            if (
                !in_array(
                    $status,
                    [
                        'active',
                        'canceled',
                        'revoked',
                        'past_due',
                        'trialing',
                        'unpaid',
                        'incomplete',
                        'incomplete_expired',
                    ],
                    true,
                )
            ) {
                $status = 'active';
            }
            // A same-timestamp canceled/updated delivery cannot undo an immediate revoke.
            if (($old['status'] ?? '') === 'revoked' && $status !== 'revoked') {
                return true;
            }
            $flag = is_bool($data['cancel_at_period_end'] ?? null)
                ? $data['cancel_at_period_end']
                : (bool) ($old['cancel_at_period_end'] ?? false);
            $cancel =
                $type === 'subscription.uncanceled'
                    ? false
                    : $type === 'subscription.canceled' || $flag;
            $end =
                self::eventTime($data['current_period_end'] ?? null) ??
                ($old['current_period_end'] ?? null);
            $this->db
                ->prepare(
                    'INSERT INTO subscriptions(subscription_id,customer_id,product_id,status,cancel_at_period_end,current_period_end,canceled_at,revoked_at,updated_at,last_webhook_id) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(subscription_id) DO UPDATE SET customer_id=excluded.customer_id,product_id=excluded.product_id,status=excluded.status,cancel_at_period_end=excluded.cancel_at_period_end,current_period_end=excluded.current_period_end,canceled_at=excluded.canceled_at,revoked_at=excluded.revoked_at,updated_at=excluded.updated_at,last_webhook_id=excluded.last_webhook_id',
                )
                ->execute([
                    $sid,
                    PolarWebhook::cleanId(
                        (string) ($data['customer_id'] ?? ($old['customer_id'] ?? '')),
                    ),
                    PolarWebhook::cleanId(
                        (string) ($data['product_id'] ?? ($old['product_id'] ?? '')),
                    ),
                    $status,
                    (int) $cancel,
                    $end,
                    $cancel
                        ? self::eventTime($data['canceled_at'] ?? null) ??
                            ($old['canceled_at'] ?? $at)
                        : null,
                    $status === 'revoked'
                        ? self::eventTime($data['ended_at'] ?? null) ?? $at
                        : $old['revoked_at'] ?? null,
                    $at,
                    $webhookId,
                ]);
            if ($cancel || $status === 'revoked') {
                $this->resolveRefundSubscription($sid);
            }
            if ($status === 'revoked') {
                $pick = $this->db->prepare(
                    "SELECT expires FROM licenses WHERE subscription_id=? AND status='active' ORDER BY issued DESC,id DESC LIMIT 1",
                );
                $pick->execute([$sid]);
                $expires = $pick->fetchColumn();
                $periodEnd =
                    $end ??
                    (self::eventTime($data['ended_at'] ?? null) ??
                        ($expires === false ? null : (int) $expires));
                if (
                    $expires !== false &&
                    $periodEnd !== null &&
                    (int) $periodEnd - time() > 48 * 3600
                ) {
                    $this->setStatusForSubscription($sid, 'revoked');
                    $this->audit('subscription.revoke', $sid, 'ok', 'polar: more than 48h remain');
                }
            }
            return true;
        });
    }

    public function reminderSuppressed(array $row): bool
    {
        $sid = (string) ($row['subscription_id'] ?? '');
        if ($sid === '') {
            return false;
        }
        $q = $this->db->prepare(
            "SELECT 1 FROM subscriptions WHERE subscription_id=? AND (cancel_at_period_end=1 OR status IN ('canceled','revoked'))",
        );
        $q->execute([$sid]);
        return $q->fetchColumn() !== false;
    }

    public function opsItem(
        string $kind,
        string $summary,
        ?string $jti = null,
        ?string $order = null,
        ?int $event = null,
    ): void {
        $summary = mb_substr(
            Mask::clean(\FluxFiles\LicenseServer\Client\OpsText::text($summary)),
            0,
            500,
        );
        $q = $this->db->prepare(
            'INSERT OR IGNORE INTO ops_items(at,kind,ref_jti,ref_order,ref_event,summary) VALUES(?,?,?,?,?,?)',
        );
        $q->execute([time(), $kind, $jti, $order, $event, $summary]);
        if ($q->rowCount() > 0) {
            $id = (int) $this->db->lastInsertId();
            $this->enqueueAlert('ops.item', 'warn', 'item:' . $id, $summary);
        }
    }

    public function resolveOps(string $kind, string $jti): void
    {
        $this->db
            ->prepare(
                'UPDATE ops_items SET resolved_at=?,resolved_by=? WHERE kind=? AND ref_jti=? AND resolved_at IS NULL',
            )
            ->execute([time(), $this->actor, $kind, $jti]);
    }

    public function refundSubscriptionItem(array $facts): void
    {
        $sid = (string) ($facts['subscription_id'] ?? '');
        if ($sid === '') {
            return;
        }
        $q = $this->db->prepare(
            'SELECT status,cancel_at_period_end FROM subscriptions WHERE subscription_id=?',
        );
        $q->execute([$sid]);
        $sub = $q->fetch();
        if (
            $sub &&
            ($sub['cancel_at_period_end'] ||
                in_array($sub['status'], ['canceled', 'revoked'], true))
        ) {
            return;
        }
        $this->opsItem(
            'refund_sub_active',
            'Refunded order ' .
                Mask::id((string) $facts['order_id']) .
                '; cancel subscription ' .
                Mask::id($sid) .
                ' in Polar',
            $facts['jti'] ?? null,
            $facts['order_id'],
        );
    }

    private function resolveRefundSubscription(string $sid): void
    {
        $this->db
            ->prepare(
                "UPDATE ops_items SET resolved_at=?,resolved_by=? WHERE kind='refund_sub_active' AND resolved_at IS NULL AND ref_order IN (SELECT order_id FROM orders WHERE subscription_id=?)",
            )
            ->execute([time(), $this->actor, $sid]);
    }

    public function resolveEventItems(int $event): void
    {
        $this->db
            ->prepare(
                'UPDATE ops_items SET resolved_at=?,resolved_by=? WHERE ref_event=? AND resolved_at IS NULL',
            )
            ->execute([time(), $this->actor, $event]);
    }

    public function sweepOpsItems(): void
    {
        foreach ($this->undelivered(1000) as $row) {
            if (!$row['is_test'] && !$row['archived_at']) {
                $this->opsItem(
                    'mail_failed',
                    'Undelivered licence · ' . Mask::email($row['email']),
                    $row['jti'],
                );
            }
        }
        foreach (
            $this->db
                ->query(
                    "SELECT id,jti,order_id,outcome FROM webhook_events WHERE verify='ok' AND resolved_at IS NULL AND outcome IN ('unmapped_product','renewal_unmatched','mail_failed','error')",
                )
                ->fetchAll()
            as $event
        ) {
            $this->opsItem(
                $event['outcome'],
                'Webhook: ' . $event['outcome'],
                $event['jti'],
                $event['order_id'],
                (int) $event['id'],
            );
        }
        $installs = new InstallGroups($this->db);
        $never = [];
        foreach ($installs->groups as $group) {
            if ($installs->never($group)) {
                $r = $group['head'];
                $never[$r['jti']] = true;
                $this->opsItem(
                    'never_installed',
                    'Never installed · ' . Mask::email($r['email']),
                    $r['jti'],
                );
            }
        }
        foreach (
            $this->db
                ->query(
                    "SELECT id,ref_jti FROM ops_items WHERE kind='never_installed' AND resolved_at IS NULL",
                )
                ->fetchAll()
            as $item
        ) {
            if (!isset($never[$item['ref_jti']])) {
                $this->db
                    ->prepare("UPDATE ops_items SET resolved_at=?,resolved_by='system' WHERE id=?")
                    ->execute([time(), $item['id']]);
            }
        }
    }

    public function recordActivity(string $kind, string $result, string $jti = ''): void
    {
        if (
            !in_array($kind, ['claim', 'pro_download', 'refresh'], true) ||
            !preg_match('/^[a-z_]{1,40}$/D', $result)
        ) {
            return;
        }
        if (!preg_match('/^[a-f0-9]{24}$/D', $jti)) {
            $jti = '';
        }
        try {
            $this->db
                ->prepare(
                    'INSERT INTO activity_daily(day,jti,kind,result,n,last_at) VALUES(?,?,?,?,1,?) ON CONFLICT(day,jti,kind,result) DO UPDATE SET n=n+1,last_at=excluded.last_at',
                )
                ->execute([gmdate('Y-m-d'), $jti, $kind, $result, time()]);
        } catch (\Throwable $e) {
            /* Public outcomes must survive lossy instrumentation. */
        }
    }

    public function recordDownload(string $module, string $source, string $version = ''): void
    {
        if (
            !preg_match('/^[a-z0-9-]{1,64}$/D', $module) ||
            !in_array($source, ['pro_link_claim', 'pro_link_download'], true)
        ) {
            return;
        }
        if ($version !== '' && !VersionRange::validVersion($version)) {
            $version = '';
        }
        try {
            $this->db
                ->prepare(
                    'INSERT INTO downloads_daily(day,module,version,source,n) VALUES(?,?,?,?,1) ON CONFLICT(day,module,version,source) DO UPDATE SET n=n+1',
                )
                ->execute([gmdate('Y-m-d'), $module, $version, $source]);
        } catch (\Throwable $e) {
            /* The download response is independent of its counter. */
        }
    }

    public function ingest(string $type, string $day, array $rows): void
    {
        $rows = Ingest::rows($type, $day, $rows);
        $this->transaction(function () use ($type, $day, $rows): void {
            foreach ($rows as $r) {
                if ($type === 'public_counters') {
                    $this->db
                        ->prepare(
                            'INSERT INTO public_counters(day,source,subject,metric,value) VALUES(?,?,?,?,?) ON CONFLICT(day,source,subject,metric) DO UPDATE SET value=excluded.value',
                        )
                        ->execute([$day, $r['source'], $r['subject'], $r['metric'], $r['value']]);
                } elseif ($type === 'downloads_daily') {
                    $this->db
                        ->prepare(
                            "INSERT INTO downloads_daily(day,module,version,source,n) VALUES(?,?,?,'zip_log',?) ON CONFLICT(day,module,version,source) DO UPDATE SET n=excluded.n",
                        )
                        ->execute([$day, $r['module'], $r['version'], $r['n']]);
                } else {
                    $this->db
                        ->prepare(
                            'INSERT INTO demo_daily(day,sandboxes_created,sandbox_limit_hits,page_limit_hits,sandboxes_live,uploads_bytes) VALUES(?,?,?,?,?,?) ON CONFLICT(day) DO UPDATE SET sandboxes_created=excluded.sandboxes_created,sandbox_limit_hits=excluded.sandbox_limit_hits,page_limit_hits=excluded.page_limit_hits,sandboxes_live=excluded.sandboxes_live,uploads_bytes=excluded.uploads_bytes',
                        )
                        ->execute([
                            $day,
                            $r['sandboxes_created'],
                            $r['sandbox_limit_hits'],
                            $r['page_limit_hits'],
                            $r['sandboxes_live'],
                            $r['uploads_bytes'],
                        ]);
                }
            }
        });
    }
}
