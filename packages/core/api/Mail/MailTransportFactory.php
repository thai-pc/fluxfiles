<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

use FluxFiles\CurlTransport;
use FluxFiles\HttpTransportInterface;
use FluxFiles\Log;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration read from the environment (not user input); core is a WordPress-independent library

/**
 * Builds the operator's mail transport from server env. Mail credentials are env-only,
 * never claims: the From address must be on a domain verified with the provider (an
 * operator-wide property), and a JWT is signed, not encrypted.
 *
 * Off (null) unless `FLUXFILES_MAIL_TRANSPORT` names a transport AND its settings are
 * complete. A misconfiguration logs one warning and turns mail off; it never throws, so
 * a broken mail setting can never fail an upload or a download.
 */
final class MailTransportFactory
{
    public const TRANSPORTS = ['smtp', 'resend', 'postmark', 'mailgun', 'ses', 'log'];

    /**
     * @param array<string,mixed>|null $env null = `$_ENV`
     * @param string|null $why set to a non-secret reason when the result is null
     */
    public static function fromEnv(?HttpTransportInterface $http = null, ?array $env = null, ?string &$why = null): ?MailTransportInterface
    {
        $env ??= $_ENV;
        $get = static fn (string $k, string $d = ''): string => trim((string) ($env[$k] ?? $d));
        $why = null;

        $kind = strtolower($get('FLUXFILES_MAIL_TRANSPORT'));
        if ($kind === '') {
            $why = 'FLUXFILES_MAIL_TRANSPORT is not set';
            return null;
        }
        if (!in_array($kind, self::TRANSPORTS, true)) {
            return self::off($why, 'FLUXFILES_MAIL_TRANSPORT is not one of ' . implode('|', self::TRANSPORTS));
        }
        if ($kind === 'log') {
            return new LogTransport();
        }

        $from = MailMessage::validAddress($get('FLUXFILES_MAIL_FROM'));
        if ($from === null) {
            return self::off($why, 'FLUXFILES_MAIL_FROM is missing or not a valid address');
        }
        $fromName = MailMessage::cleanLabel($get('FLUXFILES_MAIL_FROM_NAME', 'FluxFiles'), 80);
        $http ??= new CurlTransport(5);

        try {
            switch ($kind) {
                case 'smtp':
                    $host = $get('FLUXFILES_SMTP_HOST');
                    $user = $get('FLUXFILES_SMTP_USER');
                    $tls = strtolower($get('FLUXFILES_SMTP_TLS', 'tls'));
                    if ($host === '') {
                        return self::off($why, 'FLUXFILES_SMTP_HOST is not set');
                    }
                    if ($tls === 'none' && $user !== '' && !self::isLoopback($host)) {
                        return self::off($why, 'FLUXFILES_SMTP_TLS=none with a password is only allowed to a loopback host');
                    }
                    return new SmtpTransport($host, (int) $get('FLUXFILES_SMTP_PORT', '587'), $user,
                        (string) ($env['FLUXFILES_SMTP_PASS'] ?? ''), $tls, $from, $fromName);
                case 'resend':
                    $key = $get('FLUXFILES_RESEND_API_KEY');
                    return $key === '' ? self::off($why, 'FLUXFILES_RESEND_API_KEY is not set')
                        : new ResendTransport($key, $from, $fromName, $http);
                case 'postmark':
                    $token = $get('FLUXFILES_POSTMARK_TOKEN');
                    return $token === '' ? self::off($why, 'FLUXFILES_POSTMARK_TOKEN is not set')
                        : new PostmarkTransport($token, $get('FLUXFILES_POSTMARK_STREAM', 'outbound') ?: 'outbound', $from, $fromName, $http);
                case 'mailgun':
                    $key = $get('FLUXFILES_MAILGUN_API_KEY');
                    $domain = strtolower($get('FLUXFILES_MAILGUN_DOMAIN'));
                    if ($key === '' || $domain === '') {
                        return self::off($why, 'FLUXFILES_MAILGUN_API_KEY and FLUXFILES_MAILGUN_DOMAIN are required');
                    }
                    return new MailgunTransport($key, $domain, strtolower($get('FLUXFILES_MAILGUN_REGION', 'us')) === 'eu' ? 'eu' : 'us', $from, $fromName, $http);
                case 'ses':
                    $region = strtolower($get('FLUXFILES_SES_REGION'));
                    return $region === '' ? self::off($why, 'FLUXFILES_SES_REGION is not set')
                        : new SesTransport($region, $from, $fromName, $http);
            }
        } catch (\InvalidArgumentException $e) {
            return self::off($why, $e->getMessage());
        }
        return null;
    }

    private static function off(?string &$why, string $reason): ?MailTransportInterface
    {
        $why = $reason;
        Log::warn('[fluxfiles] e-mail notifications are off: ' . $reason);
        return null;
    }

    private static function isLoopback(string $host): bool
    {
        $h = strtolower(trim($host, '[]'));
        if ($h === 'localhost' || $h === '::1') {
            return true;
        }
        return filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($h, '127.');
    }
}
