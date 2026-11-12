<?php

declare(strict_types=1);

namespace FluxFiles;

use FluxFiles\Mail\MailException;
use FluxFiles\Mail\MailMessage;
use FluxFiles\Mail\MailTransportInterface;

/**
 * The operator routes for e-mail notices, framework-neutral so the standalone app and
 * the Laravel / WordPress proxies run the same code. Each caller passes the transport it
 * resolved (today the env-built one everywhere; the proxies will pass their host's mail
 * stack — `wp_mail`, `Mail::raw` — once those transports ship):
 *
 *   POST /api/fm/notify/test {to?}                      → test()
 *   POST /api/fm/notify/run  {disk, dry_run?, max_emails?} → run()
 *   POST /api/fm/notify/mute {module, disk, jti, muted}  → mute()
 *
 * Gate: the `intake` or `share` module (3-layer). Notices are Pro value inside those two
 * modules, so neither route needs a Webhooks licence.
 */
final class NotifyEndpoint
{
    public const MODULES = ['intake', 'share'];

    /**
     * Send a fixed test message to `to` (must be one of the token's `notify_to`; default
     * the first). Never throws for a provider failure: `{sent:false, error}` instead, with
     * the error scrubbed of credentials, so the operator can see what is wrong.
     *
     * @param array<string,mixed> $body
     * @return array{sent:bool, transport:string, error:?string}
     */
    public static function test(Claims $claims, ?MailTransportInterface $mailer, array $body, ?LicenseCheckInterface $license = null, ?string $why = null): array
    {
        self::requireAny($claims, $license ?? ModuleRegistry::license());
        if ($claims->notifyTo === []) {
            throw new ApiException('No notify_to recipients on this token', 400, 'notify_not_configured');
        }
        if ($mailer === null) {
            throw new ApiException('E-mail is not configured on this server' . ($why !== null ? ': ' . $why : ''), 400, 'notify_not_configured');
        }
        $to = $claims->notifyTo[0];
        if (isset($body['to']) && $body['to'] !== '') {
            $want = is_string($body['to']) ? MailMessage::validAddress($body['to']) : null;
            if ($want === null) {
                throw new ApiException('Invalid e-mail address', 400, 'notify_invalid_address');
            }
            if (!in_array($want, $claims->notifyTo, true)) {
                throw new ApiException('That address is not in notify_to', 403, 'notify_recipient_denied');
            }
            $to = $want;
        }

        $i18n = I18n::forLocale(self::locale($claims, $body));
        $from = $claims->notifyFromName !== '' ? $claims->notifyFromName : 'FluxFiles';
        try {
            $mailer->send(new MailMessage(
                [$to],
                $i18n->t('notify.test_subject'),
                $i18n->t('notify.test_body', ['from' => $from]) . "\n\n-- \n" . $i18n->t('notify.footer'),
                $claims->notifyFromName
            ));
            return ['sent' => true, 'transport' => $mailer->name(), 'error' => null];
        } catch (MailException $e) {
            return ['sent' => false, 'transport' => $mailer->name(), 'error' => $e->getMessage()];
        } catch (\InvalidArgumentException $e) {
            return ['sent' => false, 'transport' => $mailer->name(), 'error' => 'invalid message'];
        } catch (\Throwable $e) {
            // A host transport that breaks the MailException contract: still a report, never
            // a 500, and never its raw message (it was not scrubbed).
            Log::warn('[fluxfiles] notify test: ' . get_class($e) . ' from the ' . $mailer->name() . ' transport');
            return ['sent' => false, 'transport' => $mailer->name(), 'error' => 'send failed (' . get_class($e) . ')'];
        }
    }

    /**
     * The sweep, over every gated module that implements {@see NotifyProviderInterface}.
     * A module that is installed + licensed + allowed but too old is reported as
     * `skipped: module_update_required` while the other still runs; when NEITHER module
     * passes its gate, the first gate error is thrown (501/402/403).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function run(DiskManager $dm, Claims $claims, ?MailTransportInterface $mailer, array $body, ?LicenseCheckInterface $license = null, #[\SensitiveParameter] string $secret = '', ?callable $audit = null): array
    {
        if (!$claims->hasPerm('read')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $disk = is_string($body['disk'] ?? null) && $body['disk'] !== '' ? $body['disk'] : 'local';
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        // Fail safe the other way round from retention: the sweep only sends to vetted,
        // sealed recipients under server-side caps, so a live run is the default.
        $raw = $body['dry_run'] ?? false;
        $dryRun = $raw === true || $raw === 1 || (is_string($raw) && in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true));
        $max = max(1, min(200, (int) ($body['max_emails'] ?? 50)));

        // Gate first: free core (no module) must answer 501/402/403, never a config error.
        $license ??= ModuleRegistry::license();
        $report = ['dry_run' => $dryRun, 'sent' => 0, 'coalesced' => 0, 'rate_limited' => 0, 'truncated' => false, 'items' => [], 'skipped' => []];
        $firstError = null;
        $gated = 0;
        /** @var array<string,NotifyProviderInterface> $modules */
        $modules = [];
        foreach (self::MODULES as $id) {
            try {
                $modules[$id] = ModuleRegistry::requireCapability($id, NotifyProviderInterface::class, $license, $claims);
                $gated++;
            } catch (ApiException $e) {
                if ($e->getErrorCode() === 'module_update_required') {
                    $report['skipped'][] = ['module' => $id, 'reason' => 'module_update_required'];
                    $gated++;   // gated in, just old: not a reason to fail the whole sweep
                } else {
                    $firstError ??= $e;
                }
            }
        }
        if ($gated === 0 && $firstError !== null) {
            throw $firstError;
        }
        if (!$dryRun && $mailer === null && $modules !== []) {
            throw new ApiException('E-mail is not configured on this server', 400, 'notify_not_configured');
        }

        foreach ($modules as $id => $module) {
            $budget = $max - $report['sent'];
            if ($budget <= 0) {
                $report['truncated'] = true;
                break;
            }
            $r = $module->runNotify($dm, $claims, $disk, $dryRun ? null : $mailer, $dryRun, $budget, $secret, $audit);
            foreach (['sent', 'coalesced', 'rate_limited'] as $k) {
                $report[$k] += (int) ($r[$k] ?? 0);
            }
            $report['truncated'] = $report['truncated'] || !empty($r['truncated']);
            foreach ((array) ($r['items'] ?? []) as $it) {
                if (is_array($it)) {
                    $report['items'][] = ['module' => $id] + $it;
                }
            }
        }
        return $report;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{jti:string, muted:bool}
     */
    public static function mute(DiskManager $dm, Claims $claims, array $body, ?LicenseCheckInterface $license = null): array
    {
        $id = is_string($body['module'] ?? null) ? $body['module'] : '';
        if (!in_array($id, self::MODULES, true)) {
            throw new ApiException('module must be intake or share', 400, 'missing_param');
        }
        $disk = is_string($body['disk'] ?? null) && $body['disk'] !== '' ? $body['disk'] : 'local';
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        $jti = is_string($body['jti'] ?? null) ? $body['jti'] : '';
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $jti) !== 1) {
            throw new ApiException('Invalid id', 400, 'missing_param');
        }
        $muted = filter_var($body['muted'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($muted === null) {
            throw new ApiException('muted must be true or false', 400, 'missing_param');
        }
        /** @var NotifyProviderInterface $module */
        $module = ModuleRegistry::requireCapability($id, NotifyProviderInterface::class, $license ?? ModuleRegistry::license(), $claims);
        return $module->mute($dm, $claims, $disk, $jti, $muted);
    }

    /** `notify_locale` claim, else the body's `locale`, else `en` — validated against the shipped set. */
    public static function locale(Claims $claims, array $body = []): string
    {
        if ($claims->notifyLocale !== '') {
            return $claims->notifyLocale;
        }
        $l = is_string($body['locale'] ?? null) ? strtolower(substr($body['locale'], 0, 2)) : '';
        return in_array($l, I18n::LOCALES, true) ? $l : 'en';
    }

    /** Throw the first gate error unless the intake or the share module passes its 3-layer gate. */
    private static function requireAny(Claims $claims, LicenseCheckInterface $license): void
    {
        $first = null;
        foreach (self::MODULES as $id) {
            try {
                ModuleRegistry::require($id, $license, $claims);
                return;
            } catch (ApiException $e) {
                $first ??= $e;
            }
        }
        if ($first !== null) {
            throw $first;
        }
    }
}
