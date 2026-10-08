<?php

/**
 * Reserved-namespace rule — FileManager::isReservedKey() is the ONE
 * implementation of "is this path FluxFiles-internal", shared by
 * assertNotSystem()/isReservedSystemPath(), isSystemKey() (zip),
 * StorageMetadataHandler::isReservedPath() (folder index) and QuotaManager
 * (usage/file-count exclusion).
 *
 * The regression this locks in: the rule used to be written out four separate
 * times, and three of those copies compared case-SENSITIVELY. On a
 * case-insensitive filesystem (APFS by default, Windows, most SMB/NFS mounts)
 * `_FLUXFILES/trash.json` sailed past assertNotSystem() and then landed on the
 * real `_fluxfiles/trash.json` — enough to poison the search index + hash
 * dedup, forge audit lines, hijack a sidecar's `uploaded_by` (which
 * assertOwner trusts) or plant a manifest whose `original_key` becomes an
 * attacker-chosen move() source. The `.meta.json` arm was already folded; the
 * directory arms were not.
 *
 * Usage: php tests/unit/test-reserved-path.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function rpEnv(): array {
    $root = sys_get_temp_dir() . '/fluxfiles-rp-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    return [new FileManager($dm, $claims, $meta), $meta, $dm, $root];
}

echo "\n{$cyan}══ Reserved-namespace rule (case-insensitive, one implementation) ══{$reset}\n\n";

// The exact spellings that slipped through before the fold.
$hostile = [
    '_FLUXFILES/x',
    '_FluxFiles/x',
    'a/_VARIANTS/x',
    '_FLUXFILES/trash.json',
    '_Fluxfiles/index.json',
    'users/42/_FLUXFILES/audit.jsonl',
    '_VARIANTS',
    '_FLUXFILES',
    'a/_Variants',
];
$benign = [
    'photo.png',
    'a/b/photo.png',
    '_fluxfilesy/x',            // longer segment — not the reserved name
    'my_fluxfiles/x',           // reserved name only as a SUFFIX of the segment
    'variants/x',               // no leading underscore
    '_variantsx/x',
];

test('isReservedKey: every case variant of _fluxfiles/_variants is reserved', function () use ($hostile) {
    foreach ($hostile as $key) {
        assertTrue(FileManager::isReservedKey($key), "must be reserved: {$key}");
    }
});

test('isReservedKey: look-alike segments are NOT reserved (no over-blocking)', function () use ($benign) {
    foreach ($benign as $key) {
        assertTrue(!FileManager::isReservedKey($key), "must be allowed: {$key}");
    }
});

test('isReservedSystemPath agrees, and still folds the .meta.json arm', function () use ($hostile) {
    [$fm] = rpEnv();
    foreach ($hostile as $key) {
        assertTrue($fm->isReservedSystemPath($key), "isReservedSystemPath: {$key}");
    }
    assertTrue($fm->isReservedSystemPath('a.txt.META.JSON'), 'legacy sidecar shape stays case-folded');
});

// The end-to-end consequence: a write to the mixed-case spelling must 403
// rather than resolve onto the real bookkeeping file.
test('uploading into _FLUXFILES/ is refused with 403 system_path', function () {
    [$fm, , , $root] = rpEnv();
    $tmp = tempnam(sys_get_temp_dir(), 'rp');
    file_put_contents($tmp, '{"forged":true}');
    try {
        $fm->upload('local', '_FLUXFILES', ['name' => 'trash.json', 'size' => filesize($tmp), 'tmp_name' => $tmp], true);
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === 'system_path', 'got ' . (string) $e->getErrorCode());
    } finally {
        @unlink($tmp);
    }
    assertTrue(!is_file($root . '/_FLUXFILES/trash.json'), 'nothing written under the mixed-case spelling');
    assertTrue(!is_file($root . '/_fluxfiles/trash.json'), 'and nothing landed on the real bookkeeping file');
});

test('mkdir of a mixed-case reserved folder is refused too', function () {
    [$fm] = rpEnv();
    foreach (['_FLUXFILES', '_Variants', 'a/_VARIANTS'] as $dir) {
        try {
            $fm->mkdir('local', $dir);
            throw new \RuntimeException("expected 403 for mkdir {$dir}");
        } catch (ApiException $e) {
            assertTrue($e->getErrorCode() === 'system_path', "mkdir {$dir}: got " . (string) $e->getErrorCode());
        }
    }
});

// QuotaManager and the folder index call the same helper, so a file that
// somehow pre-exists under the mixed-case spelling is excluded consistently
// instead of counting for one subsystem and not the other.
test('QuotaManager excludes mixed-case reserved paths from usage/count', function () {
    [, , $dm, $root] = rpEnv();
    @mkdir($root . '/_FLUXFILES', 0777, true);
    file_put_contents($root . '/_FLUXFILES/index.json', str_repeat('x', 1000));
    file_put_contents($root . '/real.txt', str_repeat('y', 10));

    $q = new QuotaManager($dm);
    assertTrue($q->getFileCount('local', '') === 1, 'only the real file counts');
    $b = $q->getUsageBreakdown('local', '', 10, 1);
    assertTrue($b['total_size'] === 10, 'reserved bytes excluded from the user-visible total, got ' . $b['total_size']);
    assertTrue($b['raw_total'] >= 1010, 'raw_total still includes internal bytes');
});

// N1: a write-scoped token on an SFTP disk must not be able to overwrite
// `.git/config` — the root cause every GitDeploy config-audit bypass (B2/B3/B4)
// is downstream of. Scoped to SFTP only (where git-deploy applies); `.gitignore`/
// `.gitattributes` are SIBLING files at the repo root, not a `.git` segment, and
// must stay writable. No real SFTP connection is made: assertNotSystem() throws
// before FileManager ever reaches the Flysystem adapter.
function rpSftpEnv(): array {
    $root = sys_get_temp_dir() . '/fluxfiles-rp-sftp-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['sftp' => [
        'driver' => 'sftp', 'host' => '127.0.0.1', 'port' => 22,
        'username' => 'nobody', 'password' => 'nobody', 'root' => $root,
    ]]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['sftp'], '', 50, null, 0, false);
    return [new FileManager($dm, $claims, $meta), $meta, $dm, $root];
}

test('N1: writing into .git/ on an SFTP disk is refused (403 git_internal_path)', function () {
    [$fm] = rpSftpEnv();
    $tmp = tempnam(sys_get_temp_dir(), 'rp');
    file_put_contents($tmp, 'evil');
    try {
        // assertNotSystem() throws before the upload pipeline ever touches the
        // (unreachable, fake-credentialed) SFTP adapter — so this is safe to
        // call directly without a live server.
        $fm->upload('sftp', '.git', ['name' => 'config', 'size' => filesize($tmp), 'tmp_name' => $tmp], true);
        throw new \RuntimeException('expected 403 git_internal_path');
    } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === 'git_internal_path', 'got ' . (string) $e->getErrorCode());
    } finally {
        @unlink($tmp);
    }
});

test('N1: mkdir of .git/hooks on an SFTP disk is refused too', function () {
    [$fm] = rpSftpEnv();
    try {
        $fm->mkdir('sftp', '.git/hooks');
        throw new \RuntimeException('expected 403 git_internal_path');
    } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === 'git_internal_path', 'got ' . (string) $e->getErrorCode());
    }
});

test('N1: the same .git path on a LOCAL disk is NOT blocked by the git-internal rule (SFTP-scoped, not blanket)', function () {
    [$fm] = rpEnv();
    // assertNotSystem()'s git check only fires when the disk's driver is sftp —
    // on local/S3/R2 a `.git` segment is ordinary user content. Confirmed via
    // the generic path-validation helper so no real write is needed either way.
    $scoped = $fm->validateUserPath('.git/config');
    assertTrue($scoped === '.git/config', 'local disk: .git/config is not treated as reserved, got ' . $scoped);
});

test('N1: .gitignore / .gitattributes (sibling files, not a .git/ segment) stay writable on SFTP', function () {
    [$fm] = rpSftpEnv();
    foreach (['.gitignore', '.gitattributes', 'project/.gitignore'] as $p) {
        // assertCanModifyScopedPath() runs the exact same assertNotSystem($path,
        // $disk) guard upload()/putContent()/etc. use, with no fs call reached
        // first (ownerOnly is off here) — so this proves the guard itself lets
        // the path through, without needing a live SFTP server.
        $scoped = $fm->assertCanModifyScopedPath('sftp', $p);
        assertTrue($scoped === $p, "{$p} must stay writable, got {$scoped}");
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
