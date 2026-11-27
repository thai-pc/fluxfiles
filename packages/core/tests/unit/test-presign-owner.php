<?php

/**
 * Presigned PUT/POST uploads keep the uploader as owner and honour the collision
 * policy / versioning. Offline: a fake S3 handler answers HEAD for the "existing"
 * keys; presigned URLs/policies are built locally.
 *
 * Usage: php tests/unit/test-presign-owner.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function expectCode(string $code, callable $fn): void {
    try { $fn(); } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === $code, "expected {$code}, got " . (string) $e->getErrorCode());
        return;
    }
    throw new \RuntimeException("expected {$code}, nothing thrown");
}

/**
 * @param array<string,array<string,string>> $existing key => object metadata headers
 */
function poFm(array $existing, array $claimProps = []): FileManager {
    $cfg = ['s3' => ['driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'b', 'key' => 'AKIDEXAMPLE', 'secret' => 'x']];
    $dm = new DiskManager($cfg);
    $handler = function (\Aws\CommandInterface $cmd, $req) use ($existing) {
        $key = $cmd['Key'] ?? '';
        if (in_array($cmd->getName(), ['HeadObject', 'GetObject'], true) && isset($existing[$key])) {
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result([
                'Metadata' => $existing[$key], 'ContentLength' => 3, 'ContentType' => 'image/png',
                'LastModified' => new \DateTimeImmutable('2026-01-01'),
            ]));
        }
        if ($cmd->getName() === 'ListObjectsV2') {
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['Contents' => [], 'CommonPrefixes' => []]));
        }
        return \GuzzleHttp\Promise\Create::rejectionFor(new \Aws\S3\Exception\S3Exception(
            'Not Found', $cmd, ['code' => 'NotFound', 'response' => new \GuzzleHttp\Psr7\Response(404)]
        ));
    };
    $client = new \Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $handler]);
    foreach (['s3Clients' => ['s3' => $client],
              'disks' => ['s3' => new \League\Flysystem\Filesystem(new \League\Flysystem\AwsS3V3\AwsS3V3Adapter($client, 'b'))]] as $prop => $val) {
        $p = new \ReflectionProperty($dm, $prop);
        $p->setAccessible(true);
        $p->setValue($dm, $val);
    }
    $c = new Claims('alice', ['read', 'write'], ['s3'], 'tenant', 5, ['png', 'txt'], 0, false);
    foreach ($claimProps as $k => $v) { $c->{$k} = $v; }
    return new FileManager($dm, $c, new StorageMetadataHandler($dm));
}
function policyCond(array $fields): string {
    $p = json_decode((string) base64_decode($fields['Policy'] ?? $fields['policy'] ?? ''), true);
    return json_encode($p['conditions'] ?? []);
}

echo "\n{$cyan}══ Presigned uploads: owner, collision, versioning ══{$reset}\n\n";

test('PUT signs the owner header in and returns it for the client to send', function () {
    $r = poFm([])->presign('s3', 'a.png', 'PUT', 600, 10);
    assertTrue(($r['headers']['x-amz-meta-fm-uploaded-by'] ?? '') === 'alice', 'headers: ' . json_encode($r['headers'] ?? null));
    assertTrue(strpos($r['url'], 'x-amz-meta-fm-uploaded-by') !== false, 'owner header is part of the signature');
    assertTrue(strpos($r['url'], 'SignedHeaders=host%3Bx-amz-meta-fm-uploaded-by') !== false, 'signed header');
    assertTrue($r['path'] === 'a.png', 'path echoed');
});

test('POST pins the owner field with a policy condition', function () {
    $r = poFm([])->presign('s3', 'a.png', 'POST', 600, 10);
    assertTrue(($r['fields']['x-amz-meta-fm-uploaded-by'] ?? '') === 'alice', 'field present');
    assertTrue(strpos(policyCond($r['fields']), '["eq","$x-amz-meta-fm-uploaded-by","alice"]') !== false, 'condition: ' . policyCond($r['fields']));
});

test('upload_collision=reject: existing key -> 409 name_conflict (PUT and POST)', function () {
    $fm = poFm(['tenant/a.png' => ['fm-uploaded-by' => 'alice']], ['uploadCollision' => 'reject']);
    expectCode('name_conflict', fn () => $fm->presign('s3', 'a.png', 'PUT', 600, 10));
    expectCode('name_conflict', fn () => $fm->presign('s3', 'a.png', 'POST', 600, 10));
});

test('upload_collision=rename: a free name is minted and echoed as path', function () {
    $fm = poFm(['tenant/a.png' => ['fm-uploaded-by' => 'bob']], ['uploadCollision' => 'rename']);
    $r = $fm->presign('s3', 'a.png', 'PUT', 600, 10);
    assertTrue($r['path'] === 'a (1).png', 'path ' . $r['path']);
    assertTrue(strpos(rawurldecode($r['url']), 'tenant/a (1).png') !== false, 'url targets the renamed key');
    assertTrue($r['headers']['x-amz-meta-fm-uploaded-by'] === 'alice', 'renamed object is the caller\'s own');
    $p = $fm->presign('s3', 'a.png', 'POST', 600, 10);
    assertTrue($p['fields']['key'] === 'tenant/a (1).png' && $p['path'] === 'a (1).png', 'POST key renamed');
});

test('upload_collision=overwrite keeps the existing headers (owner, title, tags)', function () {
    $fm = poFm(['tenant/a.png' => ['fm-uploaded-by' => 'alice', 'fm-title' => 'Hello', 'fm-tags' => 'x,y']], ['uploadCollision' => 'overwrite']);
    $r = $fm->presign('s3', 'a.png', 'PUT', 600, 10);
    assertTrue($r['path'] === 'a.png', 'same key');
    $h = $r['headers']; ksort($h);
    assertTrue($h === ['x-amz-meta-fm-tags' => 'x,y', 'x-amz-meta-fm-title' => 'Hello', 'x-amz-meta-fm-uploaded-by' => 'alice'], json_encode($h));
    $p = $fm->presign('s3', 'a.png', 'POST', 600, 10);
    assertTrue(strpos(policyCond($p['fields']), '["eq","$x-amz-meta-fm-title","Hello"]') !== false, 'title pinned');
});

test('overwrite of someone else\'s file under owner_only -> 403', function () {
    $fm = poFm(['tenant/a.png' => ['fm-uploaded-by' => 'bob']], ['uploadCollision' => 'overwrite', 'ownerOnly' => true]);
    expectCode('owner_only', fn () => $fm->presign('s3', 'a.png', 'PUT', 600, 10));
    expectCode('owner_only', fn () => $fm->presign('s3', 'a.png', 'POST', 600, 10));
});

test('overwrite with Versioning active -> 409 presign_overwrite_versioned; new key is fine', function () {
    $fm = poFm(['tenant/a.png' => ['fm-uploaded-by' => 'alice']], ['uploadCollision' => 'overwrite']);
    $fm->setVersionKeeper(static function () {});
    expectCode('presign_overwrite_versioned', fn () => $fm->presign('s3', 'a.png', 'PUT', 600, 10));
    expectCode('presign_overwrite_versioned', fn () => $fm->presign('s3', 'a.png', 'POST', 600, 10));
    assertTrue(isset($fm->presign('s3', 'new.png', 'PUT', 600, 10)['url']), 'new key mints');
});

test('GET presign is untouched (no headers/path)', function () {
    $r = poFm([])->presign('s3', 'a.png', 'GET', 600);
    assertTrue(!isset($r['headers']) && !isset($r['path']), 'plain GET');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
