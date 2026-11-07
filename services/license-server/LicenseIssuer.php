<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Orchestrates issuance: plan → sign → store. The one call every gateway webhook
 * (and the manual admin endpoint) funnels through, so issuance is identical
 * regardless of the checkout platform.
 */
/** A renewal was bought but there is no eligible licence to extend. */
final class RenewalWithoutLicenceException extends \InvalidArgumentException {}

final class LicenseIssuer
{
    public function __construct(
        private LicenseSigner $signer,
        private LicenseStore $store
    ) {}

    /**
     * Issue a license for a purchase. Idempotent on (gateway, order_id) via the store.
     *
     * A `*-renewal` plan extends the buyer's existing lifetime licence instead of
     * creating one; with nothing to extend it throws RenewalWithoutLicenceException
     * (it deliberately does NOT fall back to the base plan: a $29 renewal must not
     * unlock what costs $179).
     *
     * @param array{email:string,plan:string,customer?:string,gateway?:string,order_id?:string} $order
     * @throws RenewalWithoutLicenceException
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

        // A renewal has no edition/modules of its own: it re-mints what the buyer holds.
        $extendFrom = 0;
        $retire = [$planId];
        if (isset($plan['renews'])) {
            $base = $this->store->activeForBuyerPlans($email, (array) $plan['renews']);
            if ($base === null) {
                throw new RenewalWithoutLicenceException("No licence to renew for {$email} ({$planId})");
            }
            if (($base['expires'] ?? null) === null) {
                // A legacy no-expiry key already has lifetime updates; "renewing" it would
                // replace that with a 12-month term.
                throw new RenewalWithoutLicenceException("Licence {$base['jti']} already has lifetime updates ({$planId})");
            }
            $extendFrom = (int) $base['expires'];
            $plan['edition'] = (string) $base['edition'];
            $plan['modules'] = array_values(array_filter(explode(',', (string) $base['modules'])));
            $retire = (array) $plan['renews'];
        }

        $minted = $this->signer->mint([
            'customer'    => (string) ($order['customer'] ?? $email),
            'edition'     => (string) $plan['edition'],
            'modules'     => (array) $plan['modules'],
            'enforcement' => (string) $plan['enforcement'],
            'ttlDays'     => $plan['ttlDays'],
            'graceDays'   => (int) ($plan['graceDays'] ?? 14),
            'extendFrom'  => $extendFrom,
        ]);
        $p = $minted['payload'];

        // Read the signed grace window back from the payload so the reminder store
        // records the actual per-plan policy rather than duplicating it.
        $graceDays = isset($p['grace']) ? (int) round($p['grace'] / 86400) : 14;

        $record = $this->store->record([
            'jti'         => $p['jti'],
            'email'       => $email,
            'customer'    => $p['customer'],
            'plan'        => $planId,
            'edition'     => $p['edition'],
            'modules'     => $p['modules'],
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

        if ((string) $record['jti'] !== (string) $p['jti']) {
            // A concurrent delivery of this order stored its licence first; ours was
            // never recorded, so hand back the one that was.
            return ['key' => (string) $record['license_key'], 'record' => $record, 'reused' => true];
        }

        // A recurring subscription renewal is a NEW order (fresh order_id) but the
        // SAME buyer+plan — retire any other still-`active` gateway row for that pair
        // so only this newest one stays eligible for reminders (see the store
        // method's docblock for why the duplicate-row is otherwise unavoidable here).
        // Keyed on the buyer's EMAIL, not their display name: two different people
        // called "John Smith" must not retire each other's licences. Manual issues
        // are left alone — an operator may deliberately hand one buyer two keys.
        if ((string) $record['gateway'] !== 'manual') {
            // A renewal also retires the licence it extends (and earlier renewals).
            foreach (array_unique(array_merge($retire, [$planId])) as $p) {
                $this->store->supersedeActiveForBuyerPlan(
                    (string) $record['email'],
                    (string) $p,
                    (string) $record['jti']
                );
            }
        }

        return ['key' => $minted['key'], 'record' => $record, 'reused' => false];
    }
}
