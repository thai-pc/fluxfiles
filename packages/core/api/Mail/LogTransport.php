<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

use FluxFiles\Log;

/**
 * Development transport (`FLUXFILES_MAIL_TRANSPORT=log`): writes the message to the PHP
 * error log instead of sending it. Addresses are masked and long tokens redacted, so
 * turning it on in production cannot leak a working link or key into a log file.
 */
final class LogTransport implements MailTransportInterface
{
    /** @var list<string> captured lines (tests read them back) */
    public array $sent = [];

    public function name(): string
    {
        return 'log';
    }

    public function send(MailMessage $m): void
    {
        $to = implode(', ', array_map([MailMessage::class, 'maskAddress'], $m->to));
        $redact = static fn (string $s): string => (string) preg_replace(
            '/[A-Za-z0-9_-]{16,}(?:\.[A-Za-z0-9_+\/=-]{16,})+|[A-Za-z0-9_+\/=-]{40,}/',
            '[redacted]',
            $s
        );
        $line = '[fluxfiles] mail (log transport) to=' . $to . ' subject=' . $redact($m->subject) . "\n" . $redact($m->text);
        $this->sent[] = $line;
        Log::warn($line);
    }
}
