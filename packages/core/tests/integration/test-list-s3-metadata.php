<?php

/**
 * What listing a folder costs on S3/R2, and that the cheaper reads stay safe:
 * one GET per bookkeeping file (only a 404 is "absent"), concurrent HEADs for
 * files that are not in the index, and image URLs that repeat between listings.
 *
 * In-memory bucket behind the AWS SDK handler, no network.
 *   php tests/integration/test-list-s3-metadata.php
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
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function same($want, $got, string $m = ''): void
{
    if ($want !== $got) { throw new \RuntimeException($m . ' want ' . json_encode($want) . ' got ' . json_encode($got)); }
}

/** @return array{0:FileManager,1:StorageMetadataHandler,2:object} */
function env(array $files): array
{
    $dm = new DiskManager(['s3' => ['driver' => 's3', 'bucket' => 'b', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's', 'url_ttl' => 3600]]);
    $st = (object) ['files' => $files, 'calls' => [], 'deny' => [], 'open' => 0, 'maxOpen' => 0, 'mtime' => 1700000000];
    $dm->s3Client('s3')->getHandlerList()->setHandler(function (CommandInterface $cmd) use ($st) {
        $k = (string) ($cmd['Key'] ?? '');
        $st->calls[] = $cmd->getName() . ' ' . ($k !== '' ? $k : (string) ($cmd['Prefix'] ?? ''));
        $err = fn (int $code) => Create::rejectionFor(new S3Exception("HTTP $code", $cmd, ['response' => new Response($code), 'code' => "E$code"]));
        if (in_array($k, $st->deny, true)) { return $err(403); }
        $date = new \Aws\Api\DateTimeResult('@' . $st->mtime);
        switch ($cmd->getName()) {
            case 'HeadObject':
                // Settles later, so the test can see how many are in flight together.
                $st->open++; $st->maxOpen = max($st->maxOpen, $st->open);
                $p = new Promise(function () use (&$p, $st, $k, $date, $err, $cmd) {
                    $st->open--;
                    isset($st->files[$k])
                        ? $p->resolve(new Result(['ContentLength' => 1, 'LastModified' => $date, 'Metadata' => ['fm-title' => 'title of ' . $k]]))
                        : $p->reject(new S3Exception('HTTP 404', $cmd, ['response' => new Response(404), 'code' => 'NotFound']));
                });
                return $p;
            case 'GetObject':
                return isset($st->files[$k]) ? Create::promiseFor(new Result(['Body' => \GuzzleHttp\Psr7\Utils::streamFor($st->files[$k])])) : $err(404);
            case 'ListObjectsV2':
                $p = (string) ($cmd['Prefix'] ?? ''); $c = [];
                foreach ($st->files as $key => $body) {
                    if (strpos($key, $p) === 0 && strpos(substr($key, strlen($p)), '/') === false) {
                        $c[] = ['Key' => $key, 'Size' => strlen($body), 'LastModified' => $date];
                    }
                }
                return Create::promiseFor(new Result(['Contents' => $c, 'KeyCount' => count($c), 'IsTruncated' => false]));
            default:
                return Create::promiseFor(new Result([]));
        }
    });
    $claims = new Claims('u', ['read', 'write'], ['s3'], '', 50, null, 0, false);
    $meta = new StorageMetadataHandler($dm);
    return [new FileManager($dm, $claims, $meta), $meta, $st];
}
$ten = [];
for ($i = 0; $i < 10; $i++) { $ten["pics/a$i.jpg"] = 'x'; }

test('bookkeeping files are read with one GET and no HEAD', function () use ($ten) {
    [$fm, , $st] = env($ten + ['_fluxfiles/index.json' => json_encode(array_fill_keys(array_keys($ten), ['title' => 'indexed']))]);
    $r = $fm->list('s3', 'pics', 50);
    same('indexed', $r['items'][0]['meta']['title']);
    foreach (['index', 'dirs', 'holds'] as $f) {
        same(1, count(array_keys($st->calls, "GetObject _fluxfiles/$f.json")), "$f GET");
        same(0, count(array_keys($st->calls, "HeadObject _fluxfiles/$f.json")), "$f HEAD");
    }
    same(0, $st->maxOpen, 'indexed files need no HEAD at all');
});

test('files missing from the index get their own metadata, requested together', function () use ($ten) {
    [, $meta, $st] = env($ten);
    $keys = array_merge(array_keys($ten), ['pics/gone.jpg']);
    $bulk = $meta->getBulk('s3', $keys);
    same($keys, array_keys($bulk), 'input order kept');
    foreach (array_keys($ten) as $k) { same('title of ' . $k, $bulk[$k]['title']); }
    same(null, $bulk['pics/gone.jpg'], 'one failed HEAD is null and does not affect the others');
    same(true, $st->maxOpen > 1, 'concurrent, max in flight ' . $st->maxOpen);
    same(true, $st->maxOpen <= 16, 'bounded, max in flight ' . $st->maxOpen);
});

test('an index that cannot be read is an error, never an empty index written back', function () use ($ten) {
    [$fm, $meta, $st] = env($ten + ['_fluxfiles/index.json' => '{"pics/a0.jpg":{"title":"keep"}}']);
    $st->deny = ['_fluxfiles/index.json'];
    foreach ([fn () => $fm->list('s3', 'pics', 50), fn () => $meta->indexFile('s3', 'pics/new.jpg', ['title' => 'n'])] as $do) {
        $threw = false;
        try { $do(); } catch (\Throwable $e) { $threw = true; }
        same(true, $threw, 'must fail');
    }
    same([], array_values(array_filter($st->calls, fn ($c) => $c === 'PutObject _fluxfiles/index.json')), 'index not overwritten');
});

test('an unreadable hold manifest still refuses (fail closed); a missing one is no holds', function () use ($ten) {
    [, $meta, $st] = env($ten);
    same([], $meta->allHolds('s3'));
    $st->deny = ['_fluxfiles/holds.json'];
    $code = '';
    try { $meta->allHolds('s3'); } catch (\FluxFiles\ApiException $e) { $code = $e->getErrorCode(); }
    same('legal_hold_active', $code);
});

test('listed image URLs repeat, change with the object, and never outlive url_ttl', function () use ($ten) {
    $vars = [];
    foreach (array_keys($ten) as $k) { $vars['pics/_variants/' . basename($k) . '_thumb.webp'] = 'w'; }
    [$fm, , $st] = env($ten + $vars + ['pics/doc.pdf' => 'p']);
    $row = function (string $name) use ($fm): array {
        foreach ($fm->list('s3', 'pics', 50)['items'] as $it) { if ($it['name'] === $name) { return $it; } }
        throw new \RuntimeException('no row');
    };
    $urls = fn () => [$row('a0.jpg')['url'], $row('a0.jpg')['variants']['thumb']['url']];
    $relist = function () use ($urls) { $a = $urls(); sleep(1); return $a === $urls(); };
    // Retry once: a 15-minute window boundary may fall between the two listings.
    same(true, $relist() || $relist(), 'same URLs one second later');
    $before = $urls();
    $now = time();
    parse_str((string) parse_url($before[0], PHP_URL_QUERY), $q);
    $start = \DateTime::createFromFormat('Ymd\THis\Z', $q['X-Amz-Date'], new \DateTimeZone('UTC'))->getTimestamp();
    $exp = $start + (int) $q['X-Amz-Expires'];
    same(true, $exp <= $now + 3600 && $exp > $now + 3600 - 901, 'expires at most 15 min early, never late');
    same(true, strpos($q['response-cache-control'], 'private, max-age=900') === 0, 'browser may reuse it');
    $st->mtime++;
    $after = $urls();
    same(true, $before[0] !== $after[0] && $before[1] !== $after[1], 'replaced object -> new URLs');
    parse_str((string) parse_url($row('doc.pdf')['url'], PHP_URL_QUERY), $pdf);
    same(false, isset($pdf['response-cache-control']), 'only images');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
