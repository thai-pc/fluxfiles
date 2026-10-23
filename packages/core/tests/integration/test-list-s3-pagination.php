<?php

/**
 * list() on S3/R2: pages after the first that resume among the files ask the
 * bucket for keys after the cursor (ListObjectsV2 StartAfter) instead of
 * re-listing the whole folder. Same items, same order, same filters as the
 * full listing; folders still all come first.
 *
 * Runs against an in-memory bucket behind the AWS SDK handler — no network.
 *
 * Usage:
 *   php tests/integration/test-list-s3-pagination.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

/**
 * A FileManager over an in-memory bucket. $calls counts ListObjectsV2 requests
 * and records whether each one used StartAfter.
 */
function s3Env(array $keys, string $prefix = '', bool $showHidden = false): array
{
    $dm = new DiskManager(['s3' => ['driver' => 's3', 'bucket' => 'b', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's']]);
    $store = array_fill_keys($keys, 'x');
    ksort($store, SORT_STRING);
    $stats = (object) ['lists' => 0, 'startAfter' => 0, 'prefixes' => []];
    $dm->s3Client('s3')->getHandlerList()->setHandler(
        function (CommandInterface $cmd, RequestInterface $req) use (&$store, $stats) {
            $k = (string) ($cmd['Key'] ?? '');
            $missing = fn () => Create::rejectionFor(new S3Exception('missing', $cmd, ['response' => new Response(404), 'code' => 'NotFound']));
            switch ($cmd->getName()) {
                case 'HeadObject':
                    return isset($store[$k])
                        ? Create::promiseFor(new Result(['ContentLength' => 1, 'LastModified' => new \Aws\Api\DateTimeResult('@1700000000')]))
                        : $missing();
                case 'ListObjectsV2':
                    $stats->lists++;
                    $p = (string) ($cmd['Prefix'] ?? '');
                    $fromStart = ($cmd['StartAfter'] ?? '') === '' && ($cmd['ContinuationToken'] ?? '') === ''
                        && (int) ($cmd['MaxKeys'] ?? 1000) > 1;
                    $stats->prefixes[] = $p . ($fromStart ? '|from-start' : '|resumed');
                    $delim = (string) ($cmd['Delimiter'] ?? '');
                    $after = (string) ($cmd['StartAfter'] ?? '');
                    if ($after !== '') {
                        $stats->startAfter++;
                    }
                    $token = (string) ($cmd['ContinuationToken'] ?? '');
                    if ($token !== '') {
                        $after = base64_decode($token);
                    }
                    $max = (int) ($cmd['MaxKeys'] ?? 1000);
                    $contents = []; $prefixes = []; $n = 0; $last = '';
                    foreach ($store as $key => $_) {
                        $key = (string) $key;
                        if (strpos($key, $p) !== 0 || strcmp($key, $after) <= 0) {
                            continue;
                        }
                        $rest = substr($key, strlen($p));
                        if ($delim !== '' && ($i = strpos($rest, $delim)) !== false) {
                            $cp = $p . substr($rest, 0, $i + 1);
                            if (!isset($prefixes[$cp])) {
                                if ($n >= $max) { break; }
                                $prefixes[$cp] = true; $n++; $last = $cp . "\u{10FFFF}";
                            }
                            continue;
                        }
                        if ($n >= $max) { break; }
                        $contents[] = ['Key' => $key, 'Size' => 1, 'LastModified' => new \Aws\Api\DateTimeResult('@1700000000')];
                        $n++; $last = $key;
                    }
                    $more = false;
                    foreach ($store as $key => $_) {
                        if (strpos((string) $key, $p) === 0 && strcmp((string) $key, $last) > 0) { $more = $n >= $max; break; }
                    }
                    $r = ['Contents' => $contents, 'CommonPrefixes' => array_map(fn ($c) => ['Prefix' => $c], array_keys($prefixes)),
                        'KeyCount' => $n, 'IsTruncated' => $more];
                    if ($more) { $r['NextContinuationToken'] = base64_encode($last); }
                    return Create::promiseFor(new Result($r));
                case 'GetObject':
                    return $missing(); // no metadata index / sidecars in this bucket
                default:
                    return Create::promiseFor(new Result([]));
            }
        }
    );
    // Presigned GET URLs are built through the same handler list (init step) —
    // count them so a test can tell "signed the page" from "signed the folder".
    $stats->presigns = 0;
    $dm->s3Client('s3')->getHandlerList()->appendInit(\Aws\Middleware::tap(function ($cmd) use ($stats) {
        if ($cmd->getName() === 'GetObject') {
            $stats->presigns++;
        }
    }), 'test.count');
    $c = new Claims('u', ['read'], ['s3'], $prefix, 50, null, 0, false);
    $c->showHidden = $showHidden;
    return [new FileManager($dm, $c, new StorageMetadataHandler($dm)), $stats];
}

/** Every page of $path at $limit, following next_cursor. */
function allPages(FileManager $fm, string $path, int $limit): array
{
    $pages = [];
    $cursor = '';
    do {
        $r = $fm->list('s3', $path, $limit, $cursor);
        $pages[] = $r;
        $cursor = (string) ($r['next_cursor'] ?? '');
    } while ($cursor !== '' && count($pages) < 100);
    return $pages;
}

function names(array $pages): array
{
    $out = [];
    foreach ($pages as $p) {
        foreach ($p['items'] as $it) {
            $out[] = ($it['type'] === 'dir' ? 'D:' : '') . $it['name'];
        }
    }
    return $out;
}

/** 3 folders, 1 dotfile, 1 legacy sidecar, 1 genuine *.meta.json and 25 files under pics/. */
function fixture(): array
{
    $keys = ['pics/.env', 'pics/a.meta.json', 'pics/f010.jpg.meta.json'];
    foreach (['zz', 'aa', 'mm'] as $d) {
        $keys[] = "pics/{$d}/inner.jpg";
    }
    for ($i = 0; $i < 25; $i++) {
        $keys[] = sprintf('pics/f%03d.jpg', $i);
    }
    $keys[] = 'pics/_variants/f000.jpg_thumb.webp';
    $keys[] = 'other/x.jpg';
    return $keys;
}

echo "\n{$cyan}══ list(): S3 pagination via StartAfter ══{$reset}\n\n";

test('pages match the full listing exactly: folders first, files in key order, filters applied', function () {
    [$fm] = s3Env(fixture());
    $paged = names(allPages($fm, 'pics', 7));
    $full = names([$fm->list('s3', 'pics', 1000)]);
    assertTrue($paged === $full, "paged:\n" . implode(',', $paged) . "\nfull:\n" . implode(',', $full));
    assertTrue(array_slice($full, 0, 3) === ['D:aa', 'D:mm', 'D:zz'], 'folders first: ' . implode(',', array_slice($full, 0, 3)));
    assertTrue(!in_array('.env', $full, true) && !in_array('f010.jpg.meta.json', $full, true), 'dotfile + legacy sidecar hidden');
    assertTrue(in_array('a.meta.json', $full, true), 'a genuine *.meta.json stays visible');
    assertTrue(!in_array('D:_variants', $full, true), '_variants hidden');
});

test('later pages that resume among files use StartAfter, not a full re-list', function () {
    [$fm, $stats] = s3Env(fixture());
    $first = $fm->list('s3', 'pics', 10);
    $stats->prefixes = [];
    assertTrue($first['total'] === 29 && $first['next_cursor'] !== null, 'first page: full listing with a total: ' . json_encode([$first['total'], $first['next_cursor']]));
    $second = $fm->list('s3', 'pics', 10, $first['next_cursor']);
    assertTrue($stats->startAfter === 1, "StartAfter calls: {$stats->startAfter}");
    // The folder is read from the cursor on — never re-listed from its first key.
    // (Other LISTs are the page's own bookkeeping, e.g. its `_variants/`.)
    assertTrue(!in_array('pics/|from-start', $stats->prefixes, true), 'folder LISTs for page 2: ' . json_encode($stats->prefixes));
    assertTrue(in_array('pics/|resumed', $stats->prefixes, true), 'resumed from the cursor');
    assertTrue($second['total'] === null && count($second['items']) === 10, 'page 2: 10 items, total unknown');
});

test('a cursor still among the folders falls back to the full listing', function () {
    [$fm, $stats] = s3Env(fixture());
    $first = $fm->list('s3', 'pics', 2);              // ends on folder "mm"
    assertTrue($first['next_cursor'] === 'pics/mm', 'cursor is a folder: ' . json_encode($first['next_cursor']));
    $second = $fm->list('s3', 'pics', 2, $first['next_cursor']);
    assertTrue($stats->startAfter === 0, 'no StartAfter for a folder cursor');
    assertTrue(names([$second]) === ['D:zz', 'a.meta.json'], 'continues correctly: ' . implode(',', names([$second])));
});

test('tenant prefix: keys and cursors stay relative to the token root', function () {
    $keys = array_map(fn ($k) => "tenant/{$k}", fixture());
    [$fm, $stats] = s3Env($keys, 'tenant');
    $paged = names(allPages($fm, 'pics', 6));
    assertTrue(count($paged) === 29, 'all 29 visible entries: ' . count($paged) . ' ' . implode(',', $paged));
    assertTrue($stats->startAfter > 0, 'fast path used under a prefix');
    $p2 = $fm->list('s3', 'pics', 6, 'pics/f005.jpg');
    assertTrue(strpos((string) $p2['items'][0]['key'], 'tenant/') !== 0, 'keys come back unscoped');
});

test('a page signs URLs for its own rows only, not for the whole folder', function () {
    $keys = [];
    for ($i = 0; $i < 300; $i++) {
        $keys[] = sprintf('big/f%04d.txt', $i);
    }
    [$fm, $stats] = s3Env($keys);
    $stats->presigns = 0;
    $page = $fm->list('s3', 'big', 20);
    assertTrue(count($page['items']) === 20 && $page['total'] === 300, 'page of 20 out of 300');
    assertTrue($stats->presigns <= 20, "presigned GETs for one page: {$stats->presigns}");
    assertTrue(isset($page['items'][0]['url']), 'rows still carry their URL');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n\n";
exit($failed > 0 ? 1 : 0);
