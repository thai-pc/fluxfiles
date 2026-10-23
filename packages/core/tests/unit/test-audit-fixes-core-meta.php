<?php

/**
 * Regression tests for the 2026-10-23 audit fixes in the core metadata layer:
 * JwtMiddleware (exp required), FixityChecker (digit keys, trusted entries,
 * extra write actions), AuditChain (unchained-after-start = tampering),
 * TusServer (per-tenant cap, single finalize), readAudit() since/limit, legal
 * holds (fail closed, case-insensitive), private `_fluxfiles/` writes on a
 * public S3 disk, and embed.php (role/edition validation, fluxfiles_embed()
 * escaping).
 *
 * Usage: php tests/unit/test-audit-fixes-core-meta.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use FluxFiles\ApiException;
use FluxFiles\AuditChain;
use FluxFiles\Claims;
use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\FixityChecker;
use FluxFiles\JwtCompat;
use FluxFiles\JwtMiddleware;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\TusServer;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: " . get_class($e) . ': ' . $e->getMessage() . "\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void {
    if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a));
}
function expectApi(callable $fn, string $code, ?int $status = null): void {
    try { $fn(); } catch (ApiException $e) {
        assertEqual($code, $e->getErrorCode(), 'error code');
        if ($status !== null) assertEqual($status, $e->getCode(), 'status');
        return;
    }
    throw new \RuntimeException("expected ApiException {$code}");
}
function tmpRoot(string $tag): string {
    $d = sys_get_temp_dir() . '/ff-afcm-' . $tag . '-' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}
function put(string $root, string $rel, string $bytes): void {
    @mkdir(dirname("{$root}/{$rel}"), 0777, true);
    file_put_contents("{$root}/{$rel}", $bytes);
}

$SECRET = str_repeat('k', 40);
AuditChain::configure($SECRET);

echo "\n{$cyan}══ JwtMiddleware: exp is required ══{$reset}\n\n";

test('a token without `exp` is refused (401 token_invalid)', function () use ($SECRET) {
    $t = JwtCompat::encode(['sub' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'prefix' => ''], $SECRET);
    expectApi(fn () => JwtMiddleware::handle($t, $SECRET), 'token_invalid', 401);
});

test('a typed token without `exp` is still refused as a non-access token (403)', function () use ($SECRET) {
    $t = JwtCompat::encode(['t' => 'stream', 'sub' => 'u'], $SECRET);
    expectApi(fn () => JwtMiddleware::handle($t, $SECRET), 'token_not_access', 403);
});

test('a builder-minted token (always carries exp) still authenticates', function () use ($SECRET) {
    $_ENV['FLUXFILES_SECRET'] = $SECRET;
    $c = JwtMiddleware::handle(fluxfiles_token(['user' => 'u1', 'perms' => ['read']]), $SECRET);
    assertEqual('u1', $c->userId);
});

echo "\n{$cyan}══ FixityChecker / AuditChain ══{$reset}\n\n";

test('an all-digit file name at the root does not TypeError (int array key)', function () {
    $root = tmpRoot('fxdigit');
    put($root, '2024', 'year');
    put($root, '7/8', 'nested');
    $fs = (new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]))->disk('local');
    $r = FixityChecker::run($fs, '', fn (string $k): bool => str_starts_with($k, '_fluxfiles'), []);
    assertEqual(2, $r['baselined'], 'baselined');
    unlink("{$root}/2024");
    $r = FixityChecker::run($fs, '', fn (string $k): bool => str_starts_with($k, '_fluxfiles'), []);
    assertEqual(['2024'], $r['missing_unexplained'], 'missing key stays a string');
});

/** Local disk + metadata handler + a baselined tenant tree "t/". */
function chainedEnv(): array {
    $root = tmpRoot('fxchain');
    foreach (['t/a.txt' => 'alpha', 't/b.txt' => 'bravo', 't/docs/x/c.txt' => 'charlie', 't/d.txt' => 'delta'] as $k => $v) {
        put($root, $k, $v);
    }
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    $meta = new StorageMetadataHandler($dm);
    $meta->audit('local', 'mkdir', ['user_id' => 'admin', 'file_key' => 't/docs']); // chain started
    $skip = fn (string $k): bool => str_contains($k, '_fluxfiles');
    FixityChecker::run($dm->disk('local'), 't', $skip, $meta->readAudit('local'));
    return [$root, $dm, $meta, $skip];
}

test('B7: an unchained line appended after the chain started is tampering in verify()', function () {
    [$root, , $meta] = chainedEnv();
    file_put_contents("{$root}/_fluxfiles/audit.jsonl",
        json_encode(['ts' => time() + 5, 'action' => 'upload', 'context' => ['file_key' => 't/a.txt']]) . "\n", FILE_APPEND);
    $r = AuditChain::verify($meta->readAudit('local'));
    assertTrue($r['ok'] === false && count($r['tampered']) === 1, json_encode($r));
});

test('B7: fixity ignores an unchained/forged "upload" line vouching for a tampered file', function () {
    [$root, $dm, $meta, $skip] = chainedEnv();
    file_put_contents("{$root}/t/a.txt", 'tampered');
    file_put_contents("{$root}/t/b.txt", 'tampered too');
    file_put_contents("{$root}/_fluxfiles/audit.jsonl",
        json_encode(['ts' => time() + 5, 'action' => 'upload', 'context' => ['file_key' => 't/a.txt']]) . "\n"
        . json_encode(['ts' => time() + 5, 'action' => 'upload', 'context' => ['file_key' => 't/b.txt'], 'prev' => '', 'hash' => str_repeat('0', 64)]) . "\n",
        FILE_APPEND);
    $r = FixityChecker::run($dm->disk('local'), 't', $skip, $meta->readAudit('local'));
    sort($r['changed_unexplained']);
    assertEqual(['t/a.txt', 't/b.txt'], $r['changed_unexplained'], json_encode($r));
});

test('B7: a genuine chained entry still explains the change', function () {
    [$root, $dm, $meta, $skip] = chainedEnv();
    file_put_contents("{$root}/t/a.txt", 'edited in app');
    $meta->audit('local', 'content_update', ['user_id' => 'u', 'file_key' => 't/a.txt']);
    $r = FixityChecker::run($dm->disk('local'), 't', $skip, $meta->readAudit('local'));
    assertEqual(['t/a.txt'], $r['changed_explained'], json_encode($r));
});

test('N3: retention_trash / terminal / extract (sibling tree) explain their changes', function () {
    [$root, $dm, $meta, $skip] = chainedEnv();
    unlink("{$root}/t/d.txt");
    file_put_contents("{$root}/t/docs/x/c.txt", 'overwritten by extract');
    $meta->audit('local', 'retention_trash', ['user_id' => 'u', 'file_key' => 't/d.txt']);
    $meta->audit('local', 'extract', ['user_id' => 'u', 'file_key' => 't/docs/x.zip']);
    $r = FixityChecker::run($dm->disk('local'), 't', $skip, $meta->readAudit('local'));
    assertEqual(['t/d.txt'], $r['missing_explained'], 'retention ' . json_encode($r));
    assertEqual(['t/docs/x/c.txt'], $r['changed_explained'], 'extract ' . json_encode($r));

    file_put_contents("{$root}/t/b.txt", 'edited over ssh');
    sleep(1); // the change must postdate the previous pass's checked_at
    $meta->audit('local', 'terminal', ['user_id' => 'u', 'file_key' => '', 'detail' => 'sed -i s/a/b/ b.txt']);
    $r = FixityChecker::run($dm->disk('local'), 't', $skip, $meta->readAudit('local'));
    assertEqual(['t/b.txt'], $r['changed_explained'], 'terminal ' . json_encode($r));
});

test('an unchained log (no secret ever configured) is still trusted as before', function () {
    $rows = [['action' => 'upload', 'file_key' => 't/a.txt', 'created_at' => 10]];
    assertEqual($rows, AuditChain::trustedEntries($rows));
});

echo "\n{$cyan}══ readAudit(since, limit) ══{$reset}\n\n";

$dbFile = sys_get_temp_dir() . '/ff-afcm-' . getmypid() . '.sqlite3';
@unlink($dbFile);
$conn = new Connection('sqlite:' . $dbFile);
(new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
$dbDm = new DiskManager(['local' => ['driver' => 'local', 'root' => tmpRoot('db')]]);
$db = new DbMetadataHandler($conn, $dbDm);

test('B15: both backends filter by since and keep only the newest N, oldest-first', function () use ($db) {
    $json = new StorageMetadataHandler(new DiskManager(['local' => ['driver' => 'local', 'root' => tmpRoot('ra')]]));
    foreach (['json' => $json, 'db' => $db] as $name => $repo) {
        foreach ([1, 2, 3, 4] as $i) {
            $repo->audit('local', 'upload', ['user_id' => 'u', 'file_key' => "f{$i}"]);
        }
        $all = $repo->readAudit('local');
        assertEqual(4, count($all), "{$name} all");
        $last2 = $repo->readAuditWindow('local', null, 2);
        assertEqual(['f3', 'f4'], array_column($last2, 'file_key'), "{$name} limit");
        assertEqual([], $repo->readAuditWindow('local', time() + 3600), "{$name} since");
        assertEqual(4, count($repo->readAuditWindow('local', time() - 60, null, 'u')), "{$name} since+user");
        assertEqual([], $repo->readAuditWindow('local', null, null, 'nobody'), "{$name} user");
        assertEqual($all, $repo->readAuditWindow('local'), "{$name} no filter = readAudit()");
    }
});

echo "\n{$cyan}══ Legal holds ══{$reset}\n\n";

test('N4: an unreadable holds.json fails CLOSED for holdBlocking()', function () {
    $root = tmpRoot('hold');
    $meta = new StorageMetadataHandler(new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]));
    put($root, '_fluxfiles/holds.json', '{"h1": {"path": "t/a.txt", "released_at": nu');
    expectApi(fn () => $meta->holdBlocking('local', 't/a.txt'), 'legal_hold_active', 403);
    expectApi(fn () => $meta->holdBlocking('local', 't/unrelated.txt'), 'legal_hold_active', 403);
});

test('N4: addHold()/releaseHold() never rewrite holds.json from a failed read', function () {
    $root = tmpRoot('hold');
    $meta = new StorageMetadataHandler(new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]));
    $corrupt = '{"h1": {"path": "t/a.txt", "released_at": nu';
    put($root, '_fluxfiles/holds.json', $corrupt);
    expectApi(fn () => $meta->addHold('local', 'h2', ['path' => 't/b.txt', 'released_at' => null]), 'legal_hold_active');
    expectApi(fn () => $meta->releaseHold('local', 'h1', ['released_at' => time()]), 'legal_hold_active');
    assertEqual($corrupt, file_get_contents("{$root}/_fluxfiles/holds.json"), 'manifest untouched');
});

test('N4: no manifest = no holds; the listing badge lookup tolerates a corrupt one', function () {
    $root = tmpRoot('hold');
    $meta = new StorageMetadataHandler(new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]));
    assertEqual(null, $meta->holdBlocking('local', 't/a.txt'));
    put($root, '_fluxfiles/holds.json', 'garbage');
    assertEqual(['t/a.txt' => null], $meta->holdsCoveringMany('local', ['t/a.txt']));
});

test('N6: hold path matching is case-insensitive (JSON + DB backends)', function () use ($db) {
    $json = new StorageMetadataHandler(new DiskManager(['local' => ['driver' => 'local', 'root' => tmpRoot('hc')]]));
    foreach (['json' => $json, 'db' => $db] as $name => $repo) {
        $repo->addHold('local', 'hc1', ['path' => 'T/Contracts/Deal.PDF', 'released_at' => null, 'placed_at' => 1]);
        $repo->addHold('local', 'hc2', ['path' => 'T/Legal', 'is_dir' => true, 'released_at' => null, 'placed_at' => 1]);
        assertTrue($repo->holdBlocking('local', 't/contracts/deal.pdf') !== null, "{$name}: file");
        assertTrue($repo->holdBlocking('local', 't/legal/x.txt') !== null, "{$name}: under folder");
        assertTrue($repo->holdBlocking('local', 't/CONTRACTS') !== null, "{$name}: ancestor");
        assertEqual(null, $repo->holdBlocking('local', 't/other.txt'), "{$name}: unrelated");
    }
});

echo "\n{$cyan}══ _fluxfiles/ writes are private on a public S3 disk ══{$reset}\n\n";

test('N5: bookkeeping writes send ACL=private even when the disk defaults to public-read', function () {
    $dm = new DiskManager(['s3' => ['driver' => 's3', 'bucket' => 'b', 'region' => 'us-east-1',
        'key' => 'k', 'secret' => 's', 'visibility' => 'public']]);
    $fs = $dm->disk('s3');
    $seen = [];
    $dm->s3Client('s3')->getHandlerList()->setHandler(
        function (CommandInterface $cmd, RequestInterface $req) use (&$seen) {
            if ($cmd->getName() === 'PutObject') {
                $seen[(string) $cmd['Key']] = (string) ($cmd['ACL'] ?? '');
            }
            if (in_array($cmd->getName(), ['HeadObject', 'GetObject'], true)) {
                return Create::rejectionFor(new S3Exception('missing', $cmd, ['response' => new Response(404), 'code' => 'NotFound']));
            }
            return Create::promiseFor(new Result([]));
        }
    );
    // Control: a plain user write still gets the disk's public-read default.
    $fs->write('user/photo.jpg', 'x');
    $meta = new StorageMetadataHandler($dm);
    $meta->addTrash('s3', 'id1', ['original_key' => 'user/photo.jpg']);
    $meta->audit('s3', 'upload', ['user_id' => 'u', 'file_key' => 'user/photo.jpg']);
    $meta->addHold('s3', 'h1', ['path' => 'user/photo.jpg', 'released_at' => null]);
    FixityChecker::run($fs, 'user', fn (string $k): bool => false, []);
    assertEqual('public-read', $seen['user/photo.jpg'] ?? null, 'control');
    foreach (['_fluxfiles/trash.json', '_fluxfiles/audit.jsonl', '_fluxfiles/holds.json', 'user/_fluxfiles/fixity.json'] as $k) {
        assertEqual('private', $seen[$k] ?? null, $k);
    }
});

test('N5: local bookkeeping files keep their normal mode (no chmod to 0600)', function () {
    $root = tmpRoot('mode');
    $meta = new StorageMetadataHandler(new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]));
    $meta->addTrash('local', 'id1', ['original_key' => 'a.txt']);
    $meta->save('local', 'a.txt', ['title' => 'T']);
    clearstatcache();
    $expected = 0666 & ~umask();
    assertEqual($expected, fileperms("{$root}/_fluxfiles/trash.json") & 0777, 'trash mode');
    assertEqual('T', $meta->get('local', 'a.txt')['title'] ?? null, 'sidecar readable');
});

echo "\n{$cyan}══ TusServer ══{$reset}\n\n";

/** FileManager whose upload() lets a test run code inside the finalize window. */
class TusProbeFileManager extends FileManager
{
    /** @var callable|null */
    public $during = null;
    public int $uploads = 0;

    public function upload(string $disk, string $path, array $file, bool $forceUpload = false): array
    {
        $this->uploads++;
        if ($this->during !== null) {
            ($this->during)();
        }
        return ['key' => trim($path . '/' . $file['name'], '/'), 'name' => $file['name']];
    }
}

function tusEnv(string $prefix, string $user = 'u'): array {
    static $root = null, $state = null;
    $root ??= tmpRoot('tusdisk');
    $state ??= tmpRoot('tusstate');
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    $claims = new Claims($user, ['read', 'write'], ['local'], $prefix, 10, null, 0);
    $fm = new TusProbeFileManager($dm, $claims, new StorageMetadataHandler($dm));
    return [new TusServer($state), $fm, $claims];
}
function tusCreate(TusServer $tus, FileManager $fm, Claims $claims, string $name, int $len): array {
    $meta = 'filename ' . base64_encode($name) . ',disk ' . base64_encode('local');
    return $tus->handle('POST', '', ['tus-resumable' => '1.0.0', 'upload-length' => (string) $len, 'upload-metadata' => $meta],
        fopen('php://memory', 'r'), $fm, $claims);
}
function body(string $s) { $h = fopen('php://memory', 'w+'); fwrite($h, $s); rewind($h); return $h; }

test('B13: the unfinished-upload cap is per tenant+user, not per `sub`', function () {
    [$tus, $fm, $claims] = tusEnv('tenantA');
    for ($i = 0; $i < 20; $i++) {
        assertEqual(201, tusCreate($tus, $fm, $claims, "a{$i}.txt", 3)[0], "create {$i}");
    }
    expectApi(fn () => tusCreate($tus, $fm, $claims, 'a20.txt', 3), 'rate_limited', 429);
    [$tusB, $fmB, $claimsB] = tusEnv('tenantB'); // same `sub` "u", other tenant
    assertEqual(201, tusCreate($tusB, $fmB, $claimsB, 'b.txt', 3)[0], 'other tenant not blocked');
});

test('B13: a second final PATCH during completion is refused (upload() runs once)', function () {
    [$tus, $fm, $claims] = tusEnv('tenantC', 'racer');
    [$st, $h] = tusCreate($tus, $fm, $claims, 'race.txt', 4);
    assertEqual(201, $st);
    $id = basename($h['Location']);
    $hdr = ['tus-resumable' => '1.0.0', 'content-type' => 'application/offset+octet-stream'];
    $second = $del = null;
    $fm->during = function () use ($tus, $fm, $claims, $id, $hdr, &$second, &$del) {
        $fm->during = null;
        $second = $tus->handle('PATCH', $id, $hdr + ['upload-offset' => '4'], body(''), $fm, $claims)[0];
        $del = $tus->handle('DELETE', $id, ['tus-resumable' => '1.0.0'], body(''), $fm, $claims)[0];
    };
    [$st, , $result] = $tus->handle('PATCH', $id, $hdr + ['upload-offset' => '0'], body('abcd'), $fm, $claims);
    assertEqual(204, $st, 'first PATCH');
    assertTrue(is_array($result), 'first PATCH completed the upload');
    assertEqual(423, $second, 'second PATCH');
    assertEqual(1, $fm->uploads, 'upload() calls');
    assertEqual(423, $del, 'DELETE while finalizing');
    assertEqual(404, $tus->handle('HEAD', $id, ['tus-resumable' => '1.0.0'], body(''), $fm, $claims)[0], 'state cleaned up');
});

echo "\n{$cyan}══ embed.php ══{$reset}\n\n";

test('A-N2: an unknown role or edition throws, listing the valid values', function () use ($SECRET) {
    foreach ([['role' => 'editer'], ['edition' => 'enterprize']] as $bad) {
        try {
            fluxfiles_token(['user' => 'u', 'secret' => $SECRET] + $bad);
            throw new \RuntimeException('no exception for ' . json_encode($bad));
        } catch (\InvalidArgumentException $e) {
            assertTrue(str_contains($e->getMessage(), isset($bad['role']) ? 'viewer, editor, admin, superadmin' : 'free, pro'), $e->getMessage());
        }
    }
});

test('A-N2: valid / empty / differently-cased role and edition still mint', function () use ($SECRET) {
    foreach ([['role' => 'Editor'], ['role' => ''], ['edition' => 'free'], ['edition' => 'PRO'], ['edition' => '']] as $ok) {
        $p = JwtCompat::decode(fluxfiles_token(['user' => 'u', 'secret' => $SECRET] + $ok), $SECRET);
        assertEqual('u', $p->sub, json_encode($ok));
    }
});

test('A-N7: fluxfiles_embed() encodes JS values with json_encode and escapes attributes', function () {
    $html = fluxfiles_embed('https://ex.test/a"b', 'tok"</script><script>alert(1)//', 'lo"cal', 'picker',
        '100%" onmouseover="alert(1)', '600px;background:url(//evil)');
    assertTrue(!str_contains($html, '</script><script>alert'), 'no script breakout');
    assertTrue(!str_contains($html, 'onmouseover="'), 'no attribute breakout');
    assertTrue(!str_contains($html, 'background'), 'width/height restricted to a CSS length');
    assertTrue(str_contains($html, 'style="width:100%;height:600px"'), 'falls back to defaults');
    $bs = chr(92); // backslash, spelled out so no tool re-decodes the escapes
    assertTrue(str_contains($html, 'token: "tok' . $bs . 'u0022' . $bs . 'u003C/script' . $bs . 'u003E'), 'token JSON-encoded');
    assertTrue(str_contains($html, 'src="https://ex.test/a&quot;b/public/index.html"'), 'endpoint attr-escaped');
    assertTrue(str_contains($html, 'endpoint: "https://ex.test/a' . $bs . 'u0022b"'), 'endpoint JSON-encoded');
    $plain = fluxfiles_embed('https://ex.test', 'abc', 'local', 'picker', 'calc(100% - 20px)', '80vh');
    assertTrue(str_contains($plain, 'style="width:calc(100% - 20px);height:80vh"'), 'normal sizes pass');
});

@unlink($dbFile);
echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n\n";
exit($failed > 0 ? 1 : 0);
