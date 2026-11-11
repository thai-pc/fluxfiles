<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

use FluxFiles\HttpTransportInterface;

/**
 * Amazon SES v2 (`POST https://email.<region>.amazonaws.com/v2/email/outbound-emails`).
 * Env: `FLUXFILES_SES_REGION`; credentials come from the standard AWS chain (`AWS_*`,
 * instance profile, …).
 *
 * The request is signed with the SDK's SignatureV4 and sent through the shared
 * {@see HttpTransportInterface} instead of `SesV2Client`, so core's composer config keeps
 * only the S3/Sts services and tests can fake the HTTP layer.
 */
final class SesTransport extends HttpApiTransport
{
    /** @var (callable(): \Aws\Credentials\CredentialsInterface)|null */
    private $credentials;

    /**
     * @param (callable(): \Aws\Credentials\CredentialsInterface)|null $credentials null = the SDK's default chain
     */
    public function __construct(
        private readonly string $region,
        string $fromAddress,
        string $fromName,
        HttpTransportInterface $http,
        ?callable $credentials = null
    ) {
        if (preg_match('/^[a-z]{2}(?:-[a-z]+)+-\d$/', $region) !== 1) {
            throw new \InvalidArgumentException('invalid SES region');
        }
        parent::__construct($fromAddress, $fromName, $http);
        $this->credentials = $credentials;
    }

    public function name(): string
    {
        return 'ses';
    }

    public function endpoint(): string
    {
        return 'https://email.' . $this->region . '.amazonaws.com/v2/email/outbound-emails';
    }

    public function send(MailMessage $m): void
    {
        $headers = [];
        foreach (MailMessage::AUTO_HEADERS as $k => $v) {
            $headers[] = ['Name' => $k, 'Value' => $v];
        }
        $payload = [
            'FromEmailAddress' => MailMessage::mailbox($this->fromAddress, $this->senderName($m), false),
            'Destination' => ['ToAddresses' => $m->to],
            'Content' => ['Simple' => [
                'Subject' => ['Data' => $m->subject, 'Charset' => 'UTF-8'],
                'Body' => ['Text' => ['Data' => $m->text, 'Charset' => 'UTF-8']],
                'Headers' => $headers,
            ]],
        ];
        if ($m->replyTo !== '') {
            $payload['ReplyToAddresses'] = [$m->replyTo];
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            // The SDK chain is built once per transport and memoised: off EC2 without AWS_*
            // every send would otherwise wait on IMDS probe timeouts again.
            $this->credentials ??= (static function (): callable {
                $provider = \Aws\Credentials\CredentialProvider::memoize(\Aws\Credentials\CredentialProvider::defaultProvider());
                return static fn () => $provider()->wait();
            })();
            $creds = ($this->credentials)();
            $request = new \GuzzleHttp\Psr7\Request('POST', $this->endpoint(), ['Content-Type' => 'application/json'], $body);
            $signed = (new \Aws\Signature\SignatureV4('ses', $this->region))->signRequest($request, $creds);
        } catch (\Throwable $e) {
            throw new MailException('ses: no usable AWS credentials');
        }

        $out = [];
        foreach ($signed->getHeaders() as $name => $values) {
            if (strtolower((string) $name) !== 'host') {
                $out[(string) $name] = implode(', ', $values);
            }
        }
        $secrets = [(string) $creds->getSecretKey(), (string) $creds->getAccessKeyId(), (string) $creds->getSecurityToken()];
        $this->post($this->endpoint(), $out, $body, array_values(array_filter($secrets, 'strlen')));
    }
}
