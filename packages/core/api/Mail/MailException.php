<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

/** A send failed. The message is safe to show to the operator: no credential is ever in it. */
final class MailException extends \RuntimeException
{
    /**
     * Remove every secret from a provider error before it becomes an exception message:
     * the given credentials verbatim, anything that looks like a long token, and e-mail
     * addresses (masked). Capped at 300 chars.
     *
     * @param list<string> $secrets
     */
    public static function scrubbed(string $message, array $secrets = []): self
    {
        foreach ($secrets as $s) {
            if ($s !== '') {
                $message = str_replace($s, '[redacted]', $message);
            }
        }
        $message = (string) preg_replace('/[A-Za-z0-9_+\/=.-]{32,}/', '[redacted]', $message);
        $message = (string) preg_replace_callback(
            '/[A-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            static fn (array $m): string => MailMessage::maskAddress(strtolower($m[0])),
            $message
        );
        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));
        return new self(mb_substr($message, 0, 300));
    }
}
