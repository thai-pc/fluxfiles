<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

/**
 * The transport for e-mail notices on Laravel, shared by the proxy controller and the
 * `fluxfiles:notify-run` command so both send the same way:
 *  1. a provider configured explicitly for FluxFiles (FLUXFILES_MAIL_TRANSPORT in env);
 *  2. otherwise the application's own mailer (`Mail::raw`, config/mail.php), or the one
 *     named by FLUXFILES_LARAVEL_MAILER.
 * null on an installed core that predates the mail layer.
 */
final class MailerResolver
{
    public static function resolve(?string &$why = null): ?\FluxFiles\Mail\MailTransportInterface
    {
        $why = null;
        if (!interface_exists(\FluxFiles\Mail\MailTransportInterface::class)) {
            $why = 'this FluxFiles core has no e-mail support';
            return null;
        }
        if (trim((string) ($_ENV['FLUXFILES_MAIL_TRANSPORT'] ?? '')) !== '') {
            return \FluxFiles\Mail\MailTransportFactory::fromEnv(null, null, $why);
        }
        if (!class_exists(\Illuminate\Support\Facades\Mail::class)) {
            $why = 'the application has no mailer';
            return null;
        }
        $named = trim((string) ($_ENV['FLUXFILES_LARAVEL_MAILER'] ?? ''));
        return new LaravelMailTransport($named !== '' ? $named : null);
    }
}
