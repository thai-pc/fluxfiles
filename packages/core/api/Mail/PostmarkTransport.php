<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

use FluxFiles\HttpTransportInterface;

/**
 * Postmark (`POST https://api.postmarkapp.com/email`). Env: `FLUXFILES_POSTMARK_TOKEN`
 * (server token), `FLUXFILES_POSTMARK_STREAM` (message stream, default `outbound`).
 */
final class PostmarkTransport extends HttpApiTransport
{
    public const ENDPOINT = 'https://api.postmarkapp.com/email';

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $stream,
        string $fromAddress,
        string $fromName,
        HttpTransportInterface $http
    ) {
        parent::__construct($fromAddress, $fromName, $http);
    }

    public function name(): string
    {
        return 'postmark';
    }

    public function send(MailMessage $m): void
    {
        $headers = [];
        foreach (MailMessage::AUTO_HEADERS as $k => $v) {
            $headers[] = ['Name' => $k, 'Value' => $v];
        }
        $payload = [
            'From' => MailMessage::mailbox($this->fromAddress, $this->senderName($m), false),
            'To' => implode(',', $m->to),
            'Subject' => $m->subject,
            'TextBody' => $m->text,
            'MessageStream' => $this->stream,
            'Headers' => $headers,
        ];
        if ($m->replyTo !== '') {
            $payload['ReplyTo'] = $m->replyTo;
        }
        $this->post(self::ENDPOINT, [
            'X-Postmark-Server-Token' => $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [$this->token]);
    }
}
