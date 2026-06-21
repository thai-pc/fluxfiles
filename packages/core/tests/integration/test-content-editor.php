<?php

/**
 * Config/code editor: getContent / putContent (M1). Uses a local disk in-process
 * (Flysystem read/write is disk-agnostic), so no SFTP server is needed.
 *
 * Usage: php tests/integration/test-content-editor.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\ApiException;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/**
 * @param bool $codeEdit  allow_code_edit claim
 * @param array|null $allowedExt
 */
function makeFM(bool $codeEdit, ?array $allowedExt = null): array
{
    $root = sys_get_temp_dir() . '/ff-edit-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write'], ['local'], '', 50, $allowedExt, 0);
    $claims->allowCodeEdit = $codeEdit;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    return [$fm, $root];
}

echo "\n{$cyan}══ Config/code editor (M1) ══{$reset}\n\n";

test('getContent reads a text file', function () {
    [$fm, $root] = makeFM(true);
    file_put_contents($root . '/app.conf', "key=value\nport=8080\n");
    $r = $fm->getContent('local', 'app.conf');
    assertEqual("key=value\nport=8080\n", $r['content'], 'content');
    assertEqual(20, $r['size'], 'size');
});

test('getContent: missing file → 404, binary file → 415, oversize → 413', function () {
    [$fm, $root] = makeFM(true);
    try { $fm->getContent('local', 'nope.txt'); throw new \RuntimeException('should 404'); }
    catch (ApiException $e) { assertEqual('not_found', $e->getErrorCode()); }

    file_put_contents($root . '/img.bin', "PNG\x00\x01\x02binary");
    try { $fm->getContent('local', 'img.bin'); throw new \RuntimeException('should 415'); }
    catch (ApiException $e) { assertEqual('not_text', $e->getErrorCode()); }

    file_put_contents($root . '/big.txt', str_repeat('x', 5 * 1024 * 1024 + 1));
    try { $fm->getContent('local', 'big.txt'); throw new \RuntimeException('should 413'); }
    catch (ApiException $e) { assertEqual('edit_too_large', $e->getErrorCode()); }
});

test('putContent overwrites an existing file (allow_code_edit on)', function () {
    [$fm, $root] = makeFM(true);
    file_put_contents($root . '/nginx.conf', "old\n");
    $r = $fm->putContent('local', 'nginx.conf', "server {\n  listen 80;\n}\n");
    assertEqual(24, $r['size'], 'new size');
    assertEqual("server {\n  listen 80;\n}\n", file_get_contents($root . '/nginx.conf'), 'written to disk');
});

test('putContent can edit a .php file (dangerous-ext block does NOT apply to editing)', function () {
    [$fm, $root] = makeFM(true);   // allowed_ext null → all types
    file_put_contents($root . '/wp-config.php', "<?php // old");
    $fm->putContent('local', 'wp-config.php', "<?php define('DB','new');");
    assertEqual("<?php define('DB','new');", file_get_contents($root . '/wp-config.php'), 'php edited');
});

test('putContent: allow_code_edit OFF → 403 (default)', function () {
    [$fm, $root] = makeFM(false);
    file_put_contents($root . '/a.txt', "x");
    try { $fm->putContent('local', 'a.txt', "y"); throw new \RuntimeException('should 403'); }
    catch (ApiException $e) { assertEqual('edit_forbidden', $e->getErrorCode()); }
});

test('putContent: file must exist (no creating new files via the editor) → 404', function () {
    [$fm] = makeFM(true);
    try { $fm->putContent('local', 'new.txt', "hi"); throw new \RuntimeException('should 404'); }
    catch (ApiException $e) { assertEqual('not_found', $e->getErrorCode()); }
});

test('putContent: respects allowed_ext (restricted token cannot edit a disallowed type)', function () {
    [$fm, $root] = makeFM(true, ['txt', 'md']);   // only txt/md editable
    file_put_contents($root . '/script.js', "x");
    try { $fm->putContent('local', 'script.js', "y"); throw new \RuntimeException('should 403'); }
    catch (ApiException $e) { assertEqual('ext_not_allowed', $e->getErrorCode()); }
    file_put_contents($root . '/notes.md', "x");
    $fm->putContent('local', 'notes.md', "# ok");   // allowed type works
    assertEqual('# ok', file_get_contents($root . '/notes.md'), 'md edited');
});

test('putContent: oversize content → 413', function () {
    [$fm, $root] = makeFM(true);
    file_put_contents($root . '/a.txt', "x");
    try { $fm->putContent('local', 'a.txt', str_repeat('y', 5 * 1024 * 1024 + 1)); throw new \RuntimeException('should 413'); }
    catch (ApiException $e) { assertEqual('edit_too_large', $e->getErrorCode()); }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
