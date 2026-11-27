<?php

/**
 * UploadRootGuard (Apache .htaccess at a local disk root), FrameAncestors' safe
 * default, and the generic (no raw storage text) delete/trash error messages.
 *
 * Usage: php tests/unit/test-upload-root-guard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\FrameAncestors;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UploadRootGuard;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function tmpRoot(): string {
    $d = sys_get_temp_dir() . '/ff-urg-' . bin2hex(random_bytes(4));
    mkdir($d, 0755, true);
    return $d;
}
function rmrf(string $d): void {
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) continue;
        is_dir($f) ? rmrf($f) : @unlink($f);
    }
    @rmdir($d);
}
function localFs(string $root): Filesystem {
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    return $dm->disk('local');
}

echo "\n{$cyan}══ Upload-root guard ══{$reset}\n\n";

test('first write into a local disk root drops the guard .htaccess', function () {
    UploadRootGuard::reset();
    $root = tmpRoot();
    localFs($root)->write('tenant/a.txt', 'x');
    $h = @file_get_contents($root . '/.htaccess');
    assertTrue($h !== false, 'guard missing');
    assertTrue(str_contains($h, 'X-Content-Type-Options "nosniff"'), 'nosniff');
    assertTrue(str_contains($h, 'Content-Security-Policy "sandbox"'), 'CSP sandbox');
    assertTrue(str_contains($h, 'Content-Disposition "attachment"'), 'attachment');
    assertTrue(str_contains($h, 'svgz?') && str_contains($h, 'xhtml') && str_contains($h, 'html?') && str_contains($h, 'xml'), 'types');
    assertTrue(str_contains($h, 'Require all denied') && str_contains($h, 'phtml'), 'no php');
    rmrf($root);
});

test('an existing .htaccess is never overwritten', function () {
    UploadRootGuard::reset();
    $root = tmpRoot();
    file_put_contents($root . '/.htaccess', "# mine\n");
    localFs($root)->write('a.txt', 'x');
    assertTrue(file_get_contents($root . '/.htaccess') === "# mine\n", 'overwritten');
    rmrf($root);
});

test('a root with index.php (a site docroot) is left alone', function () {
    UploadRootGuard::reset();
    $root = tmpRoot();
    file_put_contents($root . '/index.php', '<?php');
    localFs($root)->write('a.txt', 'x');
    assertTrue(!file_exists($root . '/.htaccess'), 'guard written into a docroot');
    rmrf($root);
});

test('the root guard is hidden from listings (isGuardKey) but a subfolder .htaccess is not', function () {
    UploadRootGuard::reset();
    $root = tmpRoot();
    $cfg = ['driver' => 'local', 'root' => $root];
    localFs($root)->write('a.txt', 'x');
    assertTrue(FileManager::isGuardKey($cfg, '.htaccess'), 'root guard hidden');
    assertTrue(!FileManager::isGuardKey($cfg, 'tenant/.htaccess'), 'nested is a normal key');
    assertTrue(!FileManager::isGuardKey(['driver' => 's3', 'root' => $root], '.htaccess'), 's3 unaffected');
    rmrf($root);
    // An operator's own root .htaccess is not ours: stays visible.
    UploadRootGuard::reset();
    $root = tmpRoot();
    file_put_contents($root . '/.htaccess', "# mine\n");
    assertTrue(!FileManager::isGuardKey(['driver' => 'local', 'root' => $root], '.htaccess'), 'foreign file stays visible');
    rmrf($root);
});

echo "\n{$cyan}══ frame-ancestors safe default ══{$reset}\n\n";

test('selfWhenUnset only applies when nothing is configured', function () {
    unset($_ENV['FLUXFILES_FRAME_ANCESTORS']);
    assertTrue(FrameAncestors::policy([]) === null, 'default unchanged');
    assertTrue(FrameAncestors::policy([], true) === "frame-ancestors 'self'", 'self default');
    assertTrue(FrameAncestors::policy(['https://a.example'], true) === "frame-ancestors 'self' https://a.example", 'allowed origins win');
    $_ENV['FLUXFILES_FRAME_ANCESTORS'] = 'https://b.example';
    assertTrue(FrameAncestors::policy([], true) === 'frame-ancestors https://b.example', 'explicit wins');
    unset($_ENV['FLUXFILES_FRAME_ANCESTORS']);
});

echo "\n{$cyan}══ Generic storage errors ══{$reset}\n\n";

test('delete failure: detail is logged, the client gets a generic message', function () {
    $root = tmpRoot();
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    $real = new LocalFilesystemAdapter($root);
    $boom = new class($real) implements \League\Flysystem\FilesystemAdapter {
        public function __construct(private $i) {}
        public function fileExists(string $p): bool { return $this->i->fileExists($p); }
        public function directoryExists(string $p): bool { return $this->i->directoryExists($p); }
        public function write(string $p, string $c, Config $cfg): void { $this->i->write($p, $c, $cfg); }
        public function writeStream(string $p, $c, Config $cfg): void { $this->i->writeStream($p, $c, $cfg); }
        public function read(string $p): string { return $this->i->read($p); }
        public function readStream(string $p) { return $this->i->readStream($p); }
        public function delete(string $p): void { throw new \RuntimeException('SECRET-BUCKET-DETAIL /srv/data/' . $p); }
        public function deleteDirectory(string $p): void { $this->i->deleteDirectory($p); }
        public function createDirectory(string $p, Config $cfg): void { $this->i->createDirectory($p, $cfg); }
        public function setVisibility(string $p, string $v): void { $this->i->setVisibility($p, $v); }
        public function visibility(string $p): \League\Flysystem\FileAttributes { return $this->i->visibility($p); }
        public function mimeType(string $p): \League\Flysystem\FileAttributes { return $this->i->mimeType($p); }
        public function lastModified(string $p): \League\Flysystem\FileAttributes { return $this->i->lastModified($p); }
        public function fileSize(string $p): \League\Flysystem\FileAttributes { return $this->i->fileSize($p); }
        public function listContents(string $p, bool $d): iterable { return $this->i->listContents($p, $d); }
        public function move(string $s, string $d, Config $cfg): void { $this->i->move($s, $d, $cfg); }
        public function copy(string $s, string $d, Config $cfg): void { $this->i->copy($s, $d, $cfg); }
    };
    $fs = new Filesystem($boom);
    $fs->write('tenant/a.txt', 'x');
    $p = new \ReflectionProperty($dm, 'disks'); $p->setAccessible(true); $p->setValue($dm, ['local' => $fs]);
    $c = new Claims('u', ['read', 'write', 'delete'], ['local'], 'tenant', 5, [], 0, false);
    $fm = new FileManager($dm, $c, new StorageMetadataHandler($dm));
    try {
        $fm->delete('local', 'a.txt');
        throw new \RuntimeException('expected failure');
    } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === 'delete_failed', 'code ' . (string) $e->getErrorCode());
        assertTrue(!str_contains($e->getMessage(), 'SECRET') && !str_contains($e->getMessage(), '/srv/'), 'leaked: ' . $e->getMessage());
        assertTrue($e->getMessage() === 'Delete failed', 'msg ' . $e->getMessage());
    }
    rmrf($root);
});

test('no thrown ApiException in FileManager concatenates a raw exception message', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../api/FileManager.php');
    assertTrue(preg_match("/new ApiException\\([^;]*\\.\\s*\\\$e2?->getMessage\\(\\)/", $src) !== 1, 'raw getMessage() still returned to the client');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
