<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;
use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/Plans.php';
require_once __DIR__ . '/InstallGroups.php';
require_once __DIR__ . '/client/Config.php';
require_once __DIR__ . '/client/Mask.php';

final class Stats
{
    public function __construct(private PDO $db) {}

    private function rows(string $sql, array $params = []): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ', 'Asia/Ho_Chi_Minh'));
    }

    public function range(array $q): array
    {
        $now = new \DateTimeImmutable('now', $this->tz());
        $from =
            (string) ($q['from'] ??
                $now->modify('first day of this month')->modify('-11 months')->format('Y-m-d'));
        $to = (string) ($q['to'] ?? $now->format('Y-m-d'));
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $this->tz());
        if (
            !$a ||
            !$b ||
            $a->format('Y-m-d') !== $from ||
            $b->format('Y-m-d') !== $to ||
            $b < $a ||
            $b > $a->modify('+24 months')
        ) {
            throw new \InvalidArgumentException('invalid_input');
        }
        return [$a, $b->modify('+1 day')];
    }

    private function orderFilter(): string
    {
        return "o.gateway!='manual' AND NOT EXISTS(SELECT 1 FROM licenses l WHERE l.jti=o.jti AND (l.is_test=1 OR l.archived_at IS NOT NULL))";
    }

    private function accountingEvents(): array
    {
        $events = $this->rows('SELECT o.* FROM orders o WHERE ' . $this->orderFilter());
        foreach ($events as &$row) {
            $row['refunded_at'] = null;
            $row['refunded_amount'] = 0;
            $row['refunded_tax_amount'] = 0;
        }
        unset($row);
        $refunds = $this->rows(
            'SELECT o.*,r.at event_at,r.amount event_amount,r.tax_amount event_tax FROM order_refunds r JOIN orders o USING(order_id) WHERE ' .
                $this->orderFilter(),
        );
        foreach ($refunds as $row) {
            $row['paid_at'] = null;
            $row['refunded_at'] = $row['event_at'];
            $row['refunded_amount'] = $row['event_amount'];
            $row['refunded_tax_amount'] = $row['event_tax'];
            $events[] = $row;
        }
        return $events;
    }

    public function revenue(array $q = []): array
    {
        [$a, $b] = $this->range($q);
        $orders = $this->accountingEvents();
        $now = new \DateTimeImmutable('now', $this->tz());
        $start = $now->modify('first day of this month')->setTime(0, 0);
        $previous = $start->modify('-1 month');
        $by = [];
        $series = [];
        $refunds = [];
        $plans = [];
        $discounts = [];
        $planSet = [];
        foreach ($orders as $o) {
            $c = $o['currency'];
            if (($q['currency'] ?? '') !== '' && strtolower((string) $q['currency']) !== $c) {
                continue;
            }
            $planSet[$c][$o['plan'] ?: '(unmapped)'] = true;
            $by[$c] ??= [
                'currency' => $c,
                'net_month' => 0,
                'net_previous' => 0,
                'gross_month' => 0,
                'tax_month' => 0,
                'mrr' => 0,
                'arr' => 0,
                'refunds_30d' => 0,
                'refund_count_30d' => 0,
                'one_time_month' => 0,
            ];
            $paid = (int) $o['paid_at'];
            $refund = (int) $o['refunded_at'];
            $rn = (int) $o['refunded_amount'] - (int) $o['refunded_tax_amount'];
            foreach (
                [
                    [
                        'net_month',
                        $start->getTimestamp(),
                        $start->modify('+1 month')->getTimestamp(),
                    ],
                    ['net_previous', $previous->getTimestamp(), $start->getTimestamp()],
                ]
                as [$key, $lo, $hi]
            ) {
                if ($paid >= $lo && $paid < $hi) {
                    $by[$c][$key] += (int) $o['net_amount'];
                }
                if ($refund >= $lo && $refund < $hi) {
                    $by[$c][$key] -= $rn;
                }
            }
            if (
                $paid >= $start->getTimestamp() &&
                $paid < $start->modify('+1 month')->getTimestamp()
            ) {
                $by[$c]['gross_month'] += (int) $o['total_amount'];
                $by[$c]['tax_month'] += (int) $o['tax_amount'];
                if (empty($o['subscription_id'])) {
                    $by[$c]['one_time_month'] += (int) $o['net_amount'];
                }
            }
            if ($refund >= time() - 30 * 86400) {
                $by[$c]['refunds_30d'] += $rn;
                $by[$c]['refund_count_30d']++;
            }
            foreach (
                [[$paid, (int) $o['net_amount'], true], [$refund, -$rn, false]]
                as [$ts, $amount, $isPaid]
            ) {
                if ($ts < $a->getTimestamp() || $ts >= $b->getTimestamp()) {
                    continue;
                }
                $month = (new \DateTimeImmutable('@' . $ts))
                    ->setTimezone($this->tz())
                    ->format('Y-m');
                $key = $c . ':' . $month . ':' . ($o['plan'] ?: '(unmapped)');
                $series[$key] ??= [
                    'currency' => $c,
                    'month' => $month,
                    'plan' => $o['plan'] ?: '(unmapped)',
                    'net' => 0,
                    'refund_net' => 0,
                ];
                $series[$key]['net'] += $amount;
                if (!$isPaid) {
                    $series[$key]['refund_net'] -= $amount;
                }
                $pk = $c . ':' . ($o['plan'] ?: '(unmapped)');
                $plans[$pk] ??= [
                    'currency' => $c,
                    'plan' => $o['plan'] ?: '(unmapped)',
                    'net' => 0,
                ];
                $plans[$pk]['net'] += $amount;
                if ($isPaid && $o['discount_code']) {
                    $dk = $c . ':' . $o['discount_code'];
                    $discounts[$dk] ??= [
                        'currency' => $c,
                        'code' => $o['discount_code'],
                        'amount' => 0,
                    ];
                    $discounts[$dk]['amount'] += (int) $o['discount_amount'];
                }
                if (!$isPaid) {
                    $refunds[] = array_intersect_key(
                        $o,
                        array_flip(['order_id', 'currency', 'plan', 'refunded_at']),
                    ) + ['refund_net' => -$amount];
                }
            }
        }
        // The current MRR proxy follows the newest licence, then that subscription's
        // latest non-refunded order. Historical points are explicitly estimates.
        $historical = [];
        for (
            $month = $a->modify('first day of this month');
            $month < $b;
            $month = $month->modify('+1 month')
        ) {
            $asOf = min(time(), $month->modify('first day of next month')->getTimestamp() - 1);
            $mrr = $this->mrrAt($asOf, $q);
            foreach ($mrr as $c => $v) {
                $historical[] = ['month' => $month->format('Y-m'), 'currency' => $c, 'mrr' => $v];
            }
            if ($month->format('Y-m') === $now->format('Y-m')) {
                foreach ($mrr as $c => $v) {
                    if (isset($by[$c])) {
                        $by[$c]['mrr'] = $v;
                        $by[$c]['arr'] = $v * 12;
                    }
                }
            }
        }
        foreach ($this->mrrAt(time(), $q) as $currency => $amount) {
            if (isset($by[$currency])) {
                $by[$currency]['mrr'] = $amount;
                $by[$currency]['arr'] = $amount * 12;
            }
        }
        foreach ($by as $c => $metrics) {
            foreach (array_keys($planSet[$c]) as $plan) {
                for (
                    $month = $a->modify('first day of this month');
                    $month < $b;
                    $month = $month->modify('+1 month')
                ) {
                    $label = $month->format('Y-m');
                    $key = $c . ':' . $label . ':' . $plan;
                    $series[$key] ??= [
                        'currency' => $c,
                        'month' => $label,
                        'plan' => $plan,
                        'net' => 0,
                        'refund_net' => 0,
                    ];
                }
            }
        }
        $points = [];
        foreach ($historical as $point) {
            $points[$point['currency'] . ':' . $point['month']] = $point;
        }
        foreach ($by as $c => $metrics) {
            for (
                $month = $a->modify('first day of this month');
                $month < $b;
                $month = $month->modify('+1 month')
            ) {
                $label = $month->format('Y-m');
                $points[$c . ':' . $label] ??= ['month' => $label, 'currency' => $c, 'mrr' => 0];
            }
        }
        $historical = array_values($points);
        usort(
            $historical,
            static fn(array $x, array $y): int => [$x['month'], $x['currency']] <=> [
                $y['month'],
                $y['currency'],
            ],
        );
        usort(
            $series,
            static fn(array $x, array $y): int => [$x['month'], $x['currency'], $x['plan']] <=> [
                $y['month'],
                $y['currency'],
                $y['plan'],
            ],
        );
        $monthlyRefunds = [];
        foreach ($series as $point) {
            $key = $point['currency'] . ':' . $point['month'];
            $monthlyRefunds[$key] ??= [
                'currency' => $point['currency'],
                'month' => $point['month'],
                'net' => 0,
                'refund_net' => 0,
                'count' => 0,
            ];
            $monthlyRefunds[$key]['net'] += $point['net'];
            $monthlyRefunds[$key]['refund_net'] += $point['refund_net'];
        }
        foreach ($refunds as $refund) {
            $month = (new \DateTimeImmutable('@' . (int) $refund['refunded_at']))
                ->setTimezone($this->tz())
                ->format('Y-m');
            $key = $refund['currency'] . ':' . $month;
            if (isset($monthlyRefunds[$key])) {
                $monthlyRefunds[$key]['count']++;
            }
        }
        foreach ($monthlyRefunds as &$point) {
            $point['refund_rate'] =
                $point['net'] > 0 ? round(($point['refund_net'] / $point['net']) * 100, 2) : null;
        }
        unset($point);
        return [
            'currencies' => array_values($by),
            'series' => array_values($series),
            'mrr_series' => $historical,
            'plans' => array_values($plans),
            'discounts' => array_values($discounts),
            'refunds' => $refunds,
            'refunds_monthly' => array_values($monthlyRefunds),
            'founding' => $this->founding(),
            'estimate' => true,
            'past_due' => $this->rows(
                "SELECT subscription_id,current_period_end FROM subscriptions WHERE status='past_due'",
            ),
        ];
    }

    private function mrrAt(int $asOf, array $q): array
    {
        $mrr = [];
        $heads = $this->rows(
            'SELECT l.* FROM licenses l WHERE l.subscription_id IS NOT NULL AND l.issued<=? AND l.is_test=0 AND l.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM licenses n WHERE n.subscription_id=l.subscription_id AND n.issued<=? AND (n.issued>l.issued OR (n.issued=l.issued AND n.id>l.id)))',
            [$asOf, $asOf],
        );
        foreach ($heads as $l) {
            if (
                $l['status'] !== 'active' &&
                !($asOf < time() - 60 && $l['status'] === 'superseded')
            ) {
                continue;
            }
            if ((int) $l['expires'] <= $asOf) {
                continue;
            }
            $rows = $this->rows(
                "SELECT o.* FROM orders o WHERE o.subscription_id=? AND o.paid_at<=? AND (o.status!='refunded' OR o.refunded_at>?) AND " .
                    $this->orderFilter() .
                    ' ORDER BY o.paid_at DESC,o.order_id DESC LIMIT 1',
                [$l['subscription_id'], $asOf, $asOf],
            );
            if ($rows === []) {
                continue;
            }
            $subscription =
                $this->rows(
                    'SELECT status,cancel_at_period_end,revoked_at FROM subscriptions WHERE subscription_id=?',
                    [$l['subscription_id']],
                )[0] ?? null;
            if (
                $subscription &&
                $asOf >= time() - 60 &&
                ($subscription['cancel_at_period_end'] || $subscription['status'] !== 'active')
            ) {
                continue;
            }
            $o = $rows[0];
            $c = $o['currency'];
            if (($q['currency'] ?? '') !== '' && strtolower((string) $q['currency']) !== $c) {
                continue;
            }
            $ttl = Plans::get((string) $l['plan'])['ttlDays'] ?? 0;
            if (!$ttl) {
                continue;
            }
            $mrr[$c] =
                ($mrr[$c] ?? 0) + (int) round((int) $o['net_amount'] / ($ttl >= 365 ? 12 : 1));
        }
        return $mrr;
    }

    public function founding(): array
    {
        $out = [];
        foreach (explode(',', Config::get('FLUXFILES_ADMIN_TRACKED_DISCOUNTS')) as $pair) {
            if (!preg_match('/^([A-Za-z0-9_-]{1,100}):([1-9][0-9]{0,6})$/D', trim($pair), $m)) {
                continue;
            }
            $code = $m[1];
            $cap = (int) $m[2];
            $rows = $this->rows(
                'SELECT o.* FROM orders o WHERE ' .
                    $this->orderFilter() .
                    ' AND (lower(o.discount_code)=lower(?) OR o.discount_id IN (SELECT discount_id FROM orders WHERE lower(discount_code)=lower(?) AND discount_id IS NOT NULL)) ORDER BY o.paid_at,o.order_id',
                [$code, $code],
            );
            $paid = 0;
            $refunded = 0;
            $net = [];
            $last = null;
            $redemptions = [];
            foreach ($rows as $o) {
                $net[$o['currency']] =
                    ($net[$o['currency']] ?? 0) +
                    (int) $o['net_amount'] -
                    (int) $o['refunded_amount'] +
                    (int) $o['refunded_tax_amount'];
                $last = max((int) $last, (int) $o['paid_at']);
                if (
                    !in_array(
                        $o['billing_reason'],
                        ['purchase', 'subscription_create', null, ''],
                        true,
                    )
                ) {
                    continue;
                }
                $identity = $o['subscription_id'] ?: 'order:' . $o['order_id'];
                if (isset($redemptions[$identity])) {
                    continue;
                }
                $redemptions[$identity] = true;
                if ($o['status'] === 'refunded') {
                    $refunded++;
                } else {
                    $paid++;
                }
            }
            $out[] = [
                'code' => $code,
                'cap' => $cap,
                'paid' => $paid,
                'refunded' => $refunded,
                'used' => $paid + $refunded,
                'net' => $net,
                'last_at' => $last,
            ];
        }
        return $out;
    }

    public function installs(array $q = []): array
    {
        [$a, $b] = $this->range($q);
        $now = time();
        $installs = new InstallGroups($this->db);
        $active = 0;
        $never = [];
        $quiet = [];
        $daily = [];
        foreach ($installs->groups as $key => $group) {
            $row = $group['head'];
            $row['first_seen_at'] = $group['first_seen_at'];
            $row['last_seen_at'] = $group['last_seen_at'];
            if ($group['last_seen_at'] !== null && $group['last_seen_at'] >= $now - 30 * 86400) {
                $active++;
            }
            if ($installs->never($group, $now)) {
                $never[] = $row;
            } elseif (
                $group['first_seen_at'] !== null &&
                (int) $group['last_seen_at'] < $now - 30 * 86400
            ) {
                $quiet[] = $row;
            }
        }
        // Group timestamps in the accounting timezone; UTC dates can straddle local midnight.
        foreach ($installs->pings($a->getTimestamp()) as $ping) {
            $at = (int) $ping['last_at'];
            if (!$ping['active'] || $at >= $b->getTimestamp()) {
                continue;
            }
            $day = (new \DateTimeImmutable('@' . $at))->setTimezone($this->tz())->format('Y-m-d');
            $daily[$day][$ping['group']] = true;
        }
        ksort($daily);
        $series = [];
        foreach ($daily as $day => $groups) {
            $series[] = ['day' => $day, 'active' => count($groups)];
        }
        return [
            'active_30d' => $active,
            'never_installed' => count($never),
            'gone_quiet' => count($quiet),
            'series' => $series,
            'never' => array_map([Mask::class, 'row'], array_slice($never, 0, 100)),
            'quiet' => array_map([Mask::class, 'row'], array_slice($quiet, 0, 100)),
        ];
    }

    public function mail(array $q = []): array
    {
        $out = [];
        foreach ([30, 90] as $days) {
            $out[$days] = $this->rows(
                "SELECT m.kind,COUNT(*) sent,SUM(m.ok) accepted,SUM(m.status='delivered') delivered,SUM(m.status='bounced') bounced,SUM(m.status='complained') complained,SUM(m.ok=0) failed FROM mail_log m WHERE m.at>=? AND NOT EXISTS(SELECT 1 FROM licenses l WHERE l.jti=m.jti AND (l.is_test=1 OR l.archived_at IS NOT NULL OR l.gateway='manual')) GROUP BY m.kind",
                [time() - $days * 86400],
            );
            foreach ($out[$days] as &$r) {
                $r['delivery_rate'] = $r['accepted']
                    ? (int) $r['delivered'] / (int) $r['accepted']
                    : null;
                $r['bounce_rate'] = $r['accepted']
                    ? (int) $r['bounced'] / (int) $r['accepted']
                    : null;
            }
            unset($r);
        }
        return [
            'periods' => $out,
            'bounces' => $this->rows(
                "SELECT at,kind,jti,recipient,status FROM mail_log WHERE status IN ('bounced','complained') ORDER BY id DESC LIMIT 50",
            ),
            'webhook_enabled' => Config::secret('FLUXFILES_RESEND_WEBHOOK_SECRET') !== '',
        ];
    }

    private function attentionTotal(): int
    {
        $sql = "SELECT (SELECT COUNT(*) FROM ops_items WHERE resolved_at IS NULL)
          +(SELECT COUNT(*) FROM webhook_events w WHERE w.resolved_at IS NULL AND (w.http_status>=400 OR w.outcome IN ('renewal_unmatched','mail_failed','error')) AND NOT EXISTS(SELECT 1 FROM ops_items i WHERE i.ref_event=w.id AND i.resolved_at IS NULL))
          +(SELECT COUNT(*) FROM licenses l WHERE l.gateway!='manual' AND l.mailed_at IS NULL AND l.status='active' AND l.is_test=0 AND l.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM ops_items i WHERE i.ref_jti=l.jti AND i.kind='mail_failed' AND i.resolved_at IS NULL))";
        return (int) $this->db->query($sql)->fetchColumn();
    }

    public function dashboard(): array
    {
        $now = time();
        $needs = $this->rows(
            "SELECT id,type,outcome,product_id,attempts FROM webhook_events WHERE resolved_at IS NULL AND (http_status>=400 OR outcome IN ('renewal_unmatched','mail_failed','error')) ORDER BY id DESC LIMIT 20",
        );
        return [
            'revenue' => $this->revenue(),
            'installs' => $this->installs(),
            'mail' => $this->mail(),
            'ops_items' => $this->rows(
                'SELECT * FROM ops_items WHERE resolved_at IS NULL ORDER BY at DESC,id DESC LIMIT 20',
            ),
            'past_due' => $this->rows(
                "SELECT subscription_id,current_period_end FROM subscriptions WHERE status='past_due'",
            ),
            'attention_total' => $this->attentionTotal(),
            'needs_attention' => $needs,
            'undelivered_rows' => $this->rows(
                "SELECT jti,email,plan,created_at FROM licenses WHERE gateway!='manual' AND mailed_at IS NULL AND status='active' AND is_test=0 AND archived_at IS NULL ORDER BY id LIMIT 20",
            ),
            'needs_attention_total' => (int) $this->rows(
                "SELECT COUNT(*) n FROM webhook_events WHERE resolved_at IS NULL AND (http_status>=400 OR outcome IN ('renewal_unmatched','mail_failed','error'))",
            )[0]['n'],
            'undelivered' => (int) $this->rows(
                "SELECT COUNT(*) n FROM licenses WHERE gateway!='manual' AND mailed_at IS NULL AND status='active' AND is_test=0 AND archived_at IS NULL",
            )[0]['n'],
            'plans' => $this->rows(
                "SELECT plan,COUNT(*) n FROM licenses WHERE status='active' AND is_test=0 AND archived_at IS NULL GROUP BY plan",
            ),
            'new_7d' => $this->rows(
                'SELECT billing_reason,COUNT(*) n FROM orders o WHERE ' .
                    $this->orderFilter() .
                    ' AND paid_at>=? GROUP BY billing_reason',
                [$now - 7 * 86400],
            ),
            'expiring' => $this->rows(
                "SELECT enforcement,COUNT(*) n FROM licenses WHERE status='active' AND is_test=0 AND archived_at IS NULL AND expires>=? AND expires<=? GROUP BY enforcement",
                [$now, $now + 30 * 86400],
            ),
            'webhook_errors_7d' => (int) $this->rows(
                'SELECT COUNT(*) n FROM webhook_events WHERE received_at>=? AND http_status>=400',
                [$now - 7 * 86400],
            )[0]['n'],
            'telegram' => $this->rows(
                "SELECT MAX(sent_at) last_sent,SUM(sent_at IS NULL AND dropped_at IS NULL) queue_depth FROM ops_outbox WHERE channel='telegram'",
            )[0],
            'instance' => Config::get('FLUXFILES_LICENSE_INSTANCE', 'production'),
        ];
    }
}
