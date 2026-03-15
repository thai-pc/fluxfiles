<?php

declare(strict_types=1);

namespace FluxFiles;

use Aws\S3\S3Client;

class ChunkUploader
{
    private const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB

    /** @var DiskManager */
    private $diskManager;

    public function __construct(DiskManager $diskManager)
    {
        $this->diskManager = $diskManager;
    }

    public function initiate(string $disk, string $key): array
    {
        $client = $this->diskManager->s3Client($disk);
        $config = $this->diskManager->config($disk);
        $bucket = $config['bucket'] ?? '';

        $result = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);

        return [
            'upload_id'  => $result['UploadId'],
            'key'        => $key,
            'chunk_size' => self::CHUNK_SIZE,
        ];
    }

    public function presignPart(string $disk, string $key, string $uploadId, int $partNumber, int $ttl = 3600): array
    {
        $client = $this->diskManager->s3Client($disk);
        $config = $this->diskManager->config($disk);
        $bucket = $config['bucket'] ?? '';

        $cmd = $client->getCommand('UploadPart', [
            'Bucket'     => $bucket,
            'Key'        => $key,
            'UploadId'   => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        $request = $client->createPresignedRequest($cmd, "+{$ttl} seconds");

        return [
            'url'         => (string) $request->getUri(),
            'part_number' => $partNumber,
            'expires_at'  => time() + $ttl,
        ];
    }

    public function complete(string $disk, string $key, string $uploadId, array $parts): array
    {
        $client = $this->diskManager->s3Client($disk);
        $config = $this->diskManager->config($disk);
        $bucket = $config['bucket'] ?? '';

        $multipartUpload = [];
        foreach ($parts as $part) {
            $multipartUpload[] = [
                'PartNumber' => (int) $part['PartNumber'],
                'ETag'       => $part['ETag'],
            ];
        }

        $result = $client->completeMultipartUpload([
            'Bucket'          => $bucket,
            'Key'             => $key,
            'UploadId'        => $uploadId,
            'MultipartUpload' => ['Parts' => $multipartUpload],
        ]);

        return [
            'key'      => $key,
            'location' => $result['Location'] ?? '',
        ];
    }

    public function abort(string $disk, string $key, string $uploadId): array
    {
        $client = $this->diskManager->s3Client($disk);
        $config = $this->diskManager->config($disk);
        $bucket = $config['bucket'] ?? '';

        $client->abortMultipartUpload([
            'Bucket'   => $bucket,
            'Key'      => $key,
            'UploadId' => $uploadId,
        ]);

        return ['aborted' => true];
    }
}
