<?php

declare(strict_types=1);

namespace FluxFiles;

class Claims
{
    /** @var string */
    public $userId;

    /** @var array */
    public $permissions;

    /** @var array */
    public $allowedDisks;

    /** @var string */
    public $pathPrefix;

    /** @var int */
    public $maxUploadMb;

    /** @var array|null */
    public $allowedExt;

    /** @var int */
    public $maxStorageMb;

    /** @var bool When true, write/delete operations are restricted to files uploaded by this user */
    public $ownerOnly;

    /** @var array<string, array> BYOB disk configs (decrypted) — diskName => config */
    public $byobDisks = [];

    /** @var int Max number of files allowed under the prefix (0 = unlimited) */
    public $maxFiles;

    /** @var bool|null Per-tenant AI auto-tag toggle. null = inherit server default (FLUXFILES_AI_AUTO_TAG). */
    public $aiAutoTag;

    /** @var int Per-tenant read rate limit / minute. 0 = inherit server default. */
    public $rateRead;

    /** @var int Per-tenant write rate limit / minute. 0 = inherit server default. */
    public $rateWrite;

    /** @var array<string,int>|null Per-tenant image variant widths (thumb/medium/large). null = inherit defaults. */
    public $variants;

    public function __construct(
        string $userId,
        array $permissions,
        array $allowedDisks,
        string $pathPrefix,
        int $maxUploadMb,
        ?array $allowedExt,
        int $maxStorageMb,
        bool $ownerOnly = false,
        array $byobDisks = [],
        int $maxFiles = 0,
        ?bool $aiAutoTag = null,
        int $rateRead = 0,
        int $rateWrite = 0,
        ?array $variants = null
    ) {
        $this->userId = $userId;
        $this->permissions = $permissions;
        $this->allowedDisks = $allowedDisks;
        $this->pathPrefix = $pathPrefix;
        $this->maxUploadMb = $maxUploadMb;
        $this->allowedExt = $allowedExt;
        $this->maxStorageMb = $maxStorageMb;
        $this->ownerOnly = $ownerOnly;
        $this->byobDisks = $byobDisks;
        $this->maxFiles = $maxFiles;
        $this->aiAutoTag = $aiAutoTag;
        $this->rateRead = max(0, $rateRead);
        $this->rateWrite = max(0, $rateWrite);
        $this->variants = $variants;
    }

    /**
     * Sanitize a per-tenant `variants` claim: keep only the known size names with
     * sane positive widths. Returns null when nothing usable remains (= inherit).
     *
     * @return array<string,int>|null
     */
    public static function sanitizeVariants($raw): ?array
    {
        if (!is_array($raw) && !is_object($raw)) {
            return null;
        }
        $out = [];
        foreach (['thumb', 'medium', 'large'] as $name) {
            $v = is_object($raw) ? ($raw->$name ?? null) : ($raw[$name] ?? null);
            if ($v === null) {
                continue;
            }
            $w = (int) $v;
            if ($w >= 16 && $w <= 8000) {
                $out[$name] = $w;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * @param object $payload JWT payload
     * @param string $secret  FLUXFILES_SECRET (needed to decrypt BYOB credentials)
     */
    public static function fromJwtPayload(object $payload, string $secret = ''): self
    {
        // Decrypt BYOB disk credentials if present
        $byobDisks = [];
        if (isset($payload->byob_disks) && is_object($payload->byob_disks) && $secret !== '') {
            foreach ($payload->byob_disks as $diskName => $encryptedBlob) {
                $config = CredentialEncryptor::decrypt((string) $encryptedBlob, $secret);
                CredentialEncryptor::validate((string) $diskName, $config);
                $byobDisks[(string) $diskName] = $config;
            }
        }

        return new self(
            (string) ($payload->sub ?? '0'),
            (array) ($payload->perms ?? ['read']),
            (array) ($payload->disks ?? ['local']),
            (string) ($payload->prefix ?? ''),
            (int) ($payload->max_upload ?? 10),
            isset($payload->allowed_ext) ? (array) $payload->allowed_ext : null,
            (int) ($payload->max_storage ?? 0),
            (bool) ($payload->owner_only ?? false),
            $byobDisks,
            (int) ($payload->max_files ?? 0),
            isset($payload->ai_auto_tag) ? (bool) $payload->ai_auto_tag : null,
            (int) ($payload->rate_read ?? 0),
            (int) ($payload->rate_write ?? 0),
            isset($payload->variants) ? self::sanitizeVariants($payload->variants) : null
        );
    }

    /**
     * Check if a disk is a BYOB (user-provided) disk.
     */
    public function isByobDisk(string $disk): bool
    {
        return isset($this->byobDisks[$disk]);
    }

    /**
     * Get the decrypted config for a BYOB disk.
     */
    public function getByobConfig(string $disk): ?array
    {
        return $this->byobDisks[$disk] ?? null;
    }

    public function hasPerm(string $perm): bool
    {
        return in_array($perm, $this->permissions, true);
    }

    public function hasDisk(string $disk): bool
    {
        return in_array($disk, $this->allowedDisks, true);
    }

    /**
     * Check if a path is within the user's allowed scope (pathPrefix).
     */
    public function isPathInScope(string $path): bool
    {
        $prefix = trim($this->pathPrefix, '/');
        if ($prefix === '') {
            return true;
        }
        $path = trim(str_replace(["\0", "\x00"], '', $path), '/');
        return $path === $prefix || strpos($path, $prefix . '/') === 0;
    }

    /**
     * Apply path prefix and normalize (remove .. and .).
     */
    public function scopePath(string $path): string
    {
        $path = str_replace(["\0", "\x00"], '', $path);
        $parts = explode('/', $path);
        $safe = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                continue;
            }
            if ($part !== '') {
                $safe[] = $part;
            }
        }
        $relative = implode('/', $safe);
        $prefix = trim($this->pathPrefix, '/');
        if ($prefix !== '') {
            // Idempotent prefixing — see FileManager::scopedPath(). A path already
            // inside the prefix is returned unchanged (`..`/`.` stripped above), and
            // the "/" boundary keeps prefix confusion (user_1 vs user_10) out.
            if ($relative === $prefix || strpos($relative, $prefix . '/') === 0) {
                return $relative;
            }
            // Fail closed on a cross-tenant path that targets a sibling under the
            // prefix's parent (e.g. prefix "users/42" + path "users/99/…").
            $parent = (strpos($prefix, '/') !== false) ? substr($prefix, 0, strrpos($prefix, '/')) : '';
            if ($parent !== '' && strpos($relative, $parent . '/') === 0) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }
            return $relative === '' ? $prefix : $prefix . '/' . $relative;
        }
        return $relative;
    }
}
