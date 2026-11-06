<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Implemented by the `audit-export` module to own the integrity sweep behind
 * `POST /api/fm/fixity/check`. Core checks `instanceof` and otherwise falls back to its
 * own `FileManager::runFixityCheck()`.
 */
interface FixityProviderInterface
{
    /**
     * The report shape core reads: the lists `changed_unexplained` and `missing_unexplained`
     * (each `string[]` of TENANT-RELATIVE paths) are turned into one `fixity_mismatch` audit
     * line per entry (`changed` / `missing`). Everything else in the array is returned to the
     * client unchanged; the in-core report also carries `changed_explained`,
     * `missing_explained` and the counters of `FixityChecker::run()`.
     *
     * @param array<int,array<string,mixed>>|callable(?int):array<int,array<string,mixed>> $auditEntries the disk's audit rows (or a loader taking a `since` timestamp)
     * @return array<string,mixed>
     */
    public function runFixityCheck(FileManager $fm, string $disk, $auditEntries, int $budgetBytes = 1073741824): array;
}
