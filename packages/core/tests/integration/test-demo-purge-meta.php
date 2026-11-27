<?php

declare(strict_types=1);

/**
 * DemoMode::purge() must drop a purged sandbox's metadata (search index, folder index,
 * sidecars) along with its directory, or the shared index.json grows forever (measured
 * OOM at ~100k entries). Also covers the idempotent orphan cleanup and the throttled,
 * locked purgeIfDue().
 */

require_once __DIR__ . '/../../autoload.php';

use FluxFiles\DemoMode;
use FluxFiles\DiskManager;
use FluxFiles\StorageMetadataHandler;

putenv('FLUXFILES_DEMO=1');
putenv('FLUXFILES_DEMO_TTL_HOURS=1');
$p = 0; $f = 0;
function t(string $n, callable $fn): void { global $p, $f;
    try { $fn(); echo "  PASS {$n}\n"; $p++; } catch (\Throwable $e) { echo "  FAIL {$n}: " . $e->getMessage() . "\n"; $f++; } }
function truthy($c, string $m = ''): void { if (!$c) throw new RuntimeException($m ?: 'expected true'); }
function rm(string $d): void { if (!is_dir($d)) return;
    foreach (scandir($d) as $e) { if ($e === '.' || $e === '..') continue; is_dir("$d/$e") ? rm("$d/$e") : @unlink("$d/$e"); } @rmdir($d); }

function rig(): array
{
    $root = sys_get_temp_dir() . '/ff-demo-purge-' . bin2hex(random_bytes(4));
    mkdir($root . '/demo', 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    return [$root, $dm, new StorageMetadataHandler($dm)];
}
/** A sandbox dir with one indexed file + sidecar; $age seconds old. */
function sandbox(string $root, StorageMetadataHandler $meta, string $id, int $age): void
{
    mkdir("$root/demo/$id", 0777, true);
    file_put_contents("$root/demo/$id/a.png", 'x');
    $meta->trackParents('local', "demo/$id/a.png");
    $meta->indexFile('local', "demo/$id/a.png", ['title' => 'A']);
    $meta->save('local', "demo/$id/a.png", ['title' => 'A', 'owner' => 'demo-' . $id]);
    touch("$root/demo/$id", time() - $age);
}

echo "\n══ DemoMode purge drops metadata ══\n\n";

t('TTL purge removes the sandbox\'s index, folder-index and sidecar entries; live ones stay', function () {
    [$root, , $meta] = rig();
    sandbox($root, $meta, 'old0000000000001', 7200);
    sandbox($root, $meta, 'new0000000000002', 0);
    truthy(is_file("$root/_fluxfiles/meta/demo/old0000000000001/a.png.json"), 'sidecar exists before');
    DemoMode::purge($root);
    truthy(!is_dir("$root/demo/old0000000000001"), 'old dir gone');
    truthy(is_dir("$root/demo/new0000000000002"), 'new dir kept');
    truthy(!is_dir("$root/_fluxfiles/meta/demo/old0000000000001"), 'old sidecars gone');
    truthy(is_file("$root/_fluxfiles/meta/demo/new0000000000002/a.png.json"), 'new sidecar kept');
    $fresh = new StorageMetadataHandler(new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]));
    $dirs = array_keys($fresh->dirsCreated('local'));
    truthy(!array_filter($dirs, fn ($d) => str_contains((string) $d, 'old0000000000001')), 'old folder-index entries gone');
    truthy(array_filter($dirs, fn ($d) => str_contains((string) $d, 'new0000000000002')), 'new folder-index entries kept');
    $idx = json_decode((string) file_get_contents("$root/_fluxfiles/index.json"), true) ?: [];
    truthy(!array_filter(array_keys($idx), fn ($k) => str_contains((string) $k, 'old0000000000001')), 'old index entries gone');
    truthy(array_filter(array_keys($idx), fn ($k) => str_contains((string) $k, 'new0000000000002')), 'new index entries kept');
    rm($root);
});

t('purgeOrphanMeta cleans entries of sandboxes with no directory, and is idempotent', function () {
    [$root, , $meta] = rig();
    sandbox($root, $meta, 'gone000000000003', 0);
    sandbox($root, $meta, 'live000000000004', 0);
    rm("$root/demo/gone000000000003"); // directory removed by an old purge; metadata left behind
    truthy(DemoMode::purgeOrphanMeta($root) === 1, 'one orphan sandbox cleaned');
    truthy(!is_dir("$root/_fluxfiles/meta/demo/gone000000000003"), 'orphan sidecars gone');
    truthy(is_file("$root/_fluxfiles/meta/demo/live000000000004/a.png.json"), 'live sandbox untouched');
    truthy(DemoMode::purgeOrphanMeta($root) === 0, 'second run is a no-op');
    rm($root);
});

t('purgeIfDue is throttled and holds a lock; force bypasses the throttle', function () {
    [$root, , $meta] = rig();
    $state = $root . '-state'; mkdir($state);
    sandbox($root, $meta, 'old0000000000005', 7200);
    truthy(DemoMode::purgeIfDue($root, $state) === true, 'first call runs');
    sandbox($root, $meta, 'old0000000000006', 7200);
    truthy(DemoMode::purgeIfDue($root, $state) === false, 'second call inside 5 min is skipped');
    truthy(is_dir("$root/demo/old0000000000006"), 'skipped call purged nothing');
    $fh = fopen("$state/demo_purge.lock", 'c'); flock($fh, LOCK_EX);
    truthy(DemoMode::purgeIfDue($root, $state, true) === false, 'held lock => no concurrent purge, even forced');
    flock($fh, LOCK_UN); fclose($fh);
    truthy(DemoMode::purgeIfDue($root, $state, true) === true, 'forced run works once unlocked');
    truthy(!is_dir("$root/demo/old0000000000006"), 'forced run purged it');
    rm($root); rm($state);
});

t('default per-IP mint limit is NAT-friendly', function () {
    putenv('FLUXFILES_DEMO_IP_MINTS');
    unset($_ENV['FLUXFILES_DEMO_IP_MINTS']);
    truthy(DemoMode::ipMintsPerHour() === 100, 'default 100');
});

echo "\n  Total: " . ($p + $f) . "  Passed: {$p}  Failed: {$f}\n";
exit($f > 0 ? 1 : 0);
