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
     * A `*-renewal` plan extends the buyer's existing lifetime licence instead of
     * creating one; with nothing to extend it throws RenewalWithoutLicenceException
     * (it deliberately does NOT fall back to the base plan: a $29 renewal must not
     * unlock what costs $179).
     *
     * A renewal runs lookup -> mint -> record -> supersede in ONE write transaction, so
     * two concurrent renewals extend twice and leave a consistent chain.
     *
     * @param array{email:string,plan:string,customer?:string,customer_id?:string,gateway?:string,order_id?:string} $order
     * @throws RenewalWithoutLicenceException
     * @return array{key:string, record:array<string,mixed>, reused:bool}
     */
    public function issue(array $order): array
    {
        $plan = Plans::get((string) ($order['plan'] ?? ''));
        if ($plan !== null && isset($plan['renews'])) {
            return $this->store->transaction(fn () => $this->issueNow($order));
        }
        return $this->issueNow($order);
    }

    /** @return array{key:string, record:array<string,mixed>, reused:bool} */
    private function issueNow(array $order): array
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
        $base = null;
        $customerId = trim((string) ($order['customer_id'] ?? ''));
        if (isset($plan['renews'])) {
            // The email is typed at checkout and proves nothing, so when Polar's
            // customer id is known on both sides it must match too (see the store).
            // Legacy/manual bases carry no customer id and fall back to the email; the
            // key is mailed to that licence's own address and never served via /claim.
            // Dated rows only: a legacy no-expiry key already has lifetime updates and
            // a 12-month term would be a downgrade, so it is simply not renewable.
            $base = $this->store->activeForBuyerPlans($email, (array) $plan['renews'], $customerId);
            if ($base === null) {
                throw new RenewalWithoutLicenceException("No renewable lifetime licence for {$email} ({$planId})");
            }
            $extendFrom = (int) $base['expires'];
            $plan['edition'] = (string) $base['edition'];
            $plan['modules'] = array_values(array_filter(explode(',', (string) $base['modules'])));
        }

        // Keep `issued` strictly above everything this subscription/chain has produced, so a
        // clock that stepped backwards cannot mint a key a refreshing client would reject.
        $subId = trim((string) ($order['subscription_id'] ?? ''));
        $floor = 0;
        if ($base !== null) {
            $floor = (int) $base['issued'];
        } elseif ($subId !== '') {
            $floor = $this->store->maxIssuedForSubscription($subId);
        } elseif (($order['gateway'] ?? 'manual') !== 'manual') {
            $floor = $this->store->maxIssuedForBuyerPlan($email, $planId);
        }

        $minted = $this->signer->mint([
            'customer'    => (string) ($order['customer'] ?? $email),
            'edition'     => (string) $plan['edition'],
            'modules'     => (array) $plan['modules'],
            'enforcement' => (string) $plan['enforcement'],
            'ttlDays'     => $plan['ttlDays'],
            'graceDays'   => (int) ($plan['graceDays'] ?? 14),
            'extendFrom'  => $extendFrom,
            'issuedAtLeast' => $floor > 0 ? $floor + 1 : 0,
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
            'customer_id' => $customerId,
            'renews_jti'  => $base !== null ? (string) $base['jti'] : '',
            'subscription_id' => $subId,
            'billing_reason'  => (string) ($order['billing_reason'] ?? ''),
        ]);

        if ((string) $record['jti'] !== (string) $p['jti']) {
            // A concurrent delivery of this order stored its licence first; ours was
            // never recorded, so hand back the one that was.
            return ['key' => (string) $record['license_key'], 'record' => $record, 'reused' => true];
        }

        if ($base !== null) {
            // A renewal retires ONLY the row it extended (any gateway, manual included),
            // so that row stops reminding and a second lifetime under the same email
            // stays independently renewable.
            $this->store->supersedeJti((string) $base['jti']);
        } elseif ((string) $record['gateway'] !== 'manual' && $subId !== '') {
            // The subscription is known: retire only ITS earlier keys, so a buyer's second
            // subscription on the same plan keeps running.
            $this->store->supersedeActiveForSubscription($subId, (string) $record['jti']);
        } elseif ((string) $record['gateway'] !== 'manual') {
            // No subscription id (legacy / one-time): A recurring subscription renewal is a NEW order (fresh order_id) but the
            // SAME buyer+plan — retire any other still-`active` gateway row for that pair
            // so only this newest one stays eligible for reminders (see the store
            // method's docblock for why the duplicate-row is otherwise unavoidable here).
            // Keyed on the buyer's EMAIL, not their display name: two different people
            // called "John Smith" must not retire each other's licences. Manual issues
            // are left alone — an operator may deliberately hand one buyer two keys.
            $this->store->supersedeActiveForBuyerPlan(
                (string) $record['email'],
                (string) $record['plan'],
                (string) $record['jti']
            );
        }

        return ['key' => $minted['key'], 'record' => $record, 'reused' => false];
    }
}

/** A renewal was bought but there is no eligible licence to extend. */
final class RenewalWithoutLicenceException extends \InvalidArgumentException {}
