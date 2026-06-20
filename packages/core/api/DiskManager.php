<?php

declare(strict_types=1);

namespace FluxFiles;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

class DiskManager
{
    private array $disks = [];
    private array $s3Clients = [];
    private array $configs;

    public function __construct(array $configs)
    {
        $this->configs = $configs;
    }

    public function disk(string $name): Filesystem
    {
        if (!isset($this->disks[$name])) {
            if (!isset($this->configs[$name])) {
                throw new ApiException("Disk '{$name}' is not configured", 400);
            }
            $this->disks[$name] = $this->build($name, $this->configs[$name]);
        }

        return $this->disks[$name];
    }

    public function s3Client(string $name): S3Client
    {
        if (!isset($this->s3Clients[$name])) {
            // Force disk init which also creates the S3 client
            $this->disk($name);
        }

        if (!isset($this->s3Clients[$name])) {
            throw new ApiException("Disk '{$name}' is not an S3-compatible disk", 400);
        }

        return $this->s3Clients[$name];
    }

    /**
     * Presigned GET URL for an object on an S3-compatible disk, or null when the
     * disk isn't S3 or presigning fails. Lets a caller redirect (302) to the
     * bucket instead of proxying the bytes through the app server.
     */
    public function presignGetUrl(string $name, string $key, int $ttl = 3600): ?string
    {
        $cfg = $this->config($name);
        if (($cfg['driver'] ?? '') !== 's3') {
            return null;
        }
        try {
            $client = $this->s3Client($name);
            $cmd = $client->getCommand('GetObject', ['Bucket' => $cfg['bucket'] ?? '', 'Key' => $key]);
            return (string) $client->createPresignedRequest($cmd, '+' . max(1, $ttl) . ' seconds')->getUri();
        } catch (\Throwable $e) {
            error_log('FluxFiles: presign GET failed — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Register a BYOB (Bring Your Own Bucket) disk at runtime.
     * Only S3-compatible drivers are allowed — local driver is rejected for security.
     */
    public function registerByobDisk(string $name, array $config): void
    {
        if (($config['driver'] ?? '') === 'local') {
            throw new ApiException("BYOB disk '{$name}' cannot use local driver", 403);
        }

        $this->configs[$name] = $config;

        // Clear cached instances so next call rebuilds with new config
        unset($this->disks[$name], $this->s3Clients[$name]);
    }

    public function config(string $name): array
    {
        return $this->configs[$name] ?? [];
    }

    private function build(string $name, array $cfg): Filesystem
    {
        $driver = $cfg['driver'] ?? 'local';

        if ($driver === 'local') {
            $root = $cfg['root'] ?? __DIR__ . '/../storage/uploads';
            if (!is_dir($root)) {
                mkdir($root, 0755, true);
            }
            $adapter = new LocalFilesystemAdapter($root);
        } elseif ($driver === 's3') {
            $s3Params = [
                'credentials' => [
                    'key'    => $cfg['key'] ?? '',
                    'secret' => $cfg['secret'] ?? '',
                ],
                'region'  => $cfg['region'] ?? 'us-east-1',
                'version' => 'latest',
            ];

            if (!empty($cfg['endpoint'])) {
                $s3Params['endpoint'] = $cfg['endpoint'];
                $s3Params['use_path_style_endpoint'] = true;
            }

            $client = new S3Client($s3Params);

            // Only native AWS S3 marked public should send an object ACL (public-read).
            // Everyone else — private AWS disks, and all R2/MinIO endpoint disks — must
            // NOT send an ACL: modern S3 buckets default to "Bucket owner enforced"
            // (ACLs disabled) and reject any ACL header, and R2 has no ACL support.
            // Strip the ACL param from every command so writes/copies/multipart succeed.
            $wantsAcl = empty($cfg['endpoint']) && ($cfg['visibility'] ?? 'private') === 'public';
            if (!$wantsAcl) {
                $client->getHandlerList()->appendInit(
                    Middleware::mapCommand(static function (CommandInterface $command) {
                        unset($command['ACL']);
                        return $command;
                    }),
                    'fluxfiles.strip_acl'
                );
            }

            $this->s3Clients[$name] = $client;

            $adapter = new AwsS3V3Adapter($client, $cfg['bucket'] ?? '');

            // R2/MinIO: disable retain_visibility to avoid GetObjectAcl reads. Public
            // access is handled at the URL layer (public_url / public bucket).
            if (!empty($cfg['endpoint'])) {
                return new Filesystem($adapter, ['retain_visibility' => false]);
            }

            // Native AWS S3 public disk → default writes to public-read ACL so the
            // direct object URL is readable (requires a bucket that allows ACLs).
            if ($wantsAcl) {
                return new Filesystem($adapter, ['visibility' => 'public']);
            }
        } else {
            throw new ApiException("Unknown disk driver: {$driver}", 400);
        }

        return new Filesystem($adapter);
    }
}
