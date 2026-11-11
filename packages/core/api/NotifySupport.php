<?php

declare(strict_types=1);

namespace FluxFiles;

use FluxFiles\Mail\MailMessage;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration read from the environment (not user input); core is a WordPress-independent library

/**
 * The pieces of e-mail notices that the Intake and Share modules share, so both seal,
 * validate, cap and format notices the same way (design:
 * docs/design/INTAKE-SHARE-NOTIFICATIONS-DESIGN.md §2, §4, §6).
 *
 * - {@see recipients()} / {@see sealedConfig()}: what a portal/share create bakes into its
 *   record from the operator token + the create body.
 * - {@see seal()} / {@see open()}: the AES-256-GCM blob, AAD-bound to the record, so a
 *   tenant who can rewrite the JSON store (a BYOB bucket) cannot retarget the mail.
 * - {@see allow()} and the cap helpers: server-side send caps on core's rate limiter.
 * - {@see bytes()} / {@see time()} / {@see appLink()}: text formatting for templates.
 */
final class NotifySupport
{
    /** Owner notices per record per hour (excess is coalesced, never dropped). */
    public static function maxPerHour(): int
    {
        return max(1, (int) ($_ENV['FLUXFILES_NOTIFY_MAX_PER_HOUR'] ?? 6));
    }

    public static function addressDaily(): int
    {
        return max(1, (int) ($_ENV['FLUXFILES_NOTIFY_ADDRESS_DAILY'] ?? 100));
    }

    public static function receiptLimit(): int
    {
        return max(1, (int) ($_ENV['FLUXFILES_NOTIFY_RECEIPT_LIMIT'] ?? 10));
    }

    public static function receiptAddressDaily(): int
    {
        return max(1, (int) ($_ENV['FLUXFILES_NOTIFY_RECEIPT_ADDRESS_DAILY'] ?? 3));
    }

    /**
     * The recipients a create request may set: the body's `notify.to` (each one on the
     * token's `notify_to` or on a `notify_recipient_domains` domain), else all of
     * `notify_to`. `[]` = notices off for this record (no `notify_to`, or
     * `notify.enabled: false`).
     *
     * @param array<string,mixed> $body the create body
     * @return list<string>
     * @throws ApiException 400 notify_invalid_address / 403 notify_recipient_denied
     */
    public static function recipients(Claims $claims, array $body): array
    {
        if ($claims->notifyTo === []) {
            return [];
        }
        $n = is_array($body['notify'] ?? null) ? $body['notify'] : [];
        if (array_key_exists('enabled', $n) && filter_var($n['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
            return [];
        }
        if (!isset($n['to']) || $n['to'] === [] || $n['to'] === '') {
            return $claims->notifyTo;
        }
        $want = is_string($n['to']) ? explode(',', $n['to']) : (is_array($n['to']) ? $n['to'] : []);
        $out = [];
        foreach ($want as $raw) {
            $addr = is_string($raw) ? MailMessage::validAddress($raw) : null;
            if ($addr === null) {
                throw new ApiException('Invalid e-mail address', 400, 'notify_invalid_address');
            }
            $domain = substr($addr, (int) strrpos($addr, '@') + 1);
            if (!in_array($addr, $claims->notifyTo, true) && !in_array($domain, $claims->notifyRecipientDomains, true)) {
                throw new ApiException('That address is not an allowed recipient', 403, 'notify_recipient_denied');
            }
            $out[$addr] = true;
        }
        return array_slice(array_keys($out), 0, MailMessage::MAX_RECIPIENTS);
    }

    /**
     * The sealed plaintext for one record: recipients + every notice setting the record
     * needs later, read from the operator token now (a public request has no claims).
     *
     * @param list<string> $to
     * @param array<string,mixed> $body
     * @param list<string> $moduleEvents the events this module can send
     * @return array<string,mixed>
     */
    public static function sealedConfig(Claims $claims, array $to, array $body, array $moduleEvents): array
    {
        $n = is_array($body['notify'] ?? null) ? $body['notify'] : [];
        $events = $claims->notifyEvents === [] ? $moduleEvents : array_values(array_intersect($claims->notifyEvents, $moduleEvents));
        return [
            'v' => 1,
            'to' => $to,
            'events' => $events,
            'locale' => NotifyEndpoint::locale($claims, ['locale' => $n['locale'] ?? ($body['locale'] ?? '')]),
            'from_name' => $claims->notifyFromName,
            'reply_to' => $claims->notifyReplyTo,
            'app_url' => $claims->notifyAppUrl,
            'window' => $claims->notifyBatchWindow,
            'names' => $claims->notifyIncludeNames,
            'receipt' => $claims->intakeNotifyReceipt,
        ];
    }

    /** @param array<string,mixed> $cfg */
    public static function seal(array $cfg, #[\SensitiveParameter] string $secret, string $info, string $aad): string
    {
        $key = hash_hkdf('sha256', $secret, 32, $info);
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt((string) json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($ct === false) {
            throw new \RuntimeException('notify config encryption failed');
        }
        return base64_encode($nonce . $ct . $tag);
    }

    /** @return array<string,mixed>|null null = malformed, tampered, or bound to another record */
    public static function open(string $blob, #[\SensitiveParameter] string $secret, string $info, string $aad): ?array
    {
        if ($secret === '') {
            return null;
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            return null;
        }
        $key = hash_hkdf('sha256', $secret, 32, $info);
        $pt = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16), $aad);
        $cfg = $pt === false ? null : json_decode($pt, true);
        if (!is_array($cfg) || ($cfg['v'] ?? null) !== 1 || !is_array($cfg['to'] ?? null)) {
            return null;
        }
        // Re-validate on the way out: the blob is authentic, but be strict about what a send uses.
        $cfg['to'] = array_values(array_filter(array_map(
            static fn ($a) => is_string($a) ? MailMessage::validAddress($a) : null,
            $cfg['to']
        )));
        return $cfg['to'] === [] ? null : $cfg;
    }

    /**
     * One hit against `$bucket` in core's rate limiter; false (never an exception) when the
     * bucket is full. Each purpose uses its own key prefix: the limiter keeps one history
     * per key, so two windows must never share a key.
     */
    public static function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        try {
            RateLimiterFactory::make(max(1, $limit), max(1, $limit), $windowSeconds)->check($bucket, 'read');
            return true;
        } catch (ApiException $e) {
            return false;
        } catch (\Throwable $e) {
            // A limiter that cannot be read fails closed for mail: no notice is better than
            // an uncapped one.
            Log::warn('[fluxfiles] notify rate limiter unavailable: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The recipients still under their daily cap (the limiter key is a hash: no address is
     * ever written to rate-limit storage).
     *
     * @param list<string> $to
     * @return list<string>
     */
    public static function underDailyCap(array $to, string $kind, int $cap): array
    {
        $out = [];
        foreach ($to as $addr) {
            if (self::allow('notify_addr_' . $kind . ':' . hash('sha256', strtolower($addr)), $cap, 86400)) {
                $out[] = $addr;
            }
        }
        return $out;
    }

    /** `1.2 MB` — the same units the file manager shows. */
    public static function bytes(int $n): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) max(0, $n);
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $v : number_format($v, $v >= 10 ? 0 : 1)) . ' ' . $units[$i];
    }

    /** `2026-11-11 14:05 UTC` — unambiguous for a recipient in any time zone. */
    public static function time(int $ts): string
    {
        return gmdate('Y-m-d H:i', $ts) . ' UTC';
    }

    /** The operator's `notify_app_url` with `{path}` filled in (URL-encoded), or '' when unset. */
    public static function appLink(string $template, string $path): string
    {
        if ($template === '') {
            return '';
        }
        return str_replace('{path}', rawurlencode(ltrim($path, '/')), $template);
    }
}
