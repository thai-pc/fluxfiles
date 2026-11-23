<?php

declare(strict_types=1);

/**
 * `import_concurrency`: how many URL imports one user may run at the same time.
 * Slots are held here with the same flock the importer takes, so the test needs
 * no network: an import that gets a slot goes on to the SSRF guard (a loopback
 * URL is refused there), one that does not answers 429 rate_limited first.
 *
 * Usage: php tests/integration/test-url-import-concurrency.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'Expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$root = sys_get_temp_dir() . '/fluxfiles-import-conc-' . uniqid();
@mkdir($root, 0777, true);
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
$meta = new StorageMetadataHandler($dm);

function importer(string $user, int $concurrency): UrlImporter
{
    global $dm, $meta;
    $claims = new Claims($user, ['read', 'write'], ['local'], '', 50, null, 0, false);
    $claims->allowUrlImport = true;
    $claims->importConcurrency = $concurrency;
    return new UrlImporter($claims, new FileManager($dm, $claims, $meta));
}

/** Error code of an import of a loopback URL (never fetched: SSRF refuses it). */
function importCode(UrlImporter $imp): string
{
    try {
        $imp->import('local', 'http://127.0.0.1/x.png');
    } catch (ApiException $e) {
        return $e->getErrorCode();
    }
    return 'no_error';
}

/** Take slot $i of $user the way a running import holds it. @return resource */
function hold(string $user, int $i)
{
    $fp = fopen(UrlImporter::slotFile($user, $i), 'c');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
        throw new \RuntimeException("could not take slot $i");
    }
    return $fp;
}
function release($fp): void { flock($fp, LOCK_UN); fclose($fp); }

$u = 'conc-' . bin2hex(random_bytes(4));

echo "{$yellow}► import_concurrency{$reset}\n";

test('a free slot: the import goes on to the SSRF guard', function () use ($u) {
    $code = importCode(importer($u . 'a', 1));
    assertTrue($code !== 'rate_limited' && $code !== 'no_error', "expected an SSRF refusal, got $code");
});

test('limit 1 with the slot taken: 429 rate_limited before any fetch', function () use ($u) {
    $h = hold($u . 'b', 0);
    try { assertEqual('rate_limited', importCode(importer($u . 'b', 1))); } finally { release($h); }
});

test('limit 2 with one slot taken: the second slot is used', function () use ($u) {
    $h = hold($u . 'c', 0);
    try { assertTrue(importCode(importer($u . 'c', 2)) !== 'rate_limited', 'second slot free'); } finally { release($h); }
});

test('limit 2 with both slots taken: 429', function () use ($u) {
    $a = hold($u . 'd', 0); $b = hold($u . 'd', 1);
    try { assertEqual('rate_limited', importCode(importer($u . 'd', 2))); } finally { release($a); release($b); }
});

test('the slot is released when the import ends, also on an error', function () use ($u) {
    importCode(importer($u . 'e', 1));            // fails at the SSRF guard
    release(hold($u . 'e', 0));                   // would throw if the importer still held it
    assertTrue(true);
});

test('another user is not limited by this user\'s slots', function () use ($u) {
    $h = hold($u . 'f', 0);
    try { assertTrue(importCode(importer($u . 'g', 1)) !== 'rate_limited', 'other user blocked'); } finally { release($h); }
});

test('claim 0: FLUXFILES_IMPORT_CONCURRENCY, else 3', function () use ($u) {
    $hs = [hold($u . 'h', 0), hold($u . 'h', 1)];
    try {
        assertTrue(importCode(importer($u . 'h', 0)) !== 'rate_limited', 'default 3: the third slot is free');
        $hs[] = hold($u . 'h', 2);
        assertEqual('rate_limited', importCode(importer($u . 'h', 0)), 'default 3: all taken');
        $_ENV['FLUXFILES_IMPORT_CONCURRENCY'] = '4';
        assertTrue(importCode(importer($u . 'h', 0)) !== 'rate_limited', 'env 4: a fourth slot');
    } finally {
        unset($_ENV['FLUXFILES_IMPORT_CONCURRENCY']);
        foreach ($hs as $h) { release($h); }
    }
});

test('the claim wins over the env; the slot file names hide the user id', function () use ($u) {
    $_ENV['FLUXFILES_IMPORT_CONCURRENCY'] = '5';
    $h = hold($u . 'i', 0);
    try { assertEqual('rate_limited', importCode(importer($u . 'i', 1))); } finally { unset($_ENV['FLUXFILES_IMPORT_CONCURRENCY']); release($h); }
    assertTrue(strpos(basename(UrlImporter::slotFile($u . 'i', 0)), $u) === false, 'user id in the file name');
});

test('import disabled: refused before a slot is taken', function () use ($u) {
    global $dm, $meta;
    $claims = new Claims($u . 'j', ['read', 'write'], ['local'], '', 50, null, 0, false);
    $claims->importConcurrency = 1;
    $h = hold($u . 'j', 0);
    try {
        (new UrlImporter($claims, new FileManager($dm, $claims, $meta)))->import('local', 'https://example.com/a.png');
        throw new \RuntimeException('no error');
    } catch (ApiException $e) {
        assertEqual('forbidden', $e->getErrorCode());
    } finally { release($h); }
});

// Only this run's slot files: another process may hold a lock on someone else's.
foreach (range('a', 'j') as $l) { for ($i = 0; $i < 5; $i++) { @unlink(UrlImporter::slotFile($u . $l, $i)); } }
echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
