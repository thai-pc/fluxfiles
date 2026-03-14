<?php

declare(strict_types=1);

namespace FluxFiles;

class Claims
{
    public function __construct(
        public readonly string $userId,
        public readonly array $permissions,
        public readonly array $allowedDisks,
        public readonly string $pathPrefix,
        public readonly int $maxUploadMb,
        public readonly ?array $allowedExt,
        public readonly int $maxStorageMb,
    ) {}

    public static function fromJwtPayload(object $payload): self
    {
        return new self(
            userId: (string) ($payload->sub ?? '0'),
            permissions: (array) ($payload->perms ?? ['read']),
            allowedDisks: (array) ($payload->disks ?? ['local']),
            pathPrefix: (string) ($payload->prefix ?? ''),
            maxUploadMb: (int) ($payload->max_upload ?? 10),
            allowedExt: isset($payload->allowed_ext) ? (array) $payload->allowed_ext : null,
            maxStorageMb: (int) ($payload->max_storage ?? 0), // 0 = unlimited
        );
    }

    public function hasPerm(string $perm): bool
    {
        return in_array($perm, $this->permissions, true);
    }

    public function hasDisk(string $disk): bool
    {
        return in_array($disk, $this->allowedDisks, true);
    }
}
