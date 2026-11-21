<?php

/**
 * Concurrent writers on a non-local disk (S3/R2/SFTP) must not lose audit
 * lines or legal holds.
 *
 * Regression (audit 2026-11-20, R6-1): acquireLock() returned without a lock for
 * any driver but `local`, so audit.jsonl / holds.json were a bare
 * read-modify-write; 5 writers × 20 kept 4/100 audit lines (the HMAC chain still
 * verified, so the loss was silent) and 33/100 holds, each lost hold having
 * returned a hold_id. A host-local lock now serialises the PHP workers of one
 * server.
 *
 * The "remote" disk here is a DiskManager whose config() says driver `s3`
 * while the bytes go to a local dir, so the race is real and needs no network.
 *
 * Usage: php tests/integration/test-remote-sidecar-lock.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

$autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
$child = sys_get_temp_dir() . '/fxlock-child-' . uniqid() . '.php';
file_put_contents($child, '<?php
require ' . var_export($autoload, true) . ';
[$_, $root, $drv, $mode, $n, $who] = $argv;
$_ENV["FLUXFILES_SECRET"] = str_repeat("k", 40);
class RemoteLike extends FluxFiles\DiskManager {
    public function __construct(private string $r, private string $d) { parent::__construct(["r" => ["driver" => "local", "root" => $r]]); }
    public function disk(string $name): League\Flysystem\Filesystem { static $fs; return $fs ??= new League\Flysystem\Filesystem(new League\Flysystem\Local\LocalFilesystemAdapter($this->r)); }
    public function config(string $name): array { return ["driver" => $this->d, "bucket" => "b", "root" => $this->r]; }
}
$m = new FluxFiles\StorageMetadataHandler(new RemoteLike($root, $drv));
for ($i = 0; $i < (int) $n; $i++) {
    if ($mode === "audit") { $m->audit("r", "delete", ["user_id" => $who, "file_key" => "$who-$i"]); }
    else { $m->addHold("r", "h{$who}x$i", ["path" => "x/$who$i", "released_at" => null]); }
}
');

/** Run $writers children at once, each doing $n writes; returns the storage root. */
function race(string $child, string $mode, int $writers, int $n, string $lockDir): string
{
    // A lock dir that does not exist yet: the writers race to create it (a loser
    // used to fall back to another directory and lock nothing in common).
    $lockDir .= '/' . $mode;
    $root = sys_get_temp_dir() . '/fxlock-' . uniqid();
    mkdir($root . '/_fluxfiles', 0777, true);
    $procs = []; $pipesAll = [];
    for ($w = 0; $w < $writers; $w++) {
        $env = ['FLUXFILES_LOCK_PATH' => $lockDir, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        $procs[] = proc_open([PHP_BINARY, $child, $root, 's3', $mode, (string) $n, "w$w"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $pipesAll[] = $pipes;
    }
    foreach ($procs as $i => $p) { stream_get_contents($pipesAll[$i][1]); stream_get_contents($pipesAll[$i][2]); proc_close($p); }
    return $root;
}

$lockDir = sys_get_temp_dir() . '/fxlock-locks-' . uniqid();

echo "\nnon-local disk sidecar writes are serialised\n";

test('audit log: 5 concurrent writers × 20 keep all 100 lines', function () use ($child, $lockDir) {
    $root = race($child, 'audit', 5, 20, $lockDir);
    $lines = array_filter(explode("\n", (string) @file_get_contents("$root/_fluxfiles/audit.jsonl")));
    assertTrue(count($lines) === 100, 'kept ' . count($lines) . '/100 audit lines');
});

test('legal holds: 5 concurrent writers × 20 keep all 100 holds', function () use ($child, $lockDir) {
    $root = race($child, 'holds', 5, 20, $lockDir);
    $holds = json_decode((string) @file_get_contents("$root/_fluxfiles/holds.json"), true);
    assertTrue(is_array($holds) && count($holds) === 100, 'kept ' . (is_array($holds) ? count($holds) : 0) . '/100 holds');
});

test('the lock lives in FLUXFILES_LOCK_PATH, keyed by storage location, never named after a secret', function () use ($lockDir) {
    $files = glob($lockDir . '/*/*') ?: [];
    assertTrue($files !== [], 'lock files were created');
    foreach ($files as $f) {
        assertTrue(preg_match('/^[0-9a-f]{40}-(index|audit)\.lock$/', basename($f)) === 1, 'unexpected lock name ' . basename($f));
    }
});

@unlink($child);
echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
