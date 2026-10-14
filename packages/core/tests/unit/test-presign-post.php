<?php

/**
 * Presigned POST (size-enforcing form upload) + the allow_presign_put claim.
 * Offline: SigV4 policies are built locally, nothing is sent to S3. The live
 * round-trip (incl. the bucket refusing an oversized body) is in
 * tests/e2e/test-s3-live.php, which runs against LocalStack in CI.
 *
 * Usage: php tests/unit/test-presign-post.php
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
function ppFm(array $claimProps = [], string $endpoint = 'https://s3.us-east-1.amazonaws.com'): FileManager {
    $dm = new DiskManager(['s3' => ['driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'b',
        'key' => 'AKIDEXAMPLE', 'secret' => 'x', 'endpoint' => $endpoint]]);
    $c = new Claims('u', ['read', 'write'], ['s3'], 'tenant', 5, ['png', 'txt'], 0, false);
    foreach ($claimProps as $k => $v) { $c->{$k} = $v; }
    return new FileManager($dm, $c, new StorageMetadataHandler($dm));
}
/** @return array<int,mixed> the decoded policy conditions */
function policyOf(array $fields): array {
    $p = json_decode((string) base64_decode($fields['Policy'] ?? $fields['policy'] ?? ''), true);
    return $p['conditions'] ?? [];
}

echo "\n{$cyan}══ Presigned POST ══{$reset}\n\n";

test('POST returns url + fields whose policy pins size, key and Content-Type', function () {
    $r = ppFm()->presign('s3', 'pics/a.png', 'POST', 600, 1234);
    assertTrue(str_starts_with($r['url'], 'https://'), 'form action url');
    assertTrue(($r['fields']['key'] ?? '') === 'tenant/pics/a.png', 'scoped key field');
    assertTrue(($r['fields']['Content-Type'] ?? '') === 'image/png', 'type field');
    assertTrue(isset($r['fields']['X-Amz-Signature']), 'signed');
    assertTrue($r['max_bytes'] === 1234, 'max_bytes echoed');
    $cond = json_encode(policyOf($r['fields']));
    assertTrue(strpos($cond, '["content-length-range",0,1234]') !== false, 'size range: ' . $cond);
    assertTrue(strpos($cond, '["eq","$key","tenant\/pics\/a.png"]') !== false, 'key pinned');
    assertTrue(strpos($cond, '["eq","$Content-Type","image\/png"]') !== false, 'type pinned');
});

test('POST TTL is capped like PUT (15 min)', function () {
    $r = ppFm()->presign('s3', 'a.png', 'POST', 86400, 10);
    assertTrue($r['expires_at'] - time() <= 900, 'capped');
});

test('POST runs the upload guards: size, extension, declared size required', function () {
    expectCode('upload_too_large', fn () => ppFm()->presign('s3', 'a.png', 'POST', 600, 6 * 1024 * 1024));
    expectCode('ext_not_allowed', fn () => ppFm()->presign('s3', 'a.html', 'POST', 600, 10));
    expectCode('missing_param', fn () => ppFm()->presign('s3', 'a.png', 'POST', 600, 0));
});

test('Cloudflare R2 → 400 presign_post_unsupported', function () {
    expectCode('presign_post_unsupported', fn () => ppFm([], 'https://abc123.r2.cloudflarestorage.com')
        ->presign('s3', 'a.png', 'POST', 600, 10));
});

test('allow_presign_put=false refuses PUT but keeps POST and GET', function () {
    $fm = ppFm(['allowPresignPut' => false]);
    expectCode('presign_put_disabled', fn () => $fm->presign('s3', 'a.png', 'PUT', 600, 10));
    assertTrue(isset($fm->presign('s3', 'a.png', 'POST', 600, 10)['fields']), 'POST still works');
    assertTrue(isset($fm->presign('s3', 'a.png', 'GET', 600)['url']), 'GET still works');
});

test('allow_presign_put defaults to true; decodes from the JWT', function () {
    assertTrue(Claims::fromJwtPayload((object) ['sub' => 'u'])->allowPresignPut === true, 'default on');
    assertTrue(Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_presign_put' => false])->allowPresignPut === false, 'off');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
