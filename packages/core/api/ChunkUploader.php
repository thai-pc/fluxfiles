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

    public function complete(string $disk, string $key, string $uploadId, array $parts, ?callable $validate = null, bool $overwrite = true): array
    {
        $client = $this->diskManager->s3Client($disk);
        $config = $this->diskManager->config($disk);
        $bucket = $config['bucket'] ?? '';

        $multipartUpload = [];
        $previous = 0;
        foreach ($parts as $part) {
            if (!is_array($part) || !isset($part['PartNumber'], $part['ETag'])
                || filter_var($part['PartNumber'], FILTER_VALIDATE_INT) === false
                || (int) $part['PartNumber'] <= $previous || (int) $part['PartNumber'] > 10000
                || !is_string($part['ETag']) || $part['ETag'] === '') {
                throw new ApiException('Invalid multipart parts', 400, 'invalid_parts');
            }
            $previous = (int) $part['PartNumber'];
            $multipartUpload[] = [
                'PartNumber' => (int) $part['PartNumber'],
                'ETag'       => $part['ETag'],
            ];
        }
        if ($multipartUpload === []) {
            throw new ApiException('Multipart parts are required', 400, 'invalid_parts');
        }

        // Bind the real size to the exact part numbers AND ETags being completed.
        // S3 rejects completion if a part changes after this check. Validate before
        // replacing a live key: deleting a rejected object afterwards loses its old bytes.
        $uploaded = [];
        $marker = 0;
        do {
            $page = $client->listParts([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
                'PartNumberMarker' => $marker,
            ]);
            foreach ($page['Parts'] ?? [] as $part) {
                $uploaded[(int) $part['PartNumber']] = $part;
            }
            $truncated = (bool) ($page['IsTruncated'] ?? false);
            $next = (int) ($page['NextPartNumberMarker'] ?? 0);
            if ($truncated && $next <= $marker) {
                throw new ApiException('Invalid multipart listing', 502, 'invalid_parts');
            }
            $marker = $next;
        } while ($truncated);

        $size = 0;
        foreach ($multipartUpload as $part) {
            $stored = $uploaded[$part['PartNumber']] ?? null;
            if ($stored === null || !isset($stored['Size'], $stored['ETag'])
                || trim((string) $stored['ETag'], '"') !== trim($part['ETag'], '"')
                || (int) $stored['Size'] < 0) {
                throw new ApiException('Multipart part is missing or changed', 409, 'invalid_parts');
            }
            $size += (int) $stored['Size'];
        }
        if ($validate !== null) {
            $validate($size);
        }

        $args = [
            'Bucket'          => $bucket,
            'Key'             => $key,
            'UploadId'        => $uploadId,
            'MultipartUpload' => ['Parts' => $multipartUpload],
        ];
        if (!$overwrite) {
            $args['IfNoneMatch'] = '*';
        }
        try {
            $result = $client->completeMultipartUpload($args);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if (in_array($e->getStatusCode(), [409, 412], true)) {
                throw new ApiException('Destination changed during upload; retry the upload', 409, 'name_conflict');
            }
            throw $e;
        }

        return [
            'key'      => $key,
            'location' => $result['Location'] ?? '',
            'size'     => $size,
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
     * Delete an object explicitly. Upload policy failures must NOT call this:
     * validate them before completion so an existing destination remains intact.
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
