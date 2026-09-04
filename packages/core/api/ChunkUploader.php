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

        // CompleteMultipartUploadOutput carries no ContentLength — the client
        // declares a size at /chunk/init, but that's never checked against what
        // actually lands here (parts are PUT directly to S3 on presigned URLs
        // with no size condition). A HeadObject is the only way to learn the
        // REAL assembled size so the caller can re-validate it against
        // max_upload_mb/quota post-hoc, instead of trusting the client's claim.
        $head = $client->headObject([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);

        return [
            'key'      => $key,
            'location' => $result['Location'] ?? '',
            'size'     => (int) ($head['ContentLength'] ?? 0),
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

    /**
     * Delete a completed multipart object outright (NOT an abort — the upload
     * is already assembled). Used by handleChunkComplete's post-hoc
     * size/quota re-check: if the REAL assembled size violates the tenant's
     * limits, the object must not be left sitting in storage while the API
     * returns an error.
     */
    public function deleteObject(string $disk, string $key): array
    {
        $client = $this->diskManager->s3Client($disk);
        $config = $this->diskManager->config($disk);
        $bucket = $config['bucket'] ?? '';

        $client->deleteObject([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);

        return ['deleted' => true];
    }
}
