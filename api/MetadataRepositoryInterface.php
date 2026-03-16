<?php

declare(strict_types=1);

namespace FluxFiles;

interface MetadataRepositoryInterface
{
    public function get(string $disk, string $key): ?array;

    public function save(string $disk, string $key, array $data): void;

    public function delete(string $disk, string $key): void;

    public function getBulk(string $disk, array $keys): array;

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = ''): array;

    public function trash(string $disk, string $key): void;

    public function trashChildren(string $disk, string $prefix): int;

    public function restore(string $disk, string $key): void;

    public function restoreChildren(string $disk, string $prefix): int;

    public function getTrashed(string $disk): array;

    public function isTrashed(string $disk, string $key): bool;

    public function getExpiredTrash(string $disk, int $days = 30): array;

    public function purge(string $disk, string $key): void;

    public function purgeChildren(string $disk, string $prefix): int;

    public function saveHash(string $disk, string $key, string $hash): void;

    public function findByHash(string $disk, string $hash): ?array;

    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void;

    public function countChildren(string $disk, string $prefix): int;
}
