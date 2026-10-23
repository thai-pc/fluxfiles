<?php

/**
 * list() finds variants/posters with one `_variants/` listing per folder instead
 * of a fileExists() per file and size — on S3/R2/SFTP each check is a round trip.
 * The result must be identical to the per-file lookup it replaces.
 *
 * Usage:
 *   php tests/integration/test-list-variants-batch.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
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

/** Local adapter that counts fileExists() — a stand-in for an S3 HEAD request. */
final class CountingAdapter implements FilesystemAdapter
{
    /** fileExists() calls on variant/poster paths (metadata sidecar checks aside). */
    public int $exists = 0;
    public function __construct(private LocalFilesystemAdapter $in) {}
    public function fileExists(string $path): bool
    {
        if (strpos($path, '_variants/') !== false) {
            $this->exists++;
        }
        return $this->in->fileExists($path);
    }
    public function directoryExists(string $path): bool { return $this->in->directoryExists($path); }
    public function write(string $path, string $contents, Config $config): void { $this->in->write($path, $contents, $config); }
    public function writeStream(string $path, $contents, Config $config): void { $this->in->writeStream($path, $contents, $config); }
    public function read(string $path): string { return $this->in->read($path); }
    public function readStream(string $path) { return $this->in->readStream($path); }
    public function delete(string $path): void { $this->in->delete($path); }
    public function deleteDirectory(string $path): void { $this->in->deleteDirectory($path); }
    public function createDirectory(string $path, Config $config): void { $this->in->createDirectory($path, $config); }
    public function setVisibility(string $path, string $visibility): void { $this->in->setVisibility($path, $visibility); }
    public function visibility(string $path): FileAttributes { return $this->in->visibility($path); }
    public function mimeType(string $path): FileAttributes { return $this->in->mimeType($path); }
    public function lastModified(string $path): FileAttributes { return $this->in->lastModified($path); }
    public function fileSize(string $path): FileAttributes { return $this->in->fileSize($path); }
    public function listContents(string $path, bool $deep): iterable { return $this->in->listContents($path, $deep); }
    public function move(string $source, string $destination, Config $config): void { $this->in->move($source, $destination, $config); }
    public function copy(string $source, string $destination, Config $config): void { $this->in->copy($source, $destination, $config); }
}

final class CountingDiskManager extends DiskManager
{
    public CountingAdapter $adapter;
    private Filesystem $fs;
    public function __construct(string $root)
    {
        parent::__construct(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
        $this->adapter = new CountingAdapter(new LocalFilesystemAdapter($root));
        $this->fs = new Filesystem($this->adapter);
    }
    public function disk(string $name): Filesystem { return $this->fs; }
}

/** $n images in `pics/`, every other one with thumb+medium variants; plus a PDF with a poster. */
function fixture(int $n): array
{
    $root = sys_get_temp_dir() . '/ff-varbatch-' . bin2hex(random_bytes(4));
    mkdir("{$root}/pics/_variants", 0777, true);
    for ($i = 0; $i < $n; $i++) {
        $name = sprintf('img%03d.jpg', $i);
        file_put_contents("{$root}/pics/{$name}", 'x');
        if ($i % 2 === 0) {
            file_put_contents("{$root}/pics/_variants/{$name}_thumb.webp", 'v');
            file_put_contents("{$root}/pics/_variants/{$name}_medium.webp", 'v');
        }
    }
    file_put_contents("{$root}/pics/doc.pdf", '%PDF-');
    file_put_contents("{$root}/pics/_variants/doc.pdf_thumb.webp", 'v');
    $dm = new CountingDiskManager($root);
    $c = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0, false);
    return [new FileManager($dm, $c, new StorageMetadataHandler($dm)), $dm, $c];
}

/** @return array<string,mixed> name => [variant sizes, has poster] */
function shape(array $items): array
{
    $out = [];
    foreach ($items as $it) {
        if (($it['type'] ?? '') === 'file') {
            $out[$it['name']] = [array_keys($it['variants'] ?? []), isset($it['poster'])];
        }
    }
    ksort($out);
    return $out;
}

echo "\n{$cyan}══ list(): batched variant lookup ══{$reset}\n\n";

test('a page of images costs no per-image variant lookups (was 3 per image + 1 per PDF)', function () {
    [$fm, $dm] = fixture(40);
    $dm->adapter->exists = 0;
    $page = $fm->list('local', 'pics', 50);
    assertTrue(count($page['items']) === 41, 'all items listed');
    assertTrue($dm->adapter->exists === 0, "variant fileExists calls: {$dm->adapter->exists}");
});

test('variants and posters are exactly what per-file lookups report', function () {
    [$fm] = fixture(40);
    $s = shape($fm->list('local', 'pics', 50)['items']);
    assertTrue($s['img000.jpg'] === [['thumb', 'medium'], false], 'even image has thumb+medium: ' . json_encode($s['img000.jpg']));
    assertTrue($s['img001.jpg'] === [[], false], 'odd image has none');
    assertTrue($s['doc.pdf'] === [[], true], 'PDF poster found: ' . json_encode($s['doc.pdf']));
    // Same result as a small folder, which still takes the per-file path.
    [$fm2] = fixture(3);
    $s2 = shape($fm2->list('local', 'pics', 50)['items']);
    foreach (['img000.jpg', 'img001.jpg', 'img002.jpg'] as $n) {
        assertTrue($s2[$n] === $s[$n], "{$n} matches the per-file path");
    }
});

test('preview-only tokens still get no variants', function () {
    [$fm, , $c] = fixture(20);
    $c->allowDownload = false;
    $s = shape($fm->list('local', 'pics', 50)['items']);
    assertTrue($s['img000.jpg'][0] === [], 'withheld');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n\n";
exit($failed > 0 ? 1 : 0);
