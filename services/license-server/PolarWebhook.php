<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Polar webhook verification + payload extraction.
 *
 * Polar follows the **Standard Webhooks** spec, which is a different scheme from the
 * plain `hash_hmac(raw)` used by most gateways — the signature covers
 * `{webhook-id}.{webhook-timestamp}.{raw-body}`, is base64 (not hex), arrives
 * `v1,`-prefixed in `webhook-signature`, and may carry several space-separated
 * signatures during a secret rotation.
 *
 * Kept out of server.php so the part most likely to fail silently is directly testable:
 * a signature check that is wrong in the "always false" direction only shows up as
 * every purchase being dropped, and one that is wrong in the "always true" direction
 * lets anyone mint licences.
 */
final class PolarWebhook
{
    /** Reject anything older than this. Standard Webhooks' recommended replay window. */
    public const TOLERANCE_SECONDS = 300;

    /**
     * Verify a Standard Webhooks signature.
     *
     * @param array<string,string> $headers webhook-id / webhook-timestamp / webhook-signature
     * @return array{0:bool,1:string} [ok, reason] — reason is for logging, never for the client
     */
    public static function verify(string $raw, string $secret, array $headers, ?int $now = null): array
    {
        $now = $now ?? time();
        $id = trim((string) ($headers['webhook-id'] ?? ''));
        $ts = trim((string) ($headers['webhook-timestamp'] ?? ''));
        $sigHeader = trim((string) ($headers['webhook-signature'] ?? ''));

        if ($secret === '') { return [false, 'no secret configured']; }
        if ($id === '' || $ts === '' || $sigHeader === '') { return [false, 'missing signature headers']; }
        if (!ctype_digit($ts)) { return [false, 'bad timestamp']; }
        if (abs($now - (int) $ts) > self::TOLERANCE_SECONDS) { return [false, 'timestamp outside tolerance']; }

        $signed = $id . '.' . $ts . '.' . $raw;

        // Two candidate keys, because implementations disagree on the `whsec_` prefix:
        // the Standard Webhooks spec base64-decodes the part after it, while Polar has
        // been observed HMAC-ing the secret string as-is. Both are derived from the same
        // operator-held secret, so accepting either cannot help an attacker who does not
        // have it — and getting this wrong silently drops every purchase.
        $candidates = [$secret];
        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);
            if ($decoded !== false && $decoded !== '') { $candidates[] = $decoded; }
        }

        // A rotation sends several signatures at once; any one matching is enough.
        foreach (preg_split('/\s+/', $sigHeader) ?: [] as $part) {
            if ($part === '') { continue; }
            $value = str_starts_with($part, 'v1,') ? substr($part, 3) : $part;
            foreach ($candidates as $key) {
                $expected = base64_encode(hash_hmac('sha256', $signed, $key, true));
                if (hash_equals($expected, $value)) { return [true, 'ok']; }
            }
        }
        return [false, 'signature mismatch'];
    }

    /**
     * Pull the fields the issuer needs out of a `{type, data}` envelope.
     *
     * Returns null when this event is not one we issue on — the caller must still ACK
     * it, because a non-2xx makes Polar retry an event we will never act on.
     *
     * @param array<string,mixed> $plans product-id → plan-name map
     * @return array{email:string,plan:string,customer:string,order_id:string}|null
     */
    public static function extract(array $body, array $plans): ?array
    {
        $type = (string) ($body['type'] ?? '');
        // order.paid is the one that means money actually moved: order.created fires
        // with status `pending`, and issuing there would hand out a licence for a
        // payment that can still fail.
        if ($type !== 'order.paid') { return null; }

        $d = $body['data'] ?? [];
        if (!is_array($d)) { return null; }

        $status = (string) ($d['status'] ?? '');
        if ($status !== '' && $status !== 'paid') { return null; }

        $email = (string) ($d['customer']['email'] ?? $d['customer_email'] ?? '');
        $productId = (string) ($d['product_id'] ?? $d['product']['id'] ?? '');
        $orderId = (string) ($d['id'] ?? '');

        // Fall back to the product NAME only to make a misconfigured map obvious in the
        // logs; Plans::valid() still refuses anything that is not a real plan, so an
        // unmapped product fails loudly rather than issuing the wrong edition.
        $plan = (string) ($plans[$productId] ?? strtolower((string) ($d['product']['name'] ?? '')));

        return [
            'email' => $email,
            'plan' => $plan,
            'customer' => (string) ($d['customer']['name'] ?? $d['customer']['email'] ?? $email),
            'order_id' => $orderId,
        ];
    }

    /**
     * Standard Webhooks headers from $_SERVER, lowercased.
     *
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    public static function headersFromServer(array $server): array
    {
        return [
            'webhook-id' => (string) ($server['HTTP_WEBHOOK_ID'] ?? ''),
            'webhook-timestamp' => (string) ($server['HTTP_WEBHOOK_TIMESTAMP'] ?? ''),
            'webhook-signature' => (string) ($server['HTTP_WEBHOOK_SIGNATURE'] ?? ''),
        ];
    }
}
