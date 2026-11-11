<?php

declare(strict_types=1);

namespace FluxFiles\Mail;

/**
 * Sends one {@see MailMessage}. Built from the operator's env by {@see MailTransportFactory};
 * a host with its own mail stack injects its own (WordPress: `wp_mail()`, Laravel:
 * `Mail::raw()`), so the site's SMTP plugin or `config/mail.php` delivers it.
 *
 * Contract: returns on success, throws {@see MailException} on any failure. The exception
 * message never contains a credential (callers may show it to the operator, e.g. from
 * `/api/fm/notify/test`).
 */
interface MailTransportInterface
{
    /** @throws MailException */
    public function send(MailMessage $message): void;

    /** A short, non-secret name for diagnostics: `smtp`, `resend`, `wp_mail`, … */
    public function name(): string;
}
