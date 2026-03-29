<?php

declare(strict_types=1);

namespace FluxFiles;

interface MetadataRepositoryInterface
{
    public function get(string $disk, string $key): ?array;

    public function save(string $disk, string $key, array $data): void;

    public function delete(string $disk, string $key): void;

    public function deleteChildren(string $disk, string $prefix): int;

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int;

    public function getBulk(string $disk, array $keys): array;

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = ''): array;

    public function saveHash(string $disk, string $key, string $hash): void;

    public function findByHash(string $disk, string $hash): ?array;

    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void;

    public function countChildren(string $disk, string $prefix): int;
}
