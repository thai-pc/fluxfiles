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
            'status'      => 'active',
        ]);

        return ['key' => $minted['key'], 'record' => $record, 'reused' => false];
    }
}
