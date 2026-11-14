<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;
use FluxFiles\LicenseServer\Client\OpsText;

require_once __DIR__ . '/PolarWebhook.php';
require_once __DIR__ . '/AdminApi.php';

final class Webhooks
{
    public function __construct(private LicenseStore $store, private \Closure $issuer) {}

    public function polar(string $raw, array $server): array
    {
        $headers = PolarWebhook::headersFromServer($server);
        [$ok, $why] = PolarWebhook::verify(
            $raw,
            Config::secret('FLUXFILES_POLAR_WEBHOOK_SECRET'),
            $headers,
        );
        if (!$ok) {
            $reason = match ($why) {
                'no secret configured' => 'no_secret',
                'missing signature headers' => 'missing_headers',
                'timestamp outside tolerance' => 'stale',
                default => 'bad_signature',
            };
            $this->record('polar', '', $reason, [], '', 'ignored', 401);
            return [401, ['error' => 'bad signature']];
        }
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];
        $id = PolarWebhook::cleanId($headers['webhook-id']);
        $this->store->setActor('polar:' . $id);
        // Accounting and observability must never break issuance or change its status.
        $this->facts($body);
        try {
            [$code, $out, $outcome] = $this->handlePolarEvent($body, 'polar:' . $id);
        } catch (\Throwable $e) {
            $this->record('polar', $id, 'ok', $body, $raw, 'error', 500);
            throw $e;
        }
        $this->record('polar', $id, 'ok', $body, $raw, $outcome, $code, $out['jti'] ?? null);
        $this->facts($body, $out['jti'] ?? null);
        return [$code, $out];
    }

    /** The existing issue/refund decisions, independent of recording and request transport. */
    public function handlePolarEvent(array $body, string $source): array
    {
        $s = $this->store;
        $s->setActor($source);
        if (str_starts_with((string) ($body['type'] ?? ''), 'subscription.')) {
            try {
                $updated = $s->subscriptionEvent($body, substr($source, strpos($source, ':') + 1));
            } catch (\Throwable $e) {
                error_log('subscription event needs operator replay');
                return [200, ['subscription_updated' => false, 'needs_review' => true], 'error'];
            }
            return [
                200,
                ['subscription_updated' => $updated],
                $updated ? 'sub_updated' : 'ignored',
            ];
        }
        $refunded = PolarWebhook::refundedOrderId($body);
        if ($refunded !== null) {
            $r = $s->findByOrder('polar', $refunded);
            if ($r !== null && in_array($r['status'], ['active', 'superseded'], true)) {
                $s->setStatusCascade((string) $r['jti'], 'refunded');
                $this->cascadeNotice($s, 'refunded licence ' . $r['jti']);
            }
            try {
                $facts = PolarWebhook::orderFacts($body);
                if ($facts) {
                    $s->refundSubscriptionItem($facts + ['jti' => $r['jti'] ?? null]);
                }
            } catch (\Throwable $e) {
                error_log('refund item recording failed');
            }
            if ($r === null && $this->isIgnoredOrder($body, $refunded)) {
                return [200, ['refunded' => false, 'ignored' => 'product'], 'ignored_product'];
            }
            $this->alert(
                'order.refund',
                'warn',
                'refund:' . $refunded,
                OpsText::text('Order refunded · {order}', ['order' => Mask::id($refunded)]),
            );
            return [200, ['refunded' => $r !== null], 'refunded'];
        }
        $plans = json_decode(Config::get('FLUXFILES_POLAR_PLAN_MAP', '{}'), true) ?: [];
        $order = PolarWebhook::extract($body, $plans);
        if ($order === null) {
            return [200, ['ignored' => (string) ($body['type'] ?? '')], 'ignored'];
        }
        $ignored = PolarWebhook::ignoredProducts(Config::get('FLUXFILES_POLAR_IGNORE_PRODUCTS', ''));
        if ($ignored !== [] && in_array($order['product_id'], $ignored, true)) {
            if (($order['plan'] ?? '') !== '') {
                // Config error: the plan map wins and the order issues a licence as usual.
                error_log(
                    'polar webhook: product ' .
                        Mask::clean($order['product_id']) .
                        ' is in both FLUXFILES_POLAR_PLAN_MAP and FLUXFILES_POLAR_IGNORE_PRODUCTS; the plan map wins',
                );
            } else {
                return [
                    200,
                    ['ignored' => 'product', 'order_id' => $order['order_id']],
                    'ignored_product',
                ];
            }
        }
        if (($order['plan'] ?? '') === '' || Plans::get($order['plan']) === null) {
            $pid = (string) ($body['data']['product_id'] ?? '');
            error_log(
                'polar webhook: unmapped product ' .
                    Mask::clean($pid) .
                    ' order ' .
                    Mask::clean((string) $order['order_id']) .
                    ' ' .
                    Mask::email((string) $order['email']),
            );
            return [
                503,
                ['error' => 'plan not configured for this product', 'product_id' => $pid],
                'unmapped_product',
            ];
        }
        $api = new AdminApi($s, 'admin');
        try {
            $res = ($this->issuer)()->issue($order + ['gateway' => 'polar']);
        } catch (RenewalWithoutLicenceException $e) {
            $api->mailer()->sendRenewalUnmatched(
                (string) $order['email'],
                (string) $order['plan'],
                (string) $order['order_id'],
            );
            return [
                200,
                ['ignored' => 'renewal_without_licence', 'order_id' => $order['order_id']],
                'renewal_unmatched',
            ];
        }
        $jti = (string) $res['record']['jti'];
        if ($res['record']['status'] !== 'active') {
            if (!$res['reused']) {
                $api->mailer()->sendOpsNotice(
                    'FluxFiles: payment on a revoked subscription',
                    'Order ' .
                        $order['order_id'] .
                        ' on subscription ' .
                        $order['subscription_id'] .
                        ' is recorded as ' .
                        $res['record']['status'] .
                        '. Cancel billing in Polar or restore the subscription.',
                );
            }
            return [
                200,
                ['issued' => !$res['reused'], 'jti' => $jti, 'status' => $res['record']['status']],
                $res['reused'] ? 'reused' : 'issued',
            ];
        }
        $mailed = true;
        if (empty($res['record']['mailed_at'])) {
            $mailed = $api->mailer($jti)->sendLicense($res['record']);
            if ($mailed) {
                $s->markMailed($jti);
            }
        }
        if (!$res['reused']) {
            $this->alert(
                'order.new',
                'info',
                'order:' . $order['order_id'],
                OpsText::text('New order · {plan} · {email} · {order}', [
                    'plan' => Mask::clean($order['plan']),
                    'email' => Mask::email($order['email']),
                    'order' => Mask::id($order['order_id']),
                ]),
            );
        }
        return [
            200,
            ['issued' => !$res['reused'], 'jti' => $jti],
            $mailed ? ($res['reused'] ? 'reused' : 'issued') : 'mail_failed',
        ];
    }

    public function resend(string $raw, array $server): array
    {
        $headers = [
            'webhook-id' => (string) ($server['HTTP_SVIX_ID'] ?? ''),
            'webhook-timestamp' => (string) ($server['HTTP_SVIX_TIMESTAMP'] ?? ''),
            'webhook-signature' => (string) ($server['HTTP_SVIX_SIGNATURE'] ?? ''),
        ];
        [$ok, $why] = PolarWebhook::verify(
            $raw,
            Config::secret('FLUXFILES_RESEND_WEBHOOK_SECRET'),
            $headers,
        );
        if (!$ok) {
            $this->record('resend', '', 'bad_signature', [], '', 'ignored', 401);
            return [401, ['error' => 'bad signature']];
        }
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];
        $statuses = [
            'email.delivered' => 'delivered',
            'email.bounced' => 'bounced',
            'email.complained' => 'complained',
            'email.delivery_delayed' => 'delayed',
        ];
        $status = $statuses[$body['type'] ?? ''] ?? null;
        $pid = $body['data']['email_id'] ?? null;
        $this->store->setActor('resend:' . PolarWebhook::cleanId($headers['webhook-id']));
        if ($status !== null && is_string($pid)) {
            $db = $this->store->pdo();
            $q = $db->prepare(
                'SELECT jti,kind,recipient,status,status_at FROM mail_log WHERE provider_id=?',
            );
            $q->execute([$pid]);
            $row = $q->fetch();
            if ($row) {
                $at = strtotime((string) ($body['created_at'] ?? '')) ?: time();
                // Do not let delayed retries overwrite a bounce/complaint with an older delivery.
                $db->prepare(
                    "UPDATE mail_log SET status=?,status_at=? WHERE provider_id=? AND (status_at IS NULL OR status_at<=?) AND (status NOT IN ('bounced','complained') OR ? IN ('bounced','complained'))",
                )->execute([$status, $at, $pid, $at, $status]);
                if ($status === 'bounced' && $row['kind'] === 'licence') {
                    $this->store->opsItem(
                        'mail_failed',
                        OpsText::text('Licence email bounced · {email}', [
                            'email' => $row['recipient'],
                        ]),
                        $row['jti'],
                    );
                    $this->alert(
                        'mail.bounced',
                        'warn',
                        'bounce:' . $pid,
                        OpsText::text('Licence email bounced · {email}', [
                            'email' => $row['recipient'],
                        ]),
                    );
                }
            }
        }
        $this->record('resend', $headers['webhook-id'], 'ok', $body, '', 'mail_event', 200);
        return [200, ['ok' => true]];
    }

    private function record(
        string $source,
        string $id,
        string $verify,
        array $body,
        string $raw,
        string $outcome,
        int $http,
        ?string $jti = null,
    ): void {
        try {
            $this->store->recordEvent($source, $id, $verify, $body, $raw, $outcome, $http, $jti);
            if ($verify === 'ok') {
                $q = $this->store
                    ->pdo()
                    ->prepare(
                        'SELECT id,order_id FROM webhook_events WHERE source=? AND webhook_id=?',
                    );
                $q->execute([$source, $id]);
                $event = $q->fetch();
                if (
                    $event &&
                    in_array(
                        $outcome,
                        ['unmapped_product', 'renewal_unmatched', 'mail_failed', 'error'],
                        true,
                    )
                ) {
                    $this->store->opsItem(
                        $outcome,
                        OpsText::text('Webhook: {outcome}', ['outcome' => OpsText::text($outcome)]),
                        $jti,
                        $event['order_id'],
                        (int) $event['id'],
                    );
                } elseif ($event) {
                    $this->store->resolveEventItems((int) $event['id']);
                }
            }
        } catch (\Throwable $e) {
            error_log('webhook event recording failed');
        }
    }

    private function facts(array $body, ?string $jti = null): void
    {
        try {
            $facts = PolarWebhook::orderFacts($body);
            if ($facts === null) {
                return;
            }
            $map = json_decode(Config::get('FLUXFILES_POLAR_PLAN_MAP', '{}'), true) ?: [];
            $facts['plan'] = $map[$facts['product_id'] ?? ''] ?? null;
            if (
                $facts['plan'] === null &&
                in_array(
                    $facts['product_id'] ?? '',
                    PolarWebhook::ignoredProducts(
                        Config::get('FLUXFILES_POLAR_IGNORE_PRODUCTS', ''),
                    ),
                    true,
                )
            ) {
                // Sponsorship money still shows in the stats, under its own label.
                $facts['plan'] = 'ignored';
            }
            $facts['jti'] =
                $jti ?? ($this->store->findByOrder('polar', $facts['order_id'])['jti'] ?? null);
            $this->store->upsertOrder($facts);
            foreach ((new Stats($this->store->pdo()))->founding() as $f) {
                foreach ([80, 100] as $threshold) {
                    if ($f['used'] * 100 >= $f['cap'] * $threshold) {
                        $this->alert(
                            'discount.cap',
                            'info',
                            'cap:' . $f['code'] . ':' . $threshold,
                            OpsText::text(
                                'Discount {code} reached {percent}% (refunds shown separately)',
                                ['code' => $f['code'], 'percent' => $threshold],
                            ),
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('order accounting failed');
        }
    }

    /** A refund of a sponsorship (ignored-product) order: nothing to cancel, nothing to alert. */
    private function isIgnoredOrder(array $body, string $orderId): bool
    {
        $ignored = PolarWebhook::ignoredProducts(Config::get('FLUXFILES_POLAR_IGNORE_PRODUCTS', ''));
        if ($ignored === []) {
            return false;
        }
        $pid = PolarWebhook::cleanId((string) ($body['data']['product_id'] ?? ''));
        try {
            $q = $this->store->pdo()->prepare('SELECT product_id FROM orders WHERE order_id=?');
            $q->execute([$orderId]);
            $pid = $pid ?: (string) $q->fetchColumn();
        } catch (\Throwable $e) {
        }
        $map = json_decode(Config::get('FLUXFILES_POLAR_PLAN_MAP', '{}'), true) ?: [];
        return $pid !== '' && in_array($pid, $ignored, true) && !isset($map[$pid]);
    }

    private function alert(string $kind, string $severity, string $dedup, string $text): void
    {
        try {
            $this->store->enqueueAlert($kind, $severity, $dedup, $text);
        } catch (\Throwable $e) {
            error_log('alert enqueue failed');
        }
    }

    public function cascadeNotice(LicenseStore $store, string $origin): void
    {
        if ($store->lastCascade() === []) {
            return;
        }
        try {
            foreach ($store->lastCascade() as $row) {
                $store->opsItem(
                    'cascade',
                    OpsText::text('Paid renewal cancelled by cascade · {jti}', [
                        'jti' => Mask::id($row['jti']),
                    ]),
                    $row['jti'],
                );
            }
        } catch (\Throwable $e) {
            error_log('cascade item recording failed');
        }
        $lines = [];
        foreach ($store->lastCascade() as $r) {
            $lines[] = $r['jti'] . ' ' . $r['plan'] . ' ' . Mask::email($r['email']);
        }
        (new AdminApi($store, 'admin'))
            ->mailer()
            ->sendOpsNotice(
                'FluxFiles: renewals cancelled by a cascade',
                'Cancelling ' .
                    $origin .
                    ' also cancelled paid renewals: ' .
                    implode(', ', $lines) .
                    '. Reissue the paid year manually.',
            );
    }
}
