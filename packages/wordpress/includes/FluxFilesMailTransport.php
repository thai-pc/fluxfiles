<?php

defined('ABSPATH') || exit;

use FluxFiles\Mail\MailException;
use FluxFiles\Mail\MailMessage;
use FluxFiles\Mail\MailTransportInterface;

/**
 * E-mail notices through `wp_mail()`, so the site's own mail setup delivers them: an SMTP
 * plugin (WP Mail SMTP, FluentSMTP, Post SMTP, an SES plugin…) or the host's PHP mail.
 * Nothing to configure in FluxFiles.
 *
 * The From address is left to WordPress / the SMTP plugin (it knows the verified sender)
 * unless the site sets `FLUXFILES_MAIL_FROM` (env var or wp-config constant). The display
 * name — the notice's own (`notify_from_name`), else `FLUXFILES_MAIL_FROM_NAME`, else the
 * site's — is applied for this one message only, through a short-lived `wp_mail_from_name`
 * filter, so the From header carries a bare address that every wp_mail() replacement parses.
 *
 * Loaded only when the bundled core ships the mail layer (see FluxFilesApi::mailer()).
 */
class FluxFilesMailTransport implements MailTransportInterface
{
    private ?string $from;

    private string $fromName;

    /** @param string $from FLUXFILES_MAIL_FROM, $fromName FLUXFILES_MAIL_FROM_NAME (both resolved by the caller) */
    public function __construct(string $from = '', string $fromName = '')
    {
        $this->from = MailMessage::validAddress($from);
        $this->fromName = trim(preg_replace('/[\r\n]+/', ' ', $fromName));
    }

    public function name(): string
    {
        return 'wp_mail';
    }

    public function send(MailMessage $m): void
    {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if ($this->from !== null) {
            $headers[] = 'From: ' . $this->from;
        }
        if ($m->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $m->replyTo;
        }
        foreach (MailMessage::AUTO_HEADERS as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $nameFilter = null;
        $display = $m->fromName !== '' ? $m->fromName : $this->fromName;
        if ($display !== '') {
            $nameFilter = static fn () => $display;
            add_filter('wp_mail_from_name', $nameFilter, 99);
        }
        $error = null;
        $onFail = static function ($wpError) use (&$error): void {
            $error = is_object($wpError) && method_exists($wpError, 'get_error_message') ? (string) $wpError->get_error_message() : 'wp_mail failed';
        };
        add_action('wp_mail_failed', $onFail);
        try {
            $ok = wp_mail($m->to, $m->subject, $m->text, $headers);
        } finally {
            remove_action('wp_mail_failed', $onFail);
            if ($nameFilter !== null) {
                remove_filter('wp_mail_from_name', $nameFilter, 99);
            }
        }
        if (!$ok) {
            throw MailException::scrubbed('wp_mail: ' . ($error ?? 'the site could not send the message'));
        }
    }
}
