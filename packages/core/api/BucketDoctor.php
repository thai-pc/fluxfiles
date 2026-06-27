<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Bucket Doctor — diagnoses a disk's storage backend so BYOB tenants can verify
 * credentials, permissions, CORS and presigning before (or after) wiring it up.
 *
 * Runs entirely with the disk's own credentials; checks that need a permission
 * the (least-privilege) policy may not grant degrade to a warning rather than a
 * hard failure, so the doctor never demands more access than FluxFiles itself.
 *
 * A tiny probe object is written under the reserved `_fluxfiles/.doctor/`
 * namespace and deleted again (the delete doubles as the delete-permission
 * check). On a partial failure a leftover probe is cleaned up best-effort.
 */
class BucketDoctor
{
    private const PROBE_DIR = '_fluxfiles/.doctor';

    private DiskManager $disks;

    public function __construct(DiskManager $disks)
    {
        $this->disks = $disks;
    }

    /**
     * @return array{disk:string,driver:string,summary:string,checks:array,remediation:array}
     */
    public function diagnose(string $disk, ?string $origin = null): array
    {
        $config = $this->disks->config($disk);
        $driver = $config['driver'] ?? 'local';

        if ($driver === 'sftp') {
            return $this->sftpReport($disk);
        }
        if ($driver !== 's3') {
            return $this->localReport($disk, $driver);
        }

        $bucket   = (string) ($config['bucket'] ?? '');
        $s3       = $this->disks->s3Client($disk);
        $fs       = $this->disks->disk($disk);
        $probeKey = self::PROBE_DIR . '/' . bin2hex(random_bytes(8)) . '.txt';
        $probeMp  = $probeKey . '.mp';
        $body     = 'fluxfiles-doctor-' . time();
        $checks   = [];
        $wrote    = false;

        // 1. Reachability + list.
        try {
            $s3->listObjectsV2(['Bucket' => $bucket, 'MaxKeys' => 1]);
            $checks[] = $this->ok('reachability', 'Bucket is reachable and listable.');
        } catch (\Throwable $e) {
            $checks[] = $this->fail('reachability', $this->awsMsg($e, 'Cannot reach or list the bucket.'),
                'Verify credentials, bucket name, region/endpoint, and the s3:ListBucket permission.');
        }

        // 2. Write a probe object.
        try {
            $fs->write($probeKey, $body);
            $wrote = true;
            $checks[] = $this->ok('write', 'Wrote a probe object.');
        } catch (\Throwable $e) {
            $checks[] = $this->fail('write', $this->awsMsg($e, 'Cannot write to the bucket.'),
                'Grant s3:PutObject on the bucket.');
        }

        // 3. Read it back, and 4. presign a GET for it.
        if ($wrote) {
            try {
                $got = $fs->read($probeKey);
                $checks[] = $got === $body
                    ? $this->ok('read', 'Read the probe object back.')
                    : $this->warn('read', 'Read the probe back but the content differed.', null);
            } catch (\Throwable $e) {
                $checks[] = $this->fail('read', $this->awsMsg($e, 'Cannot read from the bucket.'),
                    'Grant s3:GetObject on the bucket.');
            }

            try {
                $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $probeKey]);
                $url = (string) $s3->createPresignedRequest($cmd, '+5 minutes')->getUri();
                [$code, $resp] = $this->httpGet($url);
                $checks[] = ($code === 200 && $resp === $body)
                    ? $this->ok('presign', 'Presigned GET URL serves the object.')
                    : $this->warn('presign', "Presigned GET returned HTTP {$code}.",
                        'Private disks serve files via presigned URLs — make sure the endpoint/region is reachable from clients.');
            } catch (\Throwable $e) {
                $checks[] = $this->warn('presign', $this->awsMsg($e, 'Could not presign a GET URL.'), null);
            }
        }

        // 5. Multipart (large-file uploads).
        try {
            $mp = $s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $probeMp]);
            $s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $probeMp, 'UploadId' => $mp['UploadId']]);
            $checks[] = $this->ok('multipart', 'Multipart upload works (files larger than 10 MB).');
        } catch (\Throwable $e) {
            $checks[] = $this->warn('multipart', $this->awsMsg($e, 'Multipart upload is not available.'),
                'Grant multipart upload permissions (s3:PutObject + s3:AbortMultipartUpload) for large files.');
        }

        // 6. CORS (warn-only — the #1 cause of broken browser uploads).
        $checks[] = $this->corsCheck($s3, $bucket, $origin);

        // 7. Versioning (informational).
        try {
            $v = $s3->getBucketVersioning(['Bucket' => $bucket]);
            $checks[] = $this->info('versioning', 'Versioning: ' . ($v['Status'] ?? 'Disabled') . '.');
        } catch (\Throwable $e) {
            $checks[] = $this->info('versioning', 'Could not read versioning (s3:GetBucketVersioning not granted).');
        }

        // Cleanup probe — the delete also exercises the delete permission.
        if ($wrote) {
            try {
                $fs->delete($probeKey);
                $checks[] = $this->ok('delete', 'Deleted the probe object (cleanup).');
            } catch (\Throwable $e) {
                $checks[] = $this->fail('delete', $this->awsMsg($e, 'Cannot delete from the bucket.'),
                    'Grant s3:DeleteObject — FluxFiles needs it for delete and move.');
            }
        }

        return [
            'disk'        => $disk,
            'driver'      => $driver,
            'summary'     => $this->summarize($checks),
            'checks'      => $checks,
            'remediation' => $this->remediation($bucket, $origin),
        ];
    }

    private function corsCheck($s3, string $bucket, ?string $origin): array
    {
        try {
            $res   = $s3->getBucketCors(['Bucket' => $bucket]);
            $rules = $res['CORSRules'] ?? [];
            foreach ($rules as $r) {
                $methods  = $r['AllowedMethods'] ?? [];
                $origins  = $r['AllowedOrigins'] ?? [];
                $methodOk = in_array('PUT', $methods, true) || in_array('*', $methods, true);
                $originOk = $origin === null || in_array('*', $origins, true) || in_array($origin, $origins, true);
                if ($methodOk && $originOk) {
                    return $this->ok('cors', 'CORS allows browser uploads.');
                }
            }
            return $this->warn('cors', 'CORS is configured but does not allow PUT' . ($origin ? " from {$origin}" : '') . '.',
                'Add a CORS rule allowing GET/PUT from your app origin.');
        } catch (\Throwable $e) {
            $code = $e instanceof \Aws\Exception\AwsException ? (string) $e->getAwsErrorCode() : '';
            if ($code === 'NoSuchCORSConfiguration') {
                return $this->warn('cors', 'No CORS configuration — browser presigned PUT uploads will fail.',
                    'Add a CORS rule allowing GET/PUT from your app origin.');
            }
            return $this->warn('cors', 'Could not verify CORS (s3:GetBucketCors not granted).', null);
        }
    }

    /**
     * Diagnose an SFTP disk with SFTP-appropriate messages (not the local-FS
     * report). Surfaces the real failure mode a user hits — wrong password/key,
     * unreachable host, host-key mismatch, or a read-only / wrong root — instead
     * of a misleading "Local storage is not writable". Note: FluxFiles only uses
     * the SFTP subsystem (file transfer); it never opens a shell, so an
     * SFTP-only account with no terminal access works fine.
     */
    private function sftpReport(string $disk): array
    {
        $checks = [];

        // 1. Connect + authenticate. Listing the root forces the connection, so a
        //    wrong password/key, unreachable host, or host-key mismatch lands here.
        try {
            $fs = $this->disks->disk($disk);
            iterator_to_array($fs->listContents('', false));
            $checks[] = $this->ok('reachability', 'Connected and authenticated to the SFTP host.');
        } catch (\Throwable $e) {
            $checks[] = $this->fail(
                'reachability',
                'Cannot connect or authenticate: ' . $this->shortMsg($e),
                'Check SFTP_HOST / SFTP_PORT, the username, and the password OR private key '
                . '(a wrong password/key authenticates as "Login failed"). If host-key pinning '
                . 'is enabled, verify SFTP_HOST_FINGERPRINT. The account only needs SFTP '
                . '(sftp-subsystem) access — an interactive shell is not required.'
            );
            return ['disk' => $disk, 'driver' => 'sftp', 'summary' => $this->summarize($checks), 'checks' => $checks, 'remediation' => []];
        }

        // 2. Write (covers a read-only account or a non-writable root).
        $probe = self::PROBE_DIR . '/' . bin2hex(random_bytes(8)) . '.txt';
        $wrote = false;
        try {
            $fs->write($probe, 'fluxfiles-doctor');
            $wrote = true;
            $checks[] = $this->ok('write', 'Wrote a probe file to the SFTP root.');
        } catch (\Throwable $e) {
            $checks[] = $this->fail('write', 'Cannot write: ' . $this->shortMsg($e),
                'Grant the SFTP user write permission on the root path, or set SFTP_ROOT to a writable directory.');
        }

        // 3. Read back, then clean up (best-effort).
        if ($wrote) {
            try {
                $fs->read($probe);
                $checks[] = $this->ok('read', 'Read the probe file back.');
            } catch (\Throwable $e) {
                $checks[] = $this->fail('read', 'Cannot read back: ' . $this->shortMsg($e),
                    'Grant the SFTP user read permission on the root path.');
            }
            try {
                $fs->delete($probe);
            } catch (\Throwable $e) {
                // best-effort cleanup — a leftover probe file is harmless
            }
        }

        return ['disk' => $disk, 'driver' => 'sftp', 'summary' => $this->summarize($checks), 'checks' => $checks, 'remediation' => []];
    }

    /**
     * The most useful one-line reason from an exception. Flysystem wraps the real
     * phpseclib cause ("Login failed", "Connection closed", host-key mismatch) as
     * the previous exception, so walk to the root before trimming/capping.
     */
    private function shortMsg(\Throwable $e): string
    {
        $root = $e;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }
        $m = trim(explode("\n", $root->getMessage())[0]);
        if ($m === '') {
            $m = trim(explode("\n", $e->getMessage())[0]);
        }
        if ($m === '') {
            return (new \ReflectionClass($e))->getShortName();
        }
        return strlen($m) > 200 ? substr($m, 0, 197) . '…' : $m;
    }

    private function localReport(string $disk, string $driver): array
    {
        $fs    = $this->disks->disk($disk);
        $probe = self::PROBE_DIR . '/' . bin2hex(random_bytes(8)) . '.txt';
        $checks = [];
        try {
            $fs->write($probe, 'ok');
            $fs->delete($probe);
            $checks[] = $this->ok('write', 'Local storage is writable.');
        } catch (\Throwable $e) {
            $checks[] = $this->fail('write', 'Local storage is not writable: ' . $e->getMessage(),
                'Make the storage directory writable by the web server user.');
        }
        return [
            'disk' => $disk, 'driver' => $driver,
            'summary' => $this->summarize($checks), 'checks' => $checks, 'remediation' => [],
        ];
    }

    /** @return array{0:int,1:string} [httpCode, body] */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    }

    private function awsMsg(\Throwable $e, string $fallback): string
    {
        if ($e instanceof \Aws\Exception\AwsException) {
            $code = $e->getAwsErrorCode();
            return $fallback . ($code ? " ({$code})" : '');
        }
        return $fallback;
    }

    private function summarize(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('fail', $statuses, true)) {
            return 'fail';
        }
        if (in_array('warn', $statuses, true)) {
            return 'warn';
        }
        return 'ok';
    }

    private function remediation(string $bucket, ?string $origin): array
    {
        $o = $origin ?: 'https://your-app.example';
        return [
            'iam_policy' => json_encode([
                'Version'   => '2012-10-17',
                'Statement' => [[
                    'Effect'   => 'Allow',
                    'Action'   => ['s3:ListBucket'],
                    'Resource' => "arn:aws:s3:::{$bucket}",
                ], [
                    'Effect'   => 'Allow',
                    'Action'   => ['s3:GetObject', 's3:PutObject', 's3:DeleteObject', 's3:AbortMultipartUpload'],
                    'Resource' => "arn:aws:s3:::{$bucket}/*",
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'cors' => json_encode([[
                'AllowedOrigins' => [$o],
                'AllowedMethods' => ['GET', 'PUT'],
                'AllowedHeaders' => ['*'],
                'ExposeHeaders'  => ['ETag'],
                'MaxAgeSeconds'  => 3000,
            ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function ok(string $id, string $message): array
    {
        return ['id' => $id, 'status' => 'ok', 'message' => $message, 'fix' => null];
    }

    private function warn(string $id, string $message, ?string $fix): array
    {
        return ['id' => $id, 'status' => 'warn', 'message' => $message, 'fix' => $fix];
    }

    private function fail(string $id, string $message, ?string $fix): array
    {
        return ['id' => $id, 'status' => 'fail', 'message' => $message, 'fix' => $fix];
    }

    private function info(string $id, string $message): array
    {
        return ['id' => $id, 'status' => 'info', 'message' => $message, 'fix' => null];
    }
}
