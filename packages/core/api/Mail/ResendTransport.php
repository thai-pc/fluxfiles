<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

use FluxFiles\HttpTransportInterface;

/** Resend (`POST https://api.resend.com/emails`). Env: `FLUXFILES_RESEND_API_KEY`. */
final class ResendTransport extends HttpApiTransport
{
    public const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        string $fromAddress,
        string $fromName,
        HttpTransportInterface $http
    ) {
        parent::__construct($fromAddress, $fromName, $http);
    }

    public function name(): string
    {
        return 'resend';
    }

    public function send(MailMessage $m): void
    {
        $payload = [
            'from' => MailMessage::mailbox($this->fromAddress, $this->senderName($m), false),
            'to' => $m->to,
            'subject' => $m->subject,
            'text' => $m->text,
            'headers' => MailMessage::AUTO_HEADERS,
        ];
        if ($m->replyTo !== '') {
            $payload['reply_to'] = $m->replyTo;
        }
        $this->post(self::ENDPOINT, [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Idempotency-Key' => bin2hex(random_bytes(16)),
        ], (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [$this->apiKey]);
    }
}
