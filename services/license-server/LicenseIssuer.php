<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Orchestrates issuance: plan → sign → store. The one call every gateway webhook
 * (and the manual admin endpoint) funnels through, so issuance is identical
 * regardless of the checkout platform.
 */
final class LicenseIssuer
{
    public function __construct(
        private LicenseSigner $signer,
        private LicenseStore $store
    ) {}

    /**
     * Issue a license for a purchase. Idempotent on (gateway, order_id) via the store.
     *
     * @param array{email:string,plan:string,customer?:string,gateway?:string,order_id?:string,
     *              sites?:int,domains?:string[]} $order
     * @return array{key:string, record:array<string,mixed>, reused:bool}
     */
    public function issue(array $order): array
    {
        $email = trim((string) ($order['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required');
        }
        $planId = (string) ($order['plan'] ?? '');
        $plan = Plans::get($planId);
        if ($plan === null) {
            throw new \InvalidArgumentException("Unknown plan: {$planId}");
        }

        // Idempotency: a repeat webhook for the same order returns the stored key.
        if (!empty($order['gateway']) && !empty($order['order_id'])) {
            $existing = $this->store->findByOrder((string) $order['gateway'], (string) $order['order_id']);
            if ($existing !== null) {
                return ['key' => (string) $existing['license_key'], 'record' => $existing, 'reused' => true];
            }
        }

        $minted = $this->signer->mint([
            'customer'    => (string) ($order['customer'] ?? $email),
            'edition'     => (string) $plan['edition'],
            'modules'     => (array) $plan['modules'],
            'enforcement' => (string) $plan['enforcement'],
            'sites'       => (int) ($order['sites'] ?? $plan['sites']),
            'ttlDays'     => $plan['ttlDays'],
            'domains'     => $order['domains'] ?? [],
        ]);
        $p = $minted['payload'];

        // LicenseSigner::mint() bakes the grace window (seconds) into the signed
        // payload as 'grace' when there's an expiry at all; read the days back out of
        // it so the store persists the ACTUAL value used (currently always the
        // signer's own default of 14, since no Plans::DEFAULT entry overrides it and
        // mint() isn't given a per-plan override either), instead of the reminder job
        // hardcoding 14 and silently drifting the day a per-plan grace override is
        // wired into the mint() call above.
        $graceDays = isset($p['grace']) ? (int) round($p['grace'] / 86400) : 14;

        $record = $this->store->record([
            'jti'         => $p['jti'],
            'email'       => $email,
            'customer'    => $p['customer'],
            'plan'        => $planId,
            'edition'     => $p['edition'],
            'modules'     => $p['modules'],
            'sites'       => $p['limits']['sites'],
            'enforcement' => $p['enforcement'],
            'issued'      => $p['issued'],
            'expires'     => $p['expires'] ?? null,
            'license_key' => $minted['key'],
            'gateway'     => (string) ($order['gateway'] ?? 'manual'),
            'order_id'    => (string) ($order['order_id'] ?? ''),
            'checkout_id' => (string) ($order['checkout_id'] ?? ''),
            'status'      => 'active',
            'grace_days'  => $graceDays,
        ]);

        // A recurring subscription renewal is a NEW order (fresh order_id) but the
        // SAME customer+plan — retire any other still-`active` row for that pair so
        // only this newest one stays eligible for reminders (see the store method's
        // docblock for why the duplicate-row is otherwise unavoidable here).
        $this->store->supersedeActiveForCustomerPlan(
            (string) $record['customer'],
            (string) $record['plan'],
            (string) $record['jti']
        );

        return ['key' => $minted['key'], 'record' => $record, 'reused' => false];
    }
}
