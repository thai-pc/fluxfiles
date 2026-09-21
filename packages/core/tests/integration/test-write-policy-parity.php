<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\{ApiException, Claims, DiskManager, FileManager, QuotaManager, StorageMetadataHandler};
use FluxFiles\Db\{Connection, DbMetadataHandler, MetadataImporter, MigrationRunner};

$failed = 0;
$passed = 0;
$roots = [];
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $code): void {
    try { $fn(); } catch (ApiException $e) { check($e->getErrorCode() === $code, $e->getErrorCode()); return; }
    throw new RuntimeException('Expected ' . $code);
}
function test(string $name, callable $fn): void {
    global $failed, $passed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
function fixture(string $backend): array {
    global $roots;
    $root = sys_get_temp_dir() . '/ff-write-policy-' . bin2hex(random_bytes(8));
    $roots[] = $root;
    $dm = new DiskManager([
        'local' => ['driver' => 'local', 'root' => $root . '/a', 'url' => '/s'],
        'other' => ['driver' => 'local', 'root' => $root . '/b', 'url' => '/s'],
    ]);
    $db = null;
    if ($backend === 'db') {
        $db = new Connection('sqlite::memory:');
        (new MigrationRunner($db))->migrate(__DIR__ . '/../../db/migrations');
        $meta = new DbMetadataHandler($db, $dm);
    } else {
        $meta = new StorageMetadataHandler($dm);
    }
    $claims = new Claims('alice', ['read', 'write', 'delete'], ['local', 'other'], '', 50, null, 0);
    $claims->allowCodeEdit = true;
    $fm = new FileManager($dm, $claims, $meta);
    $fm->setQuotaManager(new QuotaManager($dm));
    return [$fm, $dm, $claims, $meta, $db];
}
function uploadText(FileManager $fm, string $name, string $text): array {
    $tmp = tempnam(sys_get_temp_dir(), 'ff-policy');
    file_put_contents($tmp, $text);
    try { return $fm->upload('local', '', ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($text)]); }
    finally { unlink($tmp); }
}

foreach (['json', 'db'] as $backend) {
    if ($backend === 'db' && !extension_loaded('pdo_sqlite')) { echo "SKIP SQLite unavailable\n"; continue; }
    test("$backend: preview-only cannot read original text", function () use ($backend) {
        [$fm, $dm, $c] = fixture($backend);
        $dm->disk('local')->write('a.txt', 'secret');
        $c->allowDownload = false;
        rejects(fn() => $fm->getContent('local', 'a.txt'), 'download_forbidden');
        $c->allowDownload = true;
        check($fm->getContent('local', 'a.txt')['content'] === 'secret', 'downloadable text must still work');
    });
    test("$backend: edit checks quota delta and preserves rejected content", function () use ($backend) {
        [$fm, $dm, $c] = fixture($backend);
        $fs = $dm->disk('local'); $fs->write('a.txt', str_repeat('a', 800000));
        $c->maxStorageMb = 1;
        rejects(fn() => $fm->putContent('local', 'a.txt', str_repeat('b', 2 * 1048576)), 'quota_exceeded');
        check($fs->fileSize('a.txt') === 800000, 'rejection changed original');
        $fm->putContent('local', 'a.txt', str_repeat('c', 900000));
        check($fs->fileSize('a.txt') === 900000, 'old size double-counted');
        $fm->putContent('local', 'a.txt', 'small');
        check($fs->read('a.txt') === 'small', 'shrinking edit failed');
    });
    test("$backend: edit refreshes dedupe and searchable size, retaining owner/title", function () use ($backend) {
        [$fm, $dm, $c, $meta] = fixture($backend);
        $c->dedupeUploads = true;
        uploadText($fm, 'a.txt', 'original');
        $meta->save('local', 'a.txt', ['title' => 'Keep me']);
        $fm->putContent('local', 'a.txt', 'edited bytes');
        check($meta->findByHash('local', hash('sha256', 'original')) === null, 'stale hash remains');
        check($meta->findByHash('local', hash('sha256', 'edited bytes')) !== null, 'new hash missing');
        $row = $meta->get('local', 'a.txt');
        check($row['uploaded_by'] === 'alice' && $row['title'] === 'Keep me', 'metadata lost');
        $res = uploadText($fm, 'b.txt', 'original');
        check(empty($res['duplicate']) && $dm->disk('local')->read('b.txt') === 'original', 'false duplicate');
        $rows = $meta->search('local', 'a.txt');
        check((int) $rows[0]['size'] === 12, 'search size stale');
    });
    foreach (['crossCopy', 'crossMove'] as $operation) {
        test("$backend: $operation respects destination file count", function () use ($backend, $operation) {
            [$fm, $dm, $c] = fixture($backend);
            $dm->disk('local')->write('a.txt', 'a'); $dm->disk('other')->write('b.txt', 'b');
            $c->maxFiles = 1;
            rejects(fn() => $fm->$operation('other', 'b.txt', 'local', 'b.txt'), 'too_many_files');
            check(!$dm->disk('local')->fileExists('b.txt') && $dm->disk('other')->fileExists('b.txt'), 'rejection changed storage');
            $c->maxFiles = 2;
            $fm->$operation('other', 'b.txt', 'local', 'b.txt');
            check($dm->disk('local')->read('b.txt') === 'b', 'allowed transfer failed');
        });
    }
    test("$backend: extraction checks total new files before writing", function () use ($backend) {
        if (!class_exists(ZipArchive::class)) { echo "SKIP ZipArchive unavailable\n"; return; }
        [$fm, $dm, $c] = fixture($backend);
        $tmp = tempnam(sys_get_temp_dir(), 'ff-zip');
        $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('one.txt', 'one'); $zip->addFromString('two.txt', 'two'); $zip->close();
        $dm->disk('local')->write('input.zip', file_get_contents($tmp)); unlink($tmp);
        $c->maxFiles = 2;
        rejects(fn() => $fm->extractZip('local', 'input.zip', 'out'), 'too_many_files');
        check(!$dm->disk('local')->fileExists('out/one.txt'), 'partial extraction');
        $c->maxFiles = 3;
        $fm->extractZip('local', 'input.zip', 'out');
        $c->uploadCollision = 'overwrite';
        $fm->extractZip('local', 'input.zip', 'out');
        check((new QuotaManager($dm))->getFileCount('local', '') === 3, 'overwrites counted as new files');
    });
    test("$backend: multipart validates collision, owner, quota and count before completion", function () use ($backend) {
        [$fm, $dm, $c, $meta] = fixture($backend);
        $dm->disk('local')->write('a.txt', str_repeat('a', 800000));
        $c->maxStorageMb = 1; $c->maxFiles = 1; $c->uploadCollision = 'overwrite';
        check($fm->validateChunkUpload('local', 'a.txt', 900000) === 'a.txt', 'overwrite delta incorrect');
        rejects(fn() => $fm->validateChunkUpload('local', 'a.txt', 2 * 1048576), 'quota_exceeded');
        rejects(fn() => $fm->validateChunkUpload('local', 'new.txt', 1), 'too_many_files');
        $meta->save('local', 'a.txt', ['uploaded_by' => 'bob']); $c->ownerOnly = true;
        rejects(fn() => $fm->validateChunkUpload('local', 'a.txt', 1), 'owner_only');
        $c->uploadCollision = 'reject';
        rejects(fn() => $fm->validateChunkUpload('local', 'a.txt', 1, true), 'name_conflict');
        $c->uploadCollision = 'rename'; $c->maxFiles = 2;
        $key = $fm->validateChunkUpload('local', 'a.txt', 1, true);
        check($key !== 'a.txt', 'rename clobbers original');
        $dm->disk('local')->write($key, 'concurrent upload');
        rejects(fn() => $fm->validateChunkUpload('local', $key, 1), 'name_conflict');
    });
}

if (extension_loaded('pdo_sqlite')) {
    test('DB import cannot steal ownership; rejection writes no rows', function () {
        [$fm, $dm, $c, $meta, $db] = fixture('db');
        $c->ownerOnly = true;
        $meta->save('local', 'bob.txt', ['uploaded_by' => 'bob', 'title' => 'original']);
        $allowed = function (string $path) use ($fm): bool {
            try { $fm->assertCanModifyScopedPath('local', $path); return true; }
            catch (ApiException $e) { return false; }
        };
        $importer = new MetadataImporter($db);
        $result = $importer->import('local', [['path' => 'new.txt'], ['path' => 'bob.txt', 'owner' => 'alice']], $allowed, 'alice');
        check($result['imported'] === 0 && count($result['errors']) === 1, 'unauthorized import accepted');
        check($meta->get('local', 'new.txt') === null && $meta->get('local', 'bob.txt')['uploaded_by'] === 'bob', 'partial write');
        $result = $importer->import('local', [['path' => 'new.txt', 'owner' => 'bob']], $allowed, 'alice');
        check($result['imported'] === 1 && $meta->get('local', 'new.txt')['uploaded_by'] === 'alice', 'forged owner accepted');
        $result = $importer->import('local', [['path' => '_fluxfiles/secret']], $allowed, 'alice');
        check($result['imported'] === 0, 'system path accepted');
    });
}

foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
