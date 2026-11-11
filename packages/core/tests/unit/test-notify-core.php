<?php

/**
 * Core side of e-mail notices (docs/design/INTAKE-SHARE-NOTIFICATIONS-DESIGN.md §4–§5):
 * the 10 `notify_*` claims, NotifyEndpoint's test/run/mute gates against fake modules,
 * PublicContext::mailer() precedence, I18n::forLocale(), and the audit route→action map.
 *
 * Usage: php tests/unit/test-notify-core.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\AuditPolicy;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\I18n;
use FluxFiles\LicenseCheckInterface;
use FluxFiles\Log;
use FluxFiles\Mail\MailException;
use FluxFiles\Mail\MailMessage;
use FluxFiles\Mail\MailTransportInterface;
use FluxFiles\ModuleInterface;
use FluxFiles\ModuleRegistry;
use FluxFiles\NoLicense;
use FluxFiles\NotifyEndpoint;
use FluxFiles\NotifyProviderInterface;
use FluxFiles\PublicContext;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    try { $f(); echo "  \033[32mPASS\033[0m {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  \033[31mFAIL\033[0m {$n}: " . get_class($e) . ': ' . $e->getMessage() . "\n"; $failed++; }
    finally { ModuleRegistry::reset(); }
}
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new \RuntimeException($m); }
function eq($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function apiErr(callable $f): ApiException {
    try { $f(); } catch (ApiException $e) { return $e; }
    throw new \RuntimeException('expected an ApiException');
}

Log::setSink(static function (string $m): void {});

final class AllLicensed implements LicenseCheckInterface
{
    public function licensed(string $module): bool { return true; }
    public function status(): string { return 'active'; }
    public function info(): array { return []; }
}
final class BrokenMailer implements MailTransportInterface
{
    public function send(MailMessage $m): void { throw new \RuntimeException('raw secret-ish detail pw=hunter2'); }
    public function name(): string { return 'broken'; }
}
final class CaptureMailer implements MailTransportInterface
{
    /** @var list<MailMessage> */
    public array $sent = [];
    public function __construct(private bool $fail = false) {}
    public function send(MailMessage $m): void { if ($this->fail) { throw new MailException('smtp: 550 mailbox unavailable'); } $this->sent[] = $m; }
    public function name(): string { return 'capture'; }
}
final class FakeIntake implements ModuleInterface, NotifyProviderInterface
{
    public static array $runs = [];
    public static function id(): string { return 'intake'; }
    public static function claim(): string { return 'allow_intake'; }
    public function runNotify(DiskManager $dm, \FluxFiles\Claims $c, string $disk, ?MailTransportInterface $m, bool $dry, int $max, string $secret): array
    {
        self::$runs[] = compact('disk', 'dry', 'max', 'secret') + ['mailer' => $m !== null];
        return ['sent' => $dry ? 0 : 2, 'coalesced' => 1, 'rate_limited' => 0, 'truncated' => false,
            'items' => [['jti' => 'abcdefgh1', 'event' => 'intake_expiring', 'result' => $dry ? 'would_send' : 'sent']]];
    }
    public function mute(DiskManager $dm, \FluxFiles\Claims $c, string $disk, string $jti, bool $muted): array { return ['jti' => $jti, 'muted' => $muted]; }
}
/** A share module that predates NotifyProviderInterface. */
final class OldShare implements ModuleInterface
{
    public static function id(): string { return 'share'; }
    public static function claim(): string { return 'allow_share'; }
}

$claims = static function (array $extra = []): Claims {
    $p = (object) array_merge(['sub' => 'u1', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => ''], $extra);
    return Claims::fromJwtPayload($p, str_repeat('k', 32));
};
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir()]]);

echo "\n\033[36m══ notify_* claims ══\033[0m\n";

test('defaults: notices off, window 600, names on, receipt off', function () use ($claims) {
    $c = $claims();
    eq([], $c->notifyTo); eq([], $c->notifyEvents); eq('', $c->notifyLocale);
    eq(600, $c->notifyBatchWindow); eq(true, $c->notifyIncludeNames); eq(false, $c->intakeNotifyReceipt);
});
test('notify_to: array or comma string, validated, lower-cased, deduped, capped at 5', function () use ($claims) {
    eq(['a@x.io', 'b@x.io'], $claims(['notify_to' => 'A@x.io, b@x.io ,a@x.io,not-an-email'])->notifyTo);
    eq(5, count($claims(['notify_to' => array_map(fn ($i) => "u{$i}@x.io", range(1, 9))])->notifyTo));
    eq([], $claims(['notify_to' => ["a@x.io\r\nBcc: v@e.io"]])->notifyTo);
    eq([], $claims(['notify_to' => 42])->notifyTo);
});
test('domains, events, locale, from name, reply-to, app url, window, flags', function () use ($claims) {
    $c = $claims([
        'notify_recipient_domains' => ['@Acme.com', 'bad domain', 'sub.acme.co.uk', 'localhost'],
        'notify_events' => 'intake_received, SHARE_EXPIRED, bogus',
        'notify_locale' => 'DE',
        'notify_from_name' => "Acme\r\nBcc: x",
        'notify_reply_to' => 'Help@Acme.com',
        'notify_app_url' => 'https://app.acme.com/files?path={path}',
        'notify_batch_window' => 5,
        'notify_include_names' => false,
        'intake_notify_receipt' => true,
    ]);
    eq(['acme.com', 'sub.acme.co.uk'], $c->notifyRecipientDomains);
    eq(['intake_received', 'share_expired'], $c->notifyEvents);
    eq('de', $c->notifyLocale);
    eq('Acme Bcc: x', $c->notifyFromName);
    eq('help@acme.com', $c->notifyReplyTo);
    eq('https://app.acme.com/files?path={path}', $c->notifyAppUrl);
    eq(60, $c->notifyBatchWindow);
    eq(false, $c->notifyIncludeNames);
    eq(true, $c->intakeNotifyReceipt);
    eq(3600, $claims(['notify_batch_window' => 99999])->notifyBatchWindow);
    eq('', $claims(['notify_locale' => 'xx'])->notifyLocale);
    eq('', $claims(['notify_app_url' => 'javascript:alert(1)'])->notifyAppUrl);
    eq('', $claims(['notify_app_url' => 'https://a.io/x y'])->notifyAppUrl);
    eq('', $claims(['notify_reply_to' => 'nope'])->notifyReplyTo);
});

echo "\n\033[36m══ /notify/test ══\033[0m\n";

test('gate: no intake/share module → 501 from the first', function () use ($claims) {
    $e = apiErr(fn () => NotifyEndpoint::test($claims(['notify_to' => 'a@x.io', 'allow_intake' => true]), new CaptureMailer(), [], new AllLicensed()));
    eq('module_not_installed', $e->getErrorCode());
});
test('gate: installed but unlicensed → 402; licensed but claim off → 403', function () use ($claims) {
    ModuleRegistry::register('intake', FakeIntake::class);
    eq('license_required', apiErr(fn () => NotifyEndpoint::test($claims(['notify_to' => 'a@x.io', 'allow_intake' => true]), new CaptureMailer(), [], new NoLicense()))->getErrorCode());
    eq('allow_intake_forbidden', apiErr(fn () => NotifyEndpoint::test($claims(['notify_to' => 'a@x.io']), new CaptureMailer(), [], new AllLicensed()))->getErrorCode());
});
test('not configured: empty notify_to or no transport → 400 (reason surfaced)', function () use ($claims) {
    ModuleRegistry::register('intake', FakeIntake::class);
    eq('notify_not_configured', apiErr(fn () => NotifyEndpoint::test($claims(['allow_intake' => true]), new CaptureMailer(), [], new AllLicensed()))->getErrorCode());
    $e = apiErr(fn () => NotifyEndpoint::test($claims(['allow_intake' => true, 'notify_to' => 'a@x.io']), null, [], new AllLicensed(), 'FLUXFILES_MAIL_FROM is missing'));
    eq('notify_not_configured', $e->getErrorCode());
    ok(str_contains($e->getMessage(), 'FLUXFILES_MAIL_FROM'), $e->getMessage());
});
test('sends to notify_to[0] by default, in the claim locale; `to` must be on the list', function () use ($claims) {
    ModuleRegistry::register('intake', FakeIntake::class);
    $c = $claims(['allow_intake' => true, 'notify_to' => ['ops@acme.com', 'boss@acme.com'], 'notify_locale' => 'fr', 'notify_from_name' => 'Acme']);
    $m = new CaptureMailer();
    eq(['sent' => true, 'transport' => 'capture', 'error' => null], NotifyEndpoint::test($c, $m, [], new AllLicensed()));
    eq(['ops@acme.com'], $m->sent[0]->to);
    eq(I18n::forLocale('fr')->t('notify.test_subject'), $m->sent[0]->subject);
    ok(str_contains($m->sent[0]->text, 'Acme'), 'from name in body');
    NotifyEndpoint::test($c, $m, ['to' => 'Boss@Acme.com'], new AllLicensed());
    eq(['boss@acme.com'], $m->sent[1]->to);
    eq('notify_recipient_denied', apiErr(fn () => NotifyEndpoint::test($c, $m, ['to' => 'victim@evil.io'], new AllLicensed()))->getErrorCode());
    eq('notify_invalid_address', apiErr(fn () => NotifyEndpoint::test($c, $m, ['to' => "x\r\n@y"], new AllLicensed()))->getErrorCode());
});
test('a provider failure is reported, not thrown', function () use ($claims) {
    ModuleRegistry::register('share', FakeIntake::class);   // any notify-capable module behind the share id
    ModuleRegistry::register('share', OldShare::class);
    $r = NotifyEndpoint::test($claims(['allow_share' => true, 'notify_to' => 'a@x.io']), new CaptureMailer(true), [], new AllLicensed());
    eq(false, $r['sent']);
    ok(str_contains((string) $r['error'], '550'), (string) $r['error']);
    // A transport that throws something other than MailException: a report, not a 500, and its raw text never surfaces.
    $r2 = NotifyEndpoint::test($claims(['allow_share' => true, 'notify_to' => 'a@x.io']), new BrokenMailer(), [], new AllLicensed());
    eq(false, $r2['sent']);
    ok(!str_contains((string) $r2['error'], 'hunter2'), (string) $r2['error']);
});

echo "\n\033[36m══ /notify/run + /notify/mute ══\033[0m\n";

test('run: needs read + an allowed disk', function () use ($claims, $dm) {
    ModuleRegistry::register('intake', FakeIntake::class);
    eq('forbidden', apiErr(fn () => NotifyEndpoint::run($dm, $claims(['perms' => ['write'], 'allow_intake' => true]), new CaptureMailer(), [], new AllLicensed()))->getErrorCode());
    eq('disk_not_allowed', apiErr(fn () => NotifyEndpoint::run($dm, $claims(['allow_intake' => true]), new CaptureMailer(), ['disk' => 's3'], new AllLicensed()))->getErrorCode());
});
test('run: merges modules, reports an old module as skipped, caps max_emails at 200', function () use ($claims, $dm) {
    ModuleRegistry::register('intake', FakeIntake::class);
    ModuleRegistry::register('share', OldShare::class);
    FakeIntake::$runs = [];
    $r = NotifyEndpoint::run($dm, $claims(['allow_intake' => true, 'allow_share' => true]), new CaptureMailer(), ['max_emails' => 5000], new AllLicensed(), 'the-host-secret');
    eq(false, $r['dry_run']); eq(2, $r['sent']); eq(1, $r['coalesced']);
    eq([['module' => 'share', 'reason' => 'module_update_required']], $r['skipped']);
    eq('intake', $r['items'][0]['module']);
    eq(200, FakeIntake::$runs[0]['max']);
    eq('the-host-secret', FakeIntake::$runs[0]['secret'], 'the host secret reaches the module');
});
test('run: dry run works without a transport; a live run without one is 400', function () use ($claims, $dm) {
    ModuleRegistry::register('intake', FakeIntake::class);
    FakeIntake::$runs = [];
    $r = NotifyEndpoint::run($dm, $claims(['allow_intake' => true]), null, ['dry_run' => 'yes'], new AllLicensed());
    eq(true, $r['dry_run']); eq(false, FakeIntake::$runs[0]['mailer']);
    eq('notify_not_configured', apiErr(fn () => NotifyEndpoint::run($dm, $claims(['allow_intake' => true]), null, [], new AllLicensed()))->getErrorCode());
});
test('run: neither module passes its gate → the first gate error, even with no transport', function () use ($claims, $dm) {
    eq('module_not_installed', apiErr(fn () => NotifyEndpoint::run($dm, $claims(), new CaptureMailer(), [], new AllLicensed()))->getErrorCode());
    eq('module_not_installed', apiErr(fn () => NotifyEndpoint::run($dm, $claims(['allow_intake' => true]), null, [], new AllLicensed()))->getErrorCode(), 'free core: 501 before 400');
});
test('run: a dry run never hands the module a transport', function () use ($claims, $dm) {
    ModuleRegistry::register('intake', FakeIntake::class);
    FakeIntake::$runs = [];
    NotifyEndpoint::run($dm, $claims(['allow_intake' => true]), new CaptureMailer(), ['dry_run' => true], new AllLicensed());
    eq(false, FakeIntake::$runs[0]['mailer']);
});
test('mute: module id + jti validated; old module → 501 module_update_required', function () use ($claims, $dm) {
    ModuleRegistry::register('intake', FakeIntake::class);
    ModuleRegistry::register('share', OldShare::class);
    $c = $claims(['allow_intake' => true, 'allow_share' => true]);
    eq(['jti' => 'abcdefgh1', 'muted' => false], NotifyEndpoint::mute($dm, $c, ['module' => 'intake', 'jti' => 'abcdefgh1', 'muted' => 'false'], new AllLicensed()));
    eq('missing_param', apiErr(fn () => NotifyEndpoint::mute($dm, $c, ['module' => 'webhooks', 'jti' => 'abcdefgh1'], new AllLicensed()))->getErrorCode());
    eq('missing_param', apiErr(fn () => NotifyEndpoint::mute($dm, $c, ['module' => 'intake', 'jti' => '../x'], new AllLicensed()))->getErrorCode());
    eq('module_update_required', apiErr(fn () => NotifyEndpoint::mute($dm, $c, ['module' => 'share', 'jti' => 'abcdefgh1'], new AllLicensed()))->getErrorCode());
    eq('missing_param', apiErr(fn () => NotifyEndpoint::mute($dm, $c, ['module' => 'intake', 'jti' => 'abcdefgh1', 'muted' => 'maybe'], new AllLicensed()))->getErrorCode(), 'garbage muted is not "unmute"');
});

echo "\n\033[36m══ PublicContext::mailer, I18n::forLocale, audit map ══\033[0m\n";

test('mailer(): host-injected wins; else env; resolved once', function () {
    $m = new CaptureMailer();
    $ctx = new PublicContext(null, [], null, 's', new NoLicense(), true, $m);
    ok($ctx->mailer() === $m, 'injected');
    $saved = $_ENV;
    $_ENV['FLUXFILES_MAIL_TRANSPORT'] = 'log';
    try {
        $ctx2 = new PublicContext(null, [], null, 's', new NoLicense());
        ok($ctx2->mailer() instanceof \FluxFiles\Mail\LogTransport, 'env');
        unset($_ENV['FLUXFILES_MAIL_TRANSPORT']);
        ok($ctx2->mailer() instanceof \FluxFiles\Mail\LogTransport, 'memoised');
        eq(null, (new PublicContext(null, [], null, 's', new NoLicense()))->mailer(), 'off');
    } finally {
        $_ENV = $saved;
    }
});
test('forLocale: shipped locale, unknown → en, never reads ?lang=', function () {
    $_GET['lang'] = 'de';
    try {
        eq('en', I18n::forLocale('xx')->locale());
        eq('vi', I18n::forLocale('vi-VN')->locale());
        ok(I18n::forLocale('en')->t('notify.footer') !== 'notify.footer', 'template present');
    } finally {
        unset($_GET['lang']);
    }
});
test('audit: the three routes map to named actions with a safe detail', function () use ($claims) {
    eq('notify_test', AuditPolicy::resolveAction('/api/fm/notify/test'));
    eq('notify_run', AuditPolicy::resolveAction('/api/fm/notify/run'));
    eq('notify_mute', AuditPolicy::resolveAction('/api/fm/notify/mute'));
    eq('applied sent=2 coalesced=1', AuditPolicy::detail('notify_run', [], $claims(), ['dry_run' => false, 'sent' => 2, 'coalesced' => 1]));
    $d = (string) AuditPolicy::detail('notify_test', ['to' => 'boss@acme.com'], $claims(), ['sent' => true, 'transport' => 'smtp']);
    eq('sent via smtp', $d);
    eq('intake:abcdefgh1 muted', AuditPolicy::detail('notify_mute', ['module' => 'intake', 'jti' => 'abcdefgh1'], $claims()));
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
