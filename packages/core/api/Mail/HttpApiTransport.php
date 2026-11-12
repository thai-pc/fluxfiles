<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- mail-layer exceptions are reported in the JSON error envelope or the server log (scrubbed), never rendered as HTML

use FluxFiles\HttpTransportInterface;

/**
 * Shared plumbing for the provider HTTP APIs (Resend, Postmark, Mailgun, SES): one
 * request through {@see HttpTransportInterface} (so tests fake it and a host can inject
 * its own HTTP stack), a 2xx check, and a scrubbed error.
 *
 * Every provider endpoint is a fixed host chosen in code, never from a claim, so there is
 * no SSRF surface here.
 */
abstract class HttpApiTransport implements MailTransportInterface
{
    public const TIMEOUT = 10;

    public function __construct(
        protected readonly string $fromAddress,
        protected readonly string $fromName,
        protected readonly HttpTransportInterface $http
    ) {
    }

    /** The display name for this message: the message's own, else the transport default. */
    protected function senderName(MailMessage $m): string
    {
        return $m->fromName !== '' ? $m->fromName : $this->fromName;
    }

    /**
     * @param array<int|string,string> $headers
     * @param list<string> $secrets credentials to scrub from any error
     * @return array{status:int, headers:array<string,string>, body:string, error?:string}
     */
    protected function post(string $url, array $headers, string $body, array $secrets): array
    {
        $res = $this->http->request('POST', $url, $headers, $body, self::TIMEOUT);
        if (($res['status'] ?? 0) === 0) {
            throw MailException::scrubbed($this->name() . ': request failed: ' . (string) ($res['error'] ?? 'no response'), $secrets);
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            // Scrub the whole body first (scrubbed() caps the result): truncating first could
            // cut a credential at the boundary and slip past the verbatim replacement.
            throw MailException::scrubbed($this->name() . ': HTTP ' . $res['status'] . ': ' . (string) $res['body'], $secrets);
        }
        return $res;
    }
}
