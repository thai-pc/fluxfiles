<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Aws\{MockHandler, Result, S3\S3Client};
use FluxFiles\{ApiException, ChunkUploader, DiskManager};

class MultipartTestDisks extends DiskManager
{
    public S3Client $client;
    public function __construct(S3Client $client) { parent::__construct(['s3' => ['bucket' => 'test-bucket']]); $this->client = $client; }
    public function s3Client(string $name): S3Client { return $this->client; }
}
function makeChunker(array $responses, array &$commands): ChunkUploader {
    $mock = new MockHandler($responses);
    $client = new S3Client([
        'region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => function ($command, $request) use ($mock, &$commands) {
            $commands[] = ['name' => $command->getName(), 'args' => $command->toArray(), 'headers' => $request->getHeaders()];
            return $mock($command, $request);
        },
    ]);
    return new ChunkUploader(new MultipartTestDisks($client));
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn, string $code): void {
    try { $fn(); } catch (ApiException $e) { check($e->getErrorCode() === $code, $e->getErrorCode()); return; }
    throw new RuntimeException('Expected ' . $code);
}
$failed = 0; $passed = 0;
function test(string $name, callable $fn): void {
    global $failed, $passed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}

test('oversized upload is refused before complete or delete can destroy old bytes', function () {
    $commands = [];
    $chunker = makeChunker([new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"a"', 'Size' => 2097152]]])], $commands);
    rejects(fn() => $chunker->complete('s3', 'existing.txt', 'upload', [['PartNumber' => 1, 'ETag' => '"a"']], function (int $size) {
        check($size === 2097152, 'real size not used');
        throw new ApiException('Too large', 413, 'upload_too_large');
    }), 'upload_too_large');
    check(array_column($commands, 'name') === ['ListParts'], 'completion/deletion happened before validation');
});

test('paginated listing binds size to selected ETags, validates first, and uses conditional completion', function () {
    $commands = [];
    $chunker = makeChunker([
        new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"a"', 'Size' => 10]], 'IsTruncated' => true, 'NextPartNumberMarker' => 1]),
        new Result(['Parts' => [['PartNumber' => 2, 'ETag' => '"b"', 'Size' => 20], ['PartNumber' => 3, 'ETag' => '"unused"', 'Size' => 999]]]),
        new Result(['Location' => 'https://example.test/result']),
    ], $commands);
    $validated = false;
    $result = $chunker->complete('s3', 'new.txt', 'upload', [['PartNumber' => 1, 'ETag' => '"a"'], ['PartNumber' => 2, 'ETag' => '"b"']], function (int $size) use (&$validated, &$commands) {
        check($size === 30 && count($commands) === 2, 'validation order/size incorrect'); $validated = true;
    }, false);
    check($validated && $result['size'] === 30, 'callback or size missing');
    check($commands[1]['args']['PartNumberMarker'] === 1, 'pagination missing');
    check($commands[2]['args']['IfNoneMatch'] === '*', 'concurrent destination could be overwritten');
    check($commands[2]['name'] === 'CompleteMultipartUpload', 'not completed');
});

test('wrong ETag cannot borrow the size of a different part', function () {
    $commands = [];
    $chunker = makeChunker([new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"new"', 'Size' => 1]]])], $commands);
    rejects(fn() => $chunker->complete('s3', 'file.txt', 'upload', [['PartNumber' => 1, 'ETag' => '"old"']]), 'invalid_parts');
    check(count($commands) === 1, 'changed part was completed');
});

test('empty, duplicate and unordered parts are rejected without S3 writes', function () {
    foreach ([[], [['PartNumber' => 1, 'ETag' => 'a'], ['PartNumber' => 1, 'ETag' => 'a']], [['PartNumber' => 2, 'ETag' => 'b'], ['PartNumber' => 1, 'ETag' => 'a']]] as $parts) {
        $commands = []; $chunker = makeChunker([], $commands);
        rejects(fn() => $chunker->complete('s3', 'file.txt', 'upload', $parts), 'invalid_parts');
        check($commands === [], 'invalid request reached S3');
    }
});

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
