<?php

/**
 * NotifySupport: what the Intake/Share modules share for e-mail notices — create-time
 * recipient rules, the sealed config, seal/open binding, send caps, text helpers.
 *
 * Usage: php tests/unit/test-notify-support.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\Log;
use FluxFiles\NotifySupport;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    $_ENV['FLUXFILES_STORAGE_PATH'] = sys_get_temp_dir() . '/ff-ns-' . uniqid();
    @mkdir($_ENV['FLUXFILES_STORAGE_PATH'], 0777, true);
    try { $f(); echo "  \033[32mPASS\033[0m {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  \033[31mFAIL\033[0m {$n}: " . get_class($e) . ': ' . $e->getMessage() . "\n"; $failed++; }
}
function eq($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new \RuntimeException($m); }
function code(callable $f): string { try { $f(); } catch (ApiException $e) { return (string) $e->getErrorCode(); } throw new \RuntimeException('expected an ApiException'); }

Log::setSink(static function (string $m): void {});
$claims = static fn (array $x = []): Claims => Claims::fromJwtPayload((object) array_merge(
    ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'notify_to' => ['ops@acme.test', 'boss@acme.test']], $x), str_repeat('k', 32));

test('recipients: default = all notify_to; enabled:false or no notify_to = []', function () use ($claims) {
    eq(['ops@acme.test', 'boss@acme.test'], NotifySupport::recipients($claims(), []));
    eq([], NotifySupport::recipients($claims(), ['notify' => ['enabled' => false]]));
    eq([], NotifySupport::recipients($claims(), ['notify' => ['enabled' => 'false']]));
    eq([], NotifySupport::recipients($claims(['notify_to' => []]), ['notify' => ['to' => ['ops@acme.test']]]));
});
test('recipients: body subset / allowed domain / denied / invalid', function () use ($claims) {
    $c = $claims(['notify_recipient_domains' => ['clients.test']]);
    eq(['boss@acme.test'], NotifySupport::recipients($c, ['notify' => ['to' => ['Boss@Acme.test']]]));
    eq(['jane@clients.test', 'ops@acme.test'], NotifySupport::recipients($c, ['notify' => ['to' => 'jane@clients.test, ops@acme.test']]));
    eq('notify_recipient_denied', code(fn () => NotifySupport::recipients($c, ['notify' => ['to' => ['x@evil.test']]])));
    eq('notify_recipient_denied', code(fn () => NotifySupport::recipients($c, ['notify' => ['to' => ['x@sub.clients.test']]])), 'exact domain only');
    eq('notify_invalid_address', code(fn () => NotifySupport::recipients($c, ['notify' => ['to' => ['x@[10.0.0.1]']]])));
    eq('notify_invalid_address', code(fn () => NotifySupport::recipients($c, ['notify' => ['to' => [42]]])));
});
test('sealedConfig: events intersect the module list; locale from claim, body, else en', function () use ($claims) {
    $mod = ['intake_received', 'intake_full'];
    $cfg = NotifySupport::sealedConfig($claims(['notify_events' => ['intake_full', 'share_expired']]), ['a@b.test'], [], $mod);
    eq(['intake_full'], $cfg['events']);
    eq($mod, NotifySupport::sealedConfig($claims(), ['a@b.test'], [], $mod)['events'], 'empty = all of the module');
    eq('de', NotifySupport::sealedConfig($claims(['notify_locale' => 'de']), ['a@b.test'], ['locale' => 'fr'], $mod)['locale']);
    eq('fr', NotifySupport::sealedConfig($claims(), ['a@b.test'], ['notify' => ['locale' => 'fr-FR']], $mod)['locale']);
    eq('en', NotifySupport::sealedConfig($claims(), ['a@b.test'], ['locale' => 'xx'], $mod)['locale']);
});
test('seal/open: round-trip; wrong secret, info, AAD or a flipped byte → null; re-validates addresses', function () {
    $cfg = ['v' => 1, 'to' => ['a@b.test'], 'events' => []];
    $blob = NotifySupport::seal($cfg, 'secret-1', 'info-a', '{"jti":"1"}');
    eq($cfg, NotifySupport::open($blob, 'secret-1', 'info-a', '{"jti":"1"}'));
    eq(null, NotifySupport::open($blob, 'secret-2', 'info-a', '{"jti":"1"}'));
    eq(null, NotifySupport::open($blob, 'secret-1', 'info-b', '{"jti":"1"}'));
    eq(null, NotifySupport::open($blob, 'secret-1', 'info-a', '{"jti":"2"}'));
    $raw = base64_decode($blob); $raw[20] = chr(ord($raw[20]) ^ 1);
    eq(null, NotifySupport::open(base64_encode($raw), 'secret-1', 'info-a', '{"jti":"1"}'));
    eq(null, NotifySupport::open($blob, '', 'info-a', '{"jti":"1"}'), 'no secret');
    eq(null, NotifySupport::open(NotifySupport::seal(['v' => 1, 'to' => ['x@[127.0.0.1]']], 's', 'i', 'a'), 's', 'i', 'a'), 'junk address never comes back out');
});
test('open(): a document-request seal may carry requestee + url; a client-only record has an empty `to`; junk is dropped', function () {
    $open = fn (array $c) => NotifySupport::open(NotifySupport::seal($c, 's', 'i', 'a'), 's', 'i', 'a');
    $both = $open(['v' => 1, 'to' => ['a@b.test'], 'requestee' => 'C@D.test', 'url' => 'https://x.test/p?token=t']);
    eq(['c@d.test', 'https://x.test/p?token=t'], [$both['requestee'], $both['url']]);
    $only = $open(['v' => 1, 'to' => [], 'requestee' => 'c@d.test', 'url' => 'https://x.test/p']);
    eq([], $only['to']);
    eq(null, $open(['v' => 1, 'to' => [], 'requestee' => 'c@d.test', 'url' => 'javascript:alert(1)']), 'no http(s) link and no owner: nothing to send');
    eq(null, $open(['v' => 1, 'to' => [], 'requestee' => 'x@[10.0.0.1]', 'url' => 'https://x.test/']), 'address literal');
    $stripped = $open(['v' => 1, 'to' => ['a@b.test'], 'requestee' => 'c@d.test', 'url' => 'ftp://x']);
    ok(!isset($stripped['requestee']) && !isset($stripped['url']), 'a bad link drops the requestee, the owner stays');
    eq(['v' => 1, 'to' => ['a@b.test'], 'events' => []], $open(['v' => 1, 'to' => ['a@b.test'], 'events' => []]), 'a step-1 seal comes back unchanged');
});
test('allow(): a bucket of N in a window; keys are independent', function () {
    ok(NotifySupport::allow('t:a', 2, 3600) && NotifySupport::allow('t:a', 2, 3600), 'two allowed');
    eq(false, NotifySupport::allow('t:a', 2, 3600), 'third refused');
    ok(NotifySupport::allow('t:b', 2, 3600), 'another key');
});
test('underDailyCap: per address, case-insensitive, no address in the limiter storage', function () {
    eq(['a@x.test', 'b@x.test'], NotifySupport::underDailyCap(['a@x.test', 'b@x.test'], 'owner', 1));
    eq([], NotifySupport::underDailyCap(['A@x.test', 'b@x.test'], 'owner', 1), 'both used up');
    eq(['a@x.test'], NotifySupport::underDailyCap(['a@x.test'], 'receipt', 1), 'separate kind');
    $dump = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($_ENV['FLUXFILES_STORAGE_PATH'], FilesystemIterator::SKIP_DOTS)) as $f) {
        $dump .= (string) file_get_contents($f->getPathname());
    }
    ok(!str_contains($dump, '@x.test'), 'addresses never written to rate-limit storage');
});
test('bytes / time / appLink', function () {
    eq('0 B', NotifySupport::bytes(0)); eq('999 B', NotifySupport::bytes(999)); eq('1.5 KB', NotifySupport::bytes(1536));
    eq('12 MB', NotifySupport::bytes(12 * 1024 * 1024)); eq('1.0 GB', NotifySupport::bytes(1024 ** 3));
    eq('1970-01-01 00:00 UTC', NotifySupport::time(0));
    eq('https://a.test/?p=clients%2Fjane%20doe', NotifySupport::appLink('https://a.test/?p={path}', '/clients/jane doe'));
    eq('', NotifySupport::appLink('', 'x'));
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
