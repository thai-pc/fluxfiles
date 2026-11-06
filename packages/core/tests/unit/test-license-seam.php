<?php

/**
 * Licence seam: LicenseCheckInterface, NoLicense, ModuleRegistry::license() /
 * setLicenseResolver(), and the Log sink. The default must behave exactly like the old
 * `LicenseManager::fromEnv()` call sites; an override is how a host (the WordPress
 * add-on) supplies a licence that does not live in $_ENV.
 *
 * Usage: php tests/unit/test-license-seam.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\ComplianceScorecard;
use FluxFiles\LicenseCheckInterface;
use FluxFiles\LicenseManager;
use FluxFiles\Log;
use FluxFiles\ModuleInterface;
use FluxFiles\ModuleRegistry;
use FluxFiles\NoLicense;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** A licence that entitles a fixed module list, status configurable. */
final class FakeLicense implements LicenseCheckInterface {
    public function __construct(private array $modules, private string $status = 'active') {}
    public function licensed(string $module): bool { return in_array($module, $this->modules, true); }
    public function status(): string { return $this->status; }
    public function info(): array { return ['edition' => 'fake', 'status' => $this->status, 'modules' => $this->modules]; }
}

final class SeamModule implements ModuleInterface {
    public static function id(): string { return 'seam'; }
    public static function claim(): string { return ''; }
}

echo "\n{$cyan}══ Licence seam ══{$reset}\n\n";

ModuleRegistry::setLicenseResolver(null);
$savedKey = $_ENV['FLUXFILES_LICENSE_KEY'] ?? null;
unset($_ENV['FLUXFILES_LICENSE_KEY']);

test('LicenseManager implements the interface; NoLicense is always free and entitles nothing', function () {
    assertTrue(new LicenseManager(null) instanceof LicenseCheckInterface);
    $n = new NoLicense();
    assertTrue($n instanceof LicenseCheckInterface);
    assertEqual(false, $n->licensed('share'));
    assertEqual(false, $n->licensed(''));
    assertEqual('free', $n->status());
});

test('NoLicense::info() has exactly the keys of LicenseManager::info() with empty values', function () {
    $real = (new LicenseManager(null))->info();
    $none = (new NoLicense())->info();
    assertEqual(array_keys($real), array_keys($none), 'same keys, same order');
    assertEqual($real, $none, 'and the same values as an unlicensed LicenseManager');
});

test('default resolver = LicenseManager::fromEnv(): free without a key', function () {
    $l = ModuleRegistry::license();
    assertTrue($l instanceof LicenseManager, get_class($l));
    assertEqual('free', $l->status());
    assertEqual(false, $l->licensed('share'));
});

test('default resolver reads the env per call (not cached)', function () {
    $_ENV['FLUXFILES_LICENSE_KEY'] = 'not-a-real-key';
    $a = ModuleRegistry::license();
    assertEqual('free', $a->status(), 'a malformed key is the free edition, never an error');
    unset($_ENV['FLUXFILES_LICENSE_KEY']);
    assertTrue(ModuleRegistry::license() !== $a, 'a fresh instance each call');
});

test('setLicenseResolver overrides the default; null restores it', function () {
    ModuleRegistry::setLicenseResolver(fn () => new FakeLicense(['share']));
    assertTrue(ModuleRegistry::license()->licensed('share'));
    assertEqual('active', ModuleRegistry::license()->status());
    ModuleRegistry::setLicenseResolver(null);
    assertTrue(ModuleRegistry::license() instanceof LicenseManager);
});

test('a resolver returning null falls back to the default (no opinion)', function () {
    ModuleRegistry::setLicenseResolver(fn () => null);
    assertTrue(ModuleRegistry::license() instanceof LicenseManager);
    ModuleRegistry::setLicenseResolver(fn () => 'garbage');
    assertTrue(ModuleRegistry::license() instanceof LicenseManager, 'a non-licence value is ignored too');
    ModuleRegistry::setLicenseResolver(null);
});

test('the gate takes any LicenseCheckInterface: NoLicense → 402, FakeLicense → module, expired status → license_expired', function () {
    ModuleRegistry::register('seam', SeamModule::class);
    try {
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u']);
        try { ModuleRegistry::require('seam', new NoLicense(), $claims); throw new \RuntimeException('no throw'); }
        catch (ApiException $e) { assertEqual(402, $e->getHttpCode()); assertEqual('license_required', $e->getErrorCode()); }

        assertTrue(ModuleRegistry::require('seam', new FakeLicense(['seam']), $claims) instanceof SeamModule);
        assertTrue(ModuleRegistry::requireServer('seam', new FakeLicense(['seam'])) instanceof SeamModule);

        try { ModuleRegistry::requireServer('seam', new FakeLicense([], 'expired')); throw new \RuntimeException('no throw'); }
        catch (ApiException $e) { assertEqual('license_expired', $e->getErrorCode()); }
    } finally {
        ModuleRegistry::reset();
    }
});

test('ComplianceScorecard::build accepts a NoLicense (every paid row locked)', function () {
    $r = ComplianceScorecard::build(Claims::fromJwtPayload((object) ['sub' => 'u']), new NoLicense());
    assertEqual(0, $r['summary']['available_count']);
});

test('classFor: null for an unknown or uninstalled module, the class for an installed one', function () {
    assertEqual(null, ModuleRegistry::classFor('nope'));
    ModuleRegistry::register('seam', SeamModule::class);
    try { assertEqual(SeamModule::class, ModuleRegistry::classFor('seam')); }
    finally { ModuleRegistry::reset(); }
});

echo "\n{$cyan}══ Log sink ══{$reset}\n\n";

test('Log::warn goes to the sink when one is set, and to error_log() by default', function () {
    $got = [];
    Log::setSink(function (string $m) use (&$got) { $got[] = $m; });
    Log::warn('one');
    assertEqual(['one'], $got);
    Log::setSink(null);

    $tmp = tempnam(sys_get_temp_dir(), 'fflog');
    $prev = ini_set('error_log', $tmp);
    Log::warn('to-error-log');
    ini_set('error_log', $prev === false ? '' : $prev);
    assertTrue(str_contains((string) file_get_contents($tmp), 'to-error-log'));
    @unlink($tmp);
});

test('a throwing sink never surfaces', function () {
    Log::setSink(function () { throw new \RuntimeException('boom'); });
    Log::warn('x');
    Log::setSink(null);
    assertTrue(true);
});

test('shipped core files no longer call error_log() directly (outside index.php and Log)', function () {
    $offenders = [];
    foreach (glob(__DIR__ . '/../../api/{,Db/}*.php', GLOB_BRACE) as $f) {
        if (in_array(basename($f), ['index.php', 'Log.php'], true)) { continue; }
        if (preg_match('/^\s*(?!\*|\/\/)[^\n]*[^\w>:]error_log\(/m', (string) file_get_contents($f), $m)
            && !preg_match('/^\s*(\*|\/\/|\/\*)/', $m[0])) {
            $offenders[] = basename($f);
        }
    }
    assertEqual([], $offenders);
});

if ($savedKey !== null) { $_ENV['FLUXFILES_LICENSE_KEY'] = $savedKey; }
echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
