<?php

/**
 * `fluxfiles notify:run` (docs/design/INTAKE-DOCUMENT-REQUESTS-DESIGN.md §11): the
 * claims NotifyScheduler builds from FLUXFILES_NOTIFY_* env, the sweep it drives through
 * NotifyEndpoint::run() against a fake module, and the CLI's usage handling.
 *
 * Usage: php tests/unit/test-notify-scheduler.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\LicenseCheckInterface;
use FluxFiles\Log;
use FluxFiles\Mail\MailMessage;
use FluxFiles\Mail\MailTransportInterface;
use FluxFiles\ModuleInterface;
use FluxFiles\ModuleRegistry;
use FluxFiles\NotifyProviderInterface;
use FluxFiles\NotifyScheduler;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    try { $f(); echo "  \033[32mPASS\033[0m {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  \033[31mFAIL\033[0m {$n}: " . get_class($e) . ': ' . $e->getMessage() . "\n"; $failed++; }
    finally { ModuleRegistry::reset(); }
}
function eq($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
Log::setSink(static function (string $m): void {});

final class SchedLicensed implements LicenseCheckInterface
{
    public function licensed(string $module): bool { return true; }
    public function status(): string { return 'active'; }
    public function info(): array { return []; }
}
final class SchedMailer implements MailTransportInterface
{
    public function send(MailMessage $m): void {}
    public function name(): string { return 'capture'; }
}
final class SchedIntake implements ModuleInterface, NotifyProviderInterface
{
    public static array $seen = [];
    public static function id(): string { return 'intake'; }
    public static function claim(): string { return 'allow_intake'; }
    public function runNotify(DiskManager $dm, \FluxFiles\Claims $c, string $disk, ?MailTransportInterface $m, bool $dry, int $max, string $secret, ?callable $audit = null): array
    {
        self::$seen = ['claims' => $c, 'disk' => $disk, 'dry' => $dry, 'max' => $max, 'secret' => $secret, 'mailer' => $m !== null];
        if ($audit !== null && !$dry) { $audit($disk, 'jti1', 'event=intake_request n=1'); }
        return ['sent' => $dry ? 0 : 1, 'coalesced' => 0, 'rate_limited' => 0, 'truncated' => false, 'items' => []];
    }
    public function mute(DiskManager $dm, \FluxFiles\Claims $c, string $disk, string $jti, bool $muted): array { return []; }
}

$tmp = sys_get_temp_dir() . '/fxsched-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $tmp]]);
$secret = str_repeat('s', 32);
$env = [
    'FLUXFILES_SECRET' => $secret,
    'FLUXFILES_NOTIFY_TO' => 'Boss@Acme.com, ops@acme.com, bad',
    'FLUXFILES_NOTIFY_LOCALE' => 'de',
    'FLUXFILES_NOTIFY_FROM_NAME' => 'Acme Files',
    'FLUXFILES_NOTIFY_REPLY_TO' => 'help@acme.com',
    'FLUXFILES_NOTIFY_APP_URL' => 'https://files.acme.com/app',
];

echo "\n\033[36m══ NotifyScheduler ══\033[0m\n";

test('claims come from the FLUXFILES_NOTIFY_* env, scoped to one disk + prefix', function () use ($env, $secret) {
    $c = NotifyScheduler::claims($env, 'local', '/tenant-a/', $secret);
    eq(['boss@acme.com', 'ops@acme.com'], $c->notifyTo);
    eq('de', $c->notifyLocale); eq('Acme Files', $c->notifyFromName);
    eq('help@acme.com', $c->notifyReplyTo); eq('https://files.acme.com/app', $c->notifyAppUrl);
    eq(['local'], $c->allowedDisks); eq('tenant-a', $c->pathPrefix);
    eq(true, $c->hasPerm('read')); eq(true, $c->hasPerm('write'));
});
test('no notify env means empty claims, not a crash', function () use ($secret) {
    $c = NotifyScheduler::claims([], 'local', '', $secret);
    eq([], $c->notifyTo); eq('', $c->notifyLocale);
});
test('run: drives the module with the max cap, mailer and the host secret', function () use ($env, $dm, $secret) {
    ModuleRegistry::register('intake', SchedIntake::class);
    $r = NotifyScheduler::run($env, ['max' => 500, 'prefix' => 'p'], $dm, new SchedMailer(), null, new SchedLicensed());
    eq(1, $r['sent']);
    eq(200, SchedIntake::$seen['max']); eq($secret, SchedIntake::$seen['secret']);
    eq('p', SchedIntake::$seen['claims']->pathPrefix); eq(true, SchedIntake::$seen['mailer']);
});
test('run: dry run needs no mailer; a live run without one is notify_not_configured', function () use ($env, $dm) {
    ModuleRegistry::register('intake', SchedIntake::class);
    $r = NotifyScheduler::run($env, ['dry_run' => true], $dm, null, null, new SchedLicensed());
    eq(true, $r['dry_run']); eq(false, SchedIntake::$seen['mailer']);
    try { NotifyScheduler::run($env, [], $dm, null, null, new SchedLicensed()); throw new \RuntimeException('expected ApiException'); }
    catch (ApiException $e) { eq('notify_not_configured', $e->getErrorCode()); }
});
test('run: no module installed is a gate error, a short secret a config error', function () use ($env, $dm) {
    try { NotifyScheduler::run($env, [], $dm, new SchedMailer(), null, new SchedLicensed()); throw new \RuntimeException('expected ApiException'); }
    catch (ApiException $e) { eq('module_not_installed', $e->getErrorCode()); }
    try { NotifyScheduler::run(['FLUXFILES_SECRET' => 'short'], [], $dm, new SchedMailer()); throw new \RuntimeException('expected ApiException'); }
    catch (ApiException $e) { eq(500, $e->getHttpCode()); }
});
test('run: writes notify_sent + notify_run audit lines (no address)', function () use ($env, $dm, $tmp) {
    ModuleRegistry::register('intake', SchedIntake::class);
    NotifyScheduler::run($env, [], $dm, new SchedMailer(), null, new SchedLicensed());
    $blob = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) { $blob .= (string) file_get_contents($f->getPathname()); } }
    if (!str_contains($blob, 'notify_sent') || !str_contains($blob, 'notify_run')) throw new \RuntimeException('audit lines missing');
    if (str_contains($blob, 'acme.com')) throw new \RuntimeException('an address leaked into the audit log');
});

echo "\n\033[36m══ bin/fluxfiles notify:run ══\033[0m\n";

$bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/fluxfiles') . ' notify:run';
test('usage errors exit 1 without touching anything', function () use ($bin) {
    foreach (['--bogus', '--max=abc', '--max=0', '--disk='] as $arg) {
        exec($bin . ' ' . $arg . ' 2>&1', $out, $code);
        eq(1, $code, $arg);
    }
});

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
