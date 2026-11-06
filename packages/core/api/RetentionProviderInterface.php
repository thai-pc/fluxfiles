<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Implemented by the `legal-hold` module to own the retention sweep behind
 * `POST /api/fm/retention/run`. Core checks `instanceof` and otherwise falls back to
 * its own `FileManager::runRetention()` (removed one core release after modules ship this).
 *
 * The module builds the sweep on the FileManager seams (`visibleFiles`, `isHeld`,
 * `removeKeys`), so the delete perm, scope, owner_only and legal-hold checks stay core's.
 */
interface RetentionProviderInterface
{
    /**
     * The report shape core reads and returns to the client, and whose `items` it audits:
     *
     * `{dry_run:bool, rules:int, matched:int, acted:int, held:int, errors:int, truncated:bool,
     *   items: list<{path:string (tenant-relative), action:'trash'|'delete', age_days:int, result:string}>}`
     *
     * `result` is `would_trash|would_delete|trashed|deleted|held` or the refusing error code.
     * For each item with `result` of `trashed` or `deleted`, core writes a `retention_<action>`
     * audit line (`age_days=N`).
     *
     * @return array<string,mixed>
     */
    public function runRetention(FileManager $fm, string $disk, bool $dryRun, int $maxActions = 500): array;
}
