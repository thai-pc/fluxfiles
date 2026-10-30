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

    private MetadataRepositoryInterface $storage;
    private array $claimsDisks;

    public function __construct(MetadataRepositoryInterface $storage, array $claimsDisks)
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
        // Free-form extra context (e.g. the `jti` of a revoked share link) —
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
        // A `from` date lets the backend skip older rows (a DB backend filters in
        // SQL instead of loading the whole audit table). The full filter below
        // still runs, so this only narrows what is read, never what is returned.
        $since = isset($filters['from']) && $filters['from'] !== null && $filters['from'] !== '' ? (int) $filters['from'] : null;
        $all = [];
        foreach ($this->claimsDisks as $disk) {
            try {
                $entries = $since !== null && method_exists($this->storage, 'readAuditWindow')
                    ? $this->storage->readAuditWindow($disk, $since, null, $actor)
                    : $this->storage->readAudit($disk, $actor);
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

        return array_slice(self::newestFirst($all), $offset, $limit);
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
                // Archive first: it holds the OLDER lines rotated out of the live log,
                // so archive+live is write order — what newestFirst() tie-breaks on.
                $rows = array_merge($this->storage->readAuditArchive($disk), $this->storage->readAudit($disk));
            } catch (\Throwable $e) {
                continue; // Skip disk if error
            }
            // Filter and cap each disk before the next is read: only the newest
            // MAX_EXPORT overall survive, so no disk needs to contribute more — the
            // working set stays at one disk's history plus the capped results,
            // instead of every disk's full history at once.
            // Reversed back to write order: the final newestFirst() tie-breaks on it.
            $rows = self::newestFirst(self::applyExportFilters($rows, $claims, $filters));
            $all = array_merge($all, array_reverse(array_slice($rows, 0, self::MAX_EXPORT)));
            unset($rows);
        }

        return array_slice(self::newestFirst($all), 0, self::MAX_EXPORT);
    }

    /**
     * @param array<int,array<string,mixed>> $all
     * @return array<int,array<string,mixed>>
     */
    private static function applyExportFilters(array $all, ?Claims $claims, array $filters): array
    {
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
        return array_values($all);
    }

    /**
     * Newest first. `created_at` is whole seconds, so several entries from one
     * request (an upload then its trash, a backup run's per-file lines) share a
     * timestamp — sorting on it alone kept them in write order (oldest first)
     * inside a newest-first list, so an upload showed ABOVE its own trash. Every
     * backend returns a disk's
     * entries in write order (JSONL append order / DB `id ASC`), so the position
     * in that list is the real sequence: later-written wins a tie.
     *
     * @param array<int|string,array<string,mixed>> $all
     * @return list<array<string,mixed>>
     */
    private static function newestFirst(array $all): array
    {
        $all = array_values($all);
        $idx = array_keys($all);
        usort($idx, static fn (int $i, int $j): int =>
            (($all[$j]['created_at'] ?? 0) <=> ($all[$i]['created_at'] ?? 0)) ?: ($j <=> $i));
        return array_map(static fn (int $i) => $all[$i], $idx);
    }
}
