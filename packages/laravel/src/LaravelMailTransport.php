<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use FluxFiles\Mail\MailException;
use FluxFiles\Mail\MailMessage;
use FluxFiles\Mail\MailTransportInterface;

/**
 * E-mail notices through the application's own mailer (`Mail::raw()`), so they go out the
 * way `config/mail.php` says (SMTP, SES, Mailgun, Postmark, log, …) with no FluxFiles mail
 * settings. The From address is the app's `mail.from.address` unless FLUXFILES_MAIL_FROM is
 * set; the display name is the notice's own (`notify_from_name`), else FLUXFILES_MAIL_FROM_NAME,
 * else the app's `mail.from.name`.
 *
 * Only instantiated when the installed core ships the mail layer (see the controller's
 * mailer()), so the class never loads against an older core.
 */
final class LaravelMailTransport implements MailTransportInterface
{
    public function __construct(private readonly ?string $mailer = null)
    {
    }

    public function name(): string
    {
        return 'laravel_mail';
    }

    public function send(MailMessage $m): void
    {
        $fromAddress = MailMessage::validAddress((string) ($_ENV['FLUXFILES_MAIL_FROM'] ?? ''))
            ?? MailMessage::validAddress((string) (function_exists('config') ? config('mail.from.address', '') : ''));
        $fromName = $m->fromName;
        foreach ([(string) ($_ENV['FLUXFILES_MAIL_FROM_NAME'] ?? ''), (string) (function_exists('config') ? config('mail.from.name', '') : '')] as $fallback) {
            if ($fromName === '') {
                $fromName = trim(preg_replace('/[\r\n]+/', ' ', $fallback));
            }
        }
        try {
            $mail = $this->mailer !== null
                ? \Illuminate\Support\Facades\Mail::mailer($this->mailer)
                : \Illuminate\Support\Facades\Mail::getFacadeRoot();
            $mail->raw($m->text, static function ($message) use ($m, $fromAddress, $fromName): void {
                $message->to($m->to)->subject($m->subject);
                if ($fromAddress !== null) {
                    $message->from($fromAddress, $fromName !== '' ? $fromName : null);
                }
                if ($m->replyTo !== '') {
                    $message->replyTo($m->replyTo);
                }
                if (method_exists($message, 'getSymfonyMessage')) {
                    $headers = $message->getSymfonyMessage()->getHeaders();
                    foreach (MailMessage::AUTO_HEADERS as $name => $value) {
                        $headers->addTextHeader($name, $value);
                    }
                }
            });
        } catch (\Throwable $e) {
            throw MailException::scrubbed('laravel_mail: ' . (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage());
        }
    }
}
