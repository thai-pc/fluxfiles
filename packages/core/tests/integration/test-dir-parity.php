<?php

/**
 * Directory-operation PARITY across storage drivers.
 *
 * The folder-rename data loss was not a one-off, it was an instance of a class: an
 * operation that touches a directory behaves differently on a driver with real
 * directories (local, sftp) than on one where a directory is only a key prefix
 * (s3, r2, MinIO, BYOB) — and nothing forced the two to agree. That class has already
 * produced two mirror-image failures in ONE operation (rename destroyed empty folders
 * on local; move threw a raw 500 on object stores), which is the signal for a matrix
 * rather than one more per-bug regression test.
 *
 * Every scenario is declared ONCE and executed against every driver the environment
 * offers. Assertions are on the END STATE — what exists, what is gone, what the folder
 * index says — never on the mechanism, which is exactly what lets one assertion cover
 * drivers with completely different internals.
 *
 * Drivers:
 *   local          always.
 *   s3             when FXTEST_S3_* is set. MinIO in CI (job `s3-minio`), the real S3
 *                  API through the same AwsS3V3Adapter.
 *   sftp           when FXTEST_SFTP_* is set. atmoz/sftp in CI (job `selfboot-e2e`).
 *
 * There is deliberately NO local-backed "object store" stand-in. Pairing an `s3` config
 * with a local Filesystem does select the object-store branch, but StorageMetadataHandler
 * routes metadata to real S3 object metadata whenever the driver is `s3`, so every
 * scenario that uploads a file needs a live client. A stand-in that cannot upload would
 * cover only the pure-directory cases while reading, in a green run, as though it covered
 * everything. MinIO in CI is the real API through the same adapter, so it is both cheaper
 * and honest to require it.
 *
 * Usage:
 *   php tests/integration/test-dir-parity.php
 *   FXTEST_S3_* … php tests/integration/test-dir-parity.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([__DIR__ . '/../..', __DIR__ . '/../../../..'] as $envDir) {
    if (is_file($envDir . '/.env')) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\SsrfGuard;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function tmpTxt(string $c): string {
    $p = sys_get_temp_dir() . '/fxdp-' . uniqid() . '.txt';
    file_put_contents($p, $c);
    return $p;
}

/**
 * One driver under test: a factory producing a fresh, isolated {fm, fs, meta, disk}
 * per scenario so nothing leaks between them.
 *
 * @var array<int,array{label:string, make:callable}> $DRIVERS
 */
$DRIVERS = [];

// ── local ────────────────────────────────────────────────────────────────────
$DRIVERS[] = ['label' => 'local', 'make' => static function (): array {
    $root = sys_get_temp_dir() . '/ff-parity-local-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['d' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    return mk($dm);
}];

// ── s3 (real; MinIO in CI) ───────────────────────────────────────────────────
if ((getenv('FXTEST_S3_BUCKET') ?: '') !== '') {
    $DRIVERS[] = ['label' => 's3:' . (getenv('FXTEST_S3_LABEL') ?: 'live'), 'make' => static function (): array {
        $cfg = [
            'driver'     => 's3',
            'region'     => getenv('FXTEST_S3_REGION') ?: 'us-east-1',
            'bucket'     => getenv('FXTEST_S3_BUCKET'),
            'key'        => getenv('FXTEST_S3_KEY') ?: '',
            'secret'     => getenv('FXTEST_S3_SECRET') ?: '',
            'visibility' => getenv('FXTEST_S3_VISIBILITY') ?: 'private',
        ];
        if ((getenv('FXTEST_S3_ENDPOINT') ?: '') !== '') { $cfg['endpoint'] = getenv('FXTEST_S3_ENDPOINT'); }
        $dm = new DiskManager(['d' => $cfg]);
        // Every scenario runs under its own prefix, so two runs (and two drivers)
        // can never collide inside one bucket.
        return mk($dm, 'ff-parity/' . bin2hex(random_bytes(6)) . '/');
    }];
}

// ── sftp (real; atmoz/sftp in CI) ────────────────────────────────────────────
if ((getenv('FXTEST_SFTP_HOST') ?: '') !== '') {
    if (getenv('FXTEST_SFTP_ALLOW_HOST')) {
        SsrfGuard::$allowTestHosts[] = strtolower((string) getenv('FXTEST_SFTP_HOST'));
    }
    $DRIVERS[] = ['label' => 'sftp', 'make' => static function (): array {
        $keyPath = getenv('FXTEST_SFTP_PRIVATE_KEY') ?: '';
        $dm = new DiskManager(['d' => [
            'driver'      => 'sftp',
            'host'        => getenv('FXTEST_SFTP_HOST'),
            'port'        => (int) (getenv('FXTEST_SFTP_PORT') ?: 22),
            'username'    => getenv('FXTEST_SFTP_USERNAME') ?: '',
            'password'    => getenv('FXTEST_SFTP_PASSWORD') ?: '',
            'private_key' => $keyPath !== '' && is_file($keyPath) ? (string) file_get_contents($keyPath) : '',
            'root'        => getenv('FXTEST_SFTP_ROOT') ?: '.',
        ]]);
        return mk($dm, 'ff-parity/' . bin2hex(random_bytes(6)) . '/');
    }];
}

/**
 * A prefix-scoped view of a Filesystem, exposing only what the scenarios probe.
 *
 * The remote drivers share one bucket/account, so each run gets its own tenant prefix.
 * FileManager applies that prefix itself; raw Filesystem calls do not. Without this
 * wrapper every scenario assertion would have to remember to prepend it — and the ones
 * that forgot would fail identically on s3 AND sftp while passing on local, which reads
 * exactly like a driver divergence and is not one.
 */
final class ScopedFs
{
    public function __construct(private $fs, private string $prefix) {}
    private function k(string $key): string { return $this->prefix . $key; }
    public function read(string $key): string { return (string) $this->fs->read($this->k($key)); }
    public function write(string $key, string $body): void { $this->fs->write($this->k($key), $body); }
    public function fileExists(string $key): bool { return $this->fs->fileExists($this->k($key)); }
    public function directoryExists(string $key): bool { return $this->fs->directoryExists($this->k($key)); }
    public function listContents(string $key, bool $deep) { return $this->fs->listContents($this->k($key), $deep); }
}

/**
 * Wire a FileManager over disk 'd'. $prefix scopes a shared remote backend so runs
 * never collide; it is invisible to the scenarios, which use tenant-relative paths.
 *
 * @return array{0:FileManager,1:ScopedFs,2:StorageMetadataHandler,3:string}
 */
function mk(DiskManager $dm, string $prefix = ''): array
{
    $claims = new Claims('u', ['read', 'write', 'delete'], ['d'], $prefix, 50, null, 0, false);
    $meta = new StorageMetadataHandler($dm);
    return [new FileManager($dm, $claims, $meta), new ScopedFs($dm->disk('d'), $prefix), $meta, $prefix];
}

/** Upload $name into $dir with $content. */
function up(FileManager $fm, string $dir, string $name, string $content): void
{
    $tmp = tmpTxt($content);
    $fm->upload('d', $dir, ['name' => $name, 'size' => filesize($tmp), 'tmp_name' => $tmp], true);
    @unlink($tmp);
}

/**
 * Does this directory exist, as the USER would judge it?
 *
 * `directoryExists()` is the one probe that genuinely disagrees between drivers: on an
 * object store a "directory" is only a prefix, and a prefix with no objects under it
 * may or may not answer true depending on whether a zero-byte marker was written. So a
 * parity assertion must accept EITHER a real directory or any content living under the
 * prefix — that is the end state a user perceives, and it is the same on every driver.
 */
function dirThere($fs, string $key): bool
{
    if ($fs->directoryExists($key)) { return true; }
    foreach ($fs->listContents($key, true) as $_) { return true; }
    return false;
}

function fileThere($fs, string $key): bool { return $fs->fileExists($key); }

/** Scenarios: name => fn(FileManager, Filesystem, StorageMetadataHandler, string $prefix). */
$SCENARIOS = [

    'rename: an EMPTY folder survives the move' => static function (FileManager $fm, $fs) {
        $fm->mkdir('d', 'empty');
        $fm->rename('d', 'empty', 'empty2');
        assertTrue(dirThere($fs, 'empty2'), 'destination exists');
        assertTrue(!dirThere($fs, 'empty'), 'source gone');
    },

    'rename: a folder whose ONLY content is subfolders' => static function (FileManager $fm, $fs) {
        $fm->mkdir('d', 'top/a/b');
        $fm->rename('d', 'top', 'top2');
        assertTrue(dirThere($fs, 'top2/a/b'), 'the deepest empty dir came along');
        assertTrue(!dirThere($fs, 'top'), 'source gone');
    },

    'rename: a mixed tree keeps every file AND every empty dir' => static function (FileManager $fm, $fs) {
        up($fm, 'tree/docs', 'a.txt', 'A');
        up($fm, 'tree', 'top.txt', 'T');
        $fm->mkdir('d', 'tree/void/deeper');
        $fm->rename('d', 'tree', 'tree2');
        assertEqual('A', (string) $fs->read('tree2/docs/a.txt'), 'nested file moved');
        assertEqual('T', (string) $fs->read('tree2/top.txt'), 'top-level file moved');
        assertTrue(dirThere($fs, 'tree2/void/deeper'), 'empty dir moved');
        assertTrue(!fileThere($fs, 'tree/top.txt'), 'source file gone');
    },

    'move: into a sibling folder' => static function (FileManager $fm, $fs) {
        up($fm, 'src/inner', 'x.txt', 'X');
        $fm->mkdir('d', 'dst');
        $fm->move('d', 'src', 'dst/src');
        assertEqual('X', (string) $fs->read('dst/src/inner/x.txt'), 'subtree relocated');
        assertTrue(!dirThere($fs, 'src'), 'source gone');
    },

    'move: into its OWN subtree is refused, and nothing is lost' => static function (FileManager $fm, $fs) {
        up($fm, 'self/docs', 'a.txt', 'A');
        try {
            $fm->move('d', 'self', 'self/docs/self');
            throw new \RuntimeException('expected a refusal');
        } catch (ApiException $e) {
            assertEqual('move_into_self', $e->getErrorCode(), 'move_into_self');
        }
        assertEqual('A', (string) $fs->read('self/docs/a.txt'), 'source intact after the refusal');
    },

    'trash+restore: an EMPTY folder comes back' => static function (FileManager $fm, $fs) {
        $fm->mkdir('d', 'gone');
        $r = $fm->trash('d', 'gone');
        assertTrue(!dirThere($fs, 'gone'), 'trashed');
        $fm->restore('d', (string) $r['trash_id']);
        assertTrue(dirThere($fs, 'gone'), 'restored');
    },

    'trash+restore: a subfolders-only tree comes back whole' => static function (FileManager $fm, $fs) {
        $fm->mkdir('d', 'shell/a/b');
        $r = $fm->trash('d', 'shell');
        assertTrue(!dirThere($fs, 'shell'), 'trashed');
        $fm->restore('d', (string) $r['trash_id']);
        assertTrue(dirThere($fs, 'shell/a/b'), 'the deepest empty dir came back');
    },

    'trash+restore: a mixed tree comes back whole' => static function (FileManager $fm, $fs) {
        up($fm, 'mix/docs', 'a.txt', 'A');
        $fm->mkdir('d', 'mix/void');
        $r = $fm->trash('d', 'mix');
        assertTrue(!fileThere($fs, 'mix/docs/a.txt'), 'trashed');
        $fm->restore('d', (string) $r['trash_id']);
        assertEqual('A', (string) $fs->read('mix/docs/a.txt'), 'file back');
        assertTrue(dirThere($fs, 'mix/void'), 'empty dir back');
    },

    'delete: a folder removes the whole subtree' => static function (FileManager $fm, $fs) {
        up($fm, 'kill/docs', 'a.txt', 'A');
        $fm->mkdir('d', 'kill/void');
        $fm->delete('d', 'kill');
        assertTrue(!fileThere($fs, 'kill/docs/a.txt'), 'file gone');
        assertTrue(!dirThere($fs, 'kill'), 'tree gone');
    },

    'mkdir: colliding with an existing folder is a clean 409' => static function (FileManager $fm) {
        $fm->mkdir('d', 'dup');
        try {
            $fm->mkdir('d', 'dup');
            throw new \RuntimeException('expected a conflict');
        } catch (ApiException $e) {
            assertEqual(409, $e->getHttpCode(), 'http 409');
        }
    },

    // NOT a scenario: case-only rename ("Case" → "CASE"). Whether those are the same
    // directory is a property of the HOST FILESYSTEM (macOS/APFS folds case and answers
    // 409; ext4 and S3 keys do not), so the correct expectation differs per machine
    // rather than per driver. Asserting it would need a different answer per environment,
    // which is exactly what this file's one-assertion-covers-every-driver design rules out.

    'rename: a folder holding _variants/ carries them along' => static function (FileManager $fm, $fs) {
        up($fm, 'pics', 'p.txt', 'P');
        // Write a variant-shaped sidecar by hand: generating real ones needs an image
        // pipeline, and what is under test is that the subtree travels, not GD.
        $fs->write('pics/_variants/p_thumb.webp', 'VARIANT');
        $fm->rename('d', 'pics', 'pics2');
        assertEqual('VARIANT', (string) $fs->read('pics2/_variants/p_thumb.webp'), 'variants moved');
        assertTrue(!fileThere($fs, 'pics/_variants/p_thumb.webp'), 'source variants gone');
    },

    'rename: metadata follows the files' => static function (FileManager $fm, $fs, StorageMetadataHandler $meta, string $prefix) {
        up($fm, 'meta', 'a.txt', 'A');
        $meta->save('d', $prefix . 'meta/a.txt', ['title' => 'Hello']);
        $fm->rename('d', 'meta', 'meta2');
        $after = $meta->get('d', $prefix . 'meta2/a.txt');
        assertEqual('Hello', $after['title'] ?? null, 'title travelled with the file');
    },

    'the folder index never points at a directory that is gone' => static function (FileManager $fm, $fs, StorageMetadataHandler $meta, string $prefix) {
        up($fm, 'idx/sub', 'a.txt', 'A');
        $fm->rename('d', 'idx', 'idx2');
        // A stale entry surfaces as a folder-SEARCH hit that 404s on navigate, which is
        // how the original bug stayed invisible: list() reads the filesystem, search
        // reads the index, and the two disagreed.
        foreach ($meta->searchFolders('d', 'idx', 50, $prefix) as $hit) {
            $key = (string) ($hit['path'] ?? $hit['key'] ?? '');
            if ($key === '') { continue; }
            assertTrue(dirThere($fs, $key), "index points at a live directory: {$key}");
        }
    },
];

// ── run ──────────────────────────────────────────────────────────────────────
echo "\n{$cyan}══ Directory-operation parity across drivers ══{$reset}\n";
echo "  drivers: " . implode(', ', array_column($DRIVERS, 'label')) . "\n";
if (count($DRIVERS) < 3) {
    echo "  {$yellow}note{$reset} set FXTEST_S3_* / FXTEST_SFTP_* to include the real backends\n";
}
echo "\n";

foreach ($DRIVERS as $drv) {
    echo "{$cyan}── {$drv['label']} ──{$reset}\n";
    foreach ($SCENARIOS as $name => $scenario) {
        try {
            [$fm, $fs, $meta, $prefix] = ($drv['make'])();
            $scenario($fm, $fs, $meta, $prefix);
            echo "  {$green}PASS{$reset} {$name}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
            $failed++;
        }
    }
}

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
