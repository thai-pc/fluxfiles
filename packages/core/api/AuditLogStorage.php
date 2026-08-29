<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Audit log stored in the user's own storage (_fluxfiles/audit.jsonl) — no SQLite needed.
 */
class AuditLogStorage
{
    private const MAX_LIST = 500;
    private const MAX_EXPORT = 200000; // Hard ceiling to bound memory on export

    private StorageMetadataHandler $storage;
    private array $claimsDisks;

    public function __construct(StorageMetadataHandler $storage, array $claimsDisks)
    {
        $this->storage = $storage;
        $this->claimsDisks = $claimsDisks;
    }

    public function log(
        string $userId,
        string $action,
        string $disk,
        string $fileKey,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $detail = null
    ): void {
        $context = [
            'user_id'   => $userId,
            'file_key'  => $fileKey,
            'ip'        => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];
        // Free-form extra context (e.g. the SSH command a `terminal` entry ran) —
        // the audit file_key convention doesn't fit every action.
        if ($detail !== null && $detail !== '') {
            $context['detail'] = $detail;
        }
        $this->storage->audit($disk, $action, $context);
    }

    /**
     * List audit entries — reads across every disk the user has access to.
     *
     * @param ?string $actor  Filter to a single actor (user_id), or null for all.
     * @param ?Claims  $claims Caller claims — entries are scoped to its path prefix.
     * @param array    $filters Optional: action, from (ts), to (ts), path (sub-prefix).
     */
    public function list(int $limit = 100, int $offset = 0, ?string $actor = null, ?Claims $claims = null, array $filters = []): array
    {
        $limit = max(1, min($limit, self::MAX_LIST));
        $all = [];
        foreach ($this->claimsDisks as $disk) {
            try {
                $entries = $this->storage->readAudit($disk, $actor);
                $all = array_merge($all, $entries);
            } catch (\Throwable $e) {
                // Skip disk if error
            }
        }

        // Scope to the caller's path prefix: an entry is only visible when its
        // file_key is inside scope. Default-deny entries with no/out-of-scope key
        // so a tenant can never read another tenant's activity from a shared
        // disk's audit log. Reuses the canonical Claims::isPathInScope() so the
        // boundary matches every other operation. (Empty prefix = no scoping.)
        if ($claims !== null && trim($claims->pathPrefix, '/') !== '') {
            $all = array_filter(
                $all,
                fn($e) => $claims->isPathInScope((string) ($e['file_key'] ?? ''))
            );
        }

        // Optional display filters (applied after the security scope).
        $action = isset($filters['action']) ? trim((string) $filters['action']) : '';
        if ($action !== '') {
            $all = array_filter($all, fn($e) => ($e['action'] ?? '') === $action);
        }
        if (isset($filters['from']) && $filters['from'] !== null && $filters['from'] !== '') {
            $from = (int) $filters['from'];
            $all = array_filter($all, fn($e) => ((int) ($e['created_at'] ?? 0)) >= $from);
        }
        if (isset($filters['to']) && $filters['to'] !== null && $filters['to'] !== '') {
            $to = (int) $filters['to'];
            $all = array_filter($all, fn($e) => ((int) ($e['created_at'] ?? 0)) <= $to);
        }
        $path = isset($filters['path']) ? trim((string) $filters['path'], '/') : '';
        if ($path !== '') {
            $all = array_filter($all, function ($e) use ($path) {
                $k = trim((string) ($e['file_key'] ?? ''), '/');
                return $k === $path || strpos($k, $path . '/') === 0;
            });
        }

        $all = array_values($all);
        usort($all, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        return array_slice($all, $offset, $limit);
    }

    /**
     * Every audit entry across live + archived logs, scoped and filtered the same
     * way as list() — but with no pagination (capped by MAX_EXPORT so a runaway
     * disk's history can't exhaust memory). Paid-module gated at the route; this is
     * a plain storage read.
     */
    public function exportAll(?Claims $claims = null, array $filters = []): array
    {
        $all = [];
        foreach ($this->claimsDisks as $disk) {
            try {
                $all = array_merge($all, $this->storage->readAudit($disk), $this->storage->readAuditArchive($disk));
            } catch (\Throwable $e) {
                // Skip disk if error
            }
        }

        if ($claims !== null && trim($claims->pathPrefix, '/') !== '') {
            $all = array_filter(
                $all,
                fn($e) => $claims->isPathInScope((string) ($e['file_key'] ?? ''))
            );
        }

        $action = isset($filters['action']) ? trim((string) $filters['action']) : '';
        if ($action !== '') {
            $all = array_filter($all, fn($e) => ($e['action'] ?? '') === $action);
        }
        if (isset($filters['from']) && $filters['from'] !== null && $filters['from'] !== '') {
            $from = (int) $filters['from'];
            $all = array_filter($all, fn($e) => ((int) ($e['created_at'] ?? 0)) >= $from);
        }
        if (isset($filters['to']) && $filters['to'] !== null && $filters['to'] !== '') {
            $to = (int) $filters['to'];
            $all = array_filter($all, fn($e) => ((int) ($e['created_at'] ?? 0)) <= $to);
        }
        $path = isset($filters['path']) ? trim((string) $filters['path'], '/') : '';
        if ($path !== '') {
            $all = array_filter($all, function ($e) use ($path) {
                $k = trim((string) ($e['file_key'] ?? ''), '/');
                return $k === $path || strpos($k, $path . '/') === 0;
            });
        }
        if (isset($filters['actor']) && $filters['actor'] !== null && $filters['actor'] !== '') {
            $actor = (string) $filters['actor'];
            $all = array_filter($all, fn($e) => ($e['user_id'] ?? '') === $actor);
        }

        $all = array_values($all);
        usort($all, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        return array_slice($all, 0, self::MAX_EXPORT);
    }
}
