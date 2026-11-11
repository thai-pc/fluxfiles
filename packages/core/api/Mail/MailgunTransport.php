<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

use FluxFiles\HttpTransportInterface;

/**
 * Mailgun (`POST https://api[.eu].mailgun.net/v3/<domain>/messages`, form-encoded, HTTP
 * Basic `api:<key>`). Env: `FLUXFILES_MAILGUN_API_KEY`, `_DOMAIN`, `_REGION` (`us`|`eu`).
 */
final class MailgunTransport extends HttpApiTransport
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $domain,
        private readonly string $region,
        string $fromAddress,
        string $fromName,
        HttpTransportInterface $http
    ) {
        if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $domain) !== 1) {
            throw new \InvalidArgumentException('invalid Mailgun domain');
        }
        parent::__construct($fromAddress, $fromName, $http);
    }

    public function name(): string
    {
        return 'mailgun';
    }

    public function endpoint(): string
    {
        $host = $this->region === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';
        return 'https://' . $host . '/v3/' . $this->domain . '/messages';
    }

    public function send(MailMessage $m): void
    {
        $fields = [
            'from' => MailMessage::mailbox($this->fromAddress, $this->senderName($m), false),
            'subject' => $m->subject,
            'text' => $m->text,
        ];
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        foreach ($m->to as $to) {
            $body .= '&to=' . rawurlencode($to);
        }
        if ($m->replyTo !== '') {
            $body .= '&' . rawurlencode('h:Reply-To') . '=' . rawurlencode($m->replyTo);
        }
        foreach (MailMessage::AUTO_HEADERS as $k => $v) {
            $body .= '&' . rawurlencode('h:' . $k) . '=' . rawurlencode($v);
        }
        $this->post($this->endpoint(), [
            'Authorization' => 'Basic ' . base64_encode('api:' . $this->apiKey),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body, [$this->apiKey, base64_encode('api:' . $this->apiKey)]);
    }
}
