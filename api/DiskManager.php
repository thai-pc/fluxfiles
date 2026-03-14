<?php

declare(strict_types=1);

namespace FluxFiles;

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
            $this->s3Clients[$name] = $client;

            $adapter = new AwsS3V3Adapter($client, $cfg['bucket'] ?? '');
        } else {
            throw new ApiException("Unknown disk driver: {$driver}", 400);
        }

        return new Filesystem($adapter);
    }
}
