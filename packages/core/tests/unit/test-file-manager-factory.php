<?php

/**
 * FileManagerFactory: the one place FileManager hooks are wired. Guards the
 * load-bearing rule that virus/DLP are wired ONLY on the claim and fail closed
 * (the module gate runs inside the callback, never at wiring time).
 *
 * Usage: php tests/unit/test-file-manager-factory.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\FileManagerFactory;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$root = sys_get_temp_dir() . '/ff-fmf-' . bin2hex(random_bytes(4));
mkdir($root, 0755, true);
register_shutdown_function(static function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); });
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
$repo = new StorageMetadataHandler($dm);

function prop(FileManager $fm, string $name) {
    $r = new ReflectionProperty(FileManager::class, $name);
    $r->setAccessible(true);
    return $r->getValue($fm);
}
function fresh(Claims $c, DiskManager $dm, $repo): FileManager { return new FileManager($dm, $c, $repo); }
function claims(): Claims { return new Claims('u', ['read', 'write'], ['local'], '', 10, null, 0); }

echo "\n{$cyan}══ FileManagerFactory ══{$reset}\n\n";

test('always wires quota + stream secret; returns the same FileManager', function () use ($dm, $repo) {
    $fm = fresh(claims(), $dm, $repo);
    $out = FileManagerFactory::wire($fm, claims(), $dm, $repo, str_repeat('k', 40));
    assertTrue($out === $fm);
    assertTrue(prop($fm, 'quotaManager') instanceof \FluxFiles\QuotaManager);
    assertEqual(str_repeat('k', 40), prop($fm, 'streamSecret'));
});

test('a plain token gets no scanner, optimizer or keeper hooks', function () use ($dm, $repo) {
    $c = claims();
    $fm = FileManagerFactory::wire(fresh($c, $dm, $repo), $c, $dm, $repo, str_repeat('k', 40));
    foreach (['virusScanner', 'dlpScanner', 'uploadOptimizer', 'versionKeeper', 'aiTagger'] as $p) {
        assertEqual(null, prop($fm, $p), $p);
    }
});

test('auto_optimize wires the optimizer; an injected AI tagger is set, none leaves it null', function () use ($dm, $repo) {
    $c = claims();
    $c->autoOptimize = true;
    $tagger = new \FluxFiles\AiTagger('openai', 'k');
    $fm = FileManagerFactory::wire(fresh($c, $dm, $repo), $c, $dm, $repo, str_repeat('k', 40), $tagger);
    assertTrue(is_callable(prop($fm, 'uploadOptimizer')));
    assertTrue(prop($fm, 'aiTagger') === $tagger);
});

test('virus/DLP are wired on the claim and the module gate is NOT evaluated at wiring time', function () use ($dm, $repo) {
    $c = claims();
    $c->allowVirusScan = true;
    $c->allowDlpScan = true;
    // Wiring must succeed whatever is installed/licensed — reads for this tenant must keep working.
    $fm = FileManagerFactory::wire(fresh($c, $dm, $repo), $c, $dm, $repo, str_repeat('k', 40));
    assertTrue(is_callable(prop($fm, 'virusScanner')), 'virus scanner wired');
    assertTrue(is_callable(prop($fm, 'dlpScanner')), 'dlp scanner wired');
});

test('virus/DLP callbacks FAIL CLOSED: a scan that cannot run is an ApiException, never a clean verdict', function () use ($dm, $repo, $root) {
    $c = claims();
    $c->allowVirusScan = true;
    $c->allowDlpScan = true;
    $fm = FileManagerFactory::wire(fresh($c, $dm, $repo), $c, $dm, $repo, str_repeat('k', 40));
    file_put_contents($root . '/probe.txt', 'x');
    foreach (['virusScanner' => [$root . '/probe.txt'], 'dlpScanner' => [$root . '/probe.txt', 'probe.txt']] as $hook => $args) {
        try {
            $verdict = (prop($fm, $hook))(...$args);
        } catch (ApiException $e) {
            assertTrue(in_array($e->getHttpCode(), [501, 402, 403, 502, 422, 500], true), "{$hook}: {$e->getHttpCode()}");
            continue;
        } catch (\Throwable $e) {
            continue; // an engine fault is also a refusal
        }
        assertTrue(is_array($verdict) && (($verdict['clean'] ?? null) !== true || ModuleInstalled::for($hook)), "{$hook} returned a clean verdict without a module");
    }
});

/** A "clean" verdict is only legitimate when the paid module is really installed. */
final class ModuleInstalled
{
    public static function for(string $hook): bool
    {
        return \FluxFiles\ModuleRegistry::installed($hook === 'virusScanner' ? 'virus' : 'dlp');
    }
}

test('webhookDispatcher: null unless claim + url (+ installed module); never throws', function () {
    $c = claims();
    assertEqual(null, FileManagerFactory::webhookDispatcher($c, str_repeat('k', 40)));
    $c->allowWebhooks = true;
    assertEqual(null, FileManagerFactory::webhookDispatcher($c, str_repeat('k', 40)), 'no url');
    $c->webhookUrl = 'https://example.com/hook';
    $d = FileManagerFactory::webhookDispatcher($c, str_repeat('k', 40));
    // With the module absent/unlicensed it stays null; present, it is a callable.
    assertTrue($d === null || is_callable($d));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
