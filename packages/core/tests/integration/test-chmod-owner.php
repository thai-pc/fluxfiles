<?php

/**
 * FileManager::setMode() under owner_only (security audit 2026-11, M1).
 *
 * assertOwner() skips directories and an empty path resolves to the tenant root, so
 * an owner_only user could chmod a folder full of other people's files, or the root.
 * setMode() now refuses the root and runs assertOwnsTree() on a directory target.
 * Also covers the decode-side default: owner_only without an explicit allow_chmod
 * decodes allowChmod=false.
 *
 * The SFTP connection is faked (the file tree is a local directory presented as an
 * SFTP disk), so this runs with no server.
 *
 * Usage: php tests/integration/test-chmod-owner.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEq($e, $a, string $m): void { if ($e !== $a) throw new \RuntimeException($m . ': expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function rmrf(string $d): void { exec('rm -rf ' . escapeshellarg($d)); }

/** Stands in for phpseclib's SFTP: stat() reads the local tree, chmod() records the call. */
final class FakeSftp
{
    /** @var array<int,array{int,string}> */
    public array $chmods = [];
    public function __construct(private string $root) {}
    public function stat(string $path)
    {
        $abs = $this->root . '/' . ltrim($path, '/');
        if (is_dir($abs)) { return ['type' => 2, 'mode' => 040755]; }
        if (is_file($abs)) { return ['type' => 1, 'mode' => 0100644]; }
        return false;
    }
    public function chmod(int $mode, string $path, bool $recursive): bool { $this->chmods[] = [$mode, $path]; return true; }
}

final class Disks extends DiskManager
{
    private Filesystem $fs;
    public FakeSftp $sftp;
    public function __construct(string $root)
    {
        parent::__construct(['x' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
        $this->fs = new Filesystem(new LocalFilesystemAdapter($root));
        $this->sftp = new FakeSftp($root);
    }
    public function disk(string $name): Filesystem { return $this->fs; }
    public function sftpConnection(string $name): array { return [$this->sftp, '']; }
}

/** @return array{FileManager,Disks,string,StorageMetadataHandler} */
function env(string $user, bool $ownerOnly = true): array
{
    $root = sys_get_temp_dir() . '/ff-chmod-' . bin2hex(random_bytes(4));
    mkdir($root . '/alice/docs', 0777, true);
    mkdir($root . '/mallory', 0777, true);
    file_put_contents($root . '/alice/docs/a.txt', 'a');
    file_put_contents($root . '/mallory/m.txt', 'm');
    $dm = new Disks($root);
    $m = new StorageMetadataHandler($dm);
    $m->indexFile('x', 'alice/docs/a.txt', ['uploaded_by' => 'alice']);
    $m->indexFile('x', 'mallory/m.txt', ['uploaded_by' => 'mallory']);
    $m->save('x', 'alice/docs/a.txt', ['uploaded_by' => 'alice']);
    $m->save('x', 'mallory/m.txt', ['uploaded_by' => 'mallory']);
    $c = new Claims($user, ['read', 'write', 'delete'], ['x'], '', 50, null, 0, $ownerOnly);
    return [new FileManager($dm, $c, $m), $dm, $root, $m];
}

function code(callable $fn): ?array
{
    try { $fn(); return null; } catch (ApiException $e) { return [$e->getHttpCode(), $e->getErrorCode()]; }
}

echo "\n{$cyan}══ M1: chmod under owner_only ══{$reset}\n\n";

test("owner_only: mallory chmod'ing alice's folder -> 403 owner_only, nothing changed", function () {
    [$fm, $dm, $root] = env('mallory');
    assertEq([403, 'owner_only'], code(fn () => $fm->setMode('x', 'alice', '777')), 'folder with others files');
    assertEq([403, 'owner_only'], code(fn () => $fm->setMode('x', 'alice/docs', '777')), 'nested folder');
    assertEq([], $dm->sftp->chmods, 'no chmod issued');
    rmrf($root);
});

test('owner_only: the tenant root is refused (empty, "/" and dot spellings)', function () {
    [$fm, $dm, $root] = env('mallory');
    foreach (['', '/', '.', './', '//'] as $p) {
        assertEq([403, 'chmod_root_forbidden'], code(fn () => $fm->setMode('x', $p, '777')), "path '{$p}'");
    }
    assertEq([], $dm->sftp->chmods, 'no chmod issued');
    rmrf($root);
});

test("owner_only: mallory's own folder and file still work", function () {
    [$fm, $dm, $root] = env('mallory');
    assertEq('755', $fm->setMode('x', 'mallory', '755')['mode'], 'own folder');
    assertEq('644', $fm->setMode('x', 'mallory/m.txt', '644')['mode'], 'own file');
    assertEq(2, count($dm->sftp->chmods), 'both applied');
    rmrf($root);
});

test("owner_only: alice's file is refused for mallory (file path unchanged)", function () {
    [$fm, $dm, $root] = env('mallory');
    assertEq([403, 'owner_only'], code(fn () => $fm->setMode('x', 'alice/docs/a.txt', '777')), 'file');
    rmrf($root);
});

test('without owner_only a folder chmod is not tree-checked (behaviour unchanged), root still refused', function () {
    [$fm, $dm, $root] = env('mallory', false);
    assertEq('755', $fm->setMode('x', 'alice', '755')['mode'], 'folder');
    assertEq([403, 'chmod_root_forbidden'], code(fn () => $fm->setMode('x', '', '755')), 'root');
    rmrf($root);
});

test('claim default: owner_only without allow_chmod is OFF; explicit true wins; no owner_only stays on', function () {
    assertEq(false, Claims::fromJwtPayload((object) ['owner_only' => true])->allowChmod, 'default under owner_only');
    assertEq(true, Claims::fromJwtPayload((object) ['owner_only' => true, 'allow_chmod' => true])->allowChmod, 'explicit true');
    assertEq(false, Claims::fromJwtPayload((object) ['owner_only' => true, 'allow_chmod' => false])->allowChmod, 'explicit false');
    assertEq(true, Claims::fromJwtPayload((object) [])->allowChmod, 'plain token default');
    assertEq(true, Claims::fromJwtPayload((object) ['allow_chmod' => true])->allowChmod, 'explicit on, no owner_only');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
