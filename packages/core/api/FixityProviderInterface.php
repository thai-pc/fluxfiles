<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Implemented by the `audit-export` module to own the integrity sweep behind
 * `POST /api/fm/fixity/check`. Core carries no sweep of its own: an `audit-export`
 * release that does not implement this answers 501 `module_update_required`.
 */
interface FixityProviderInterface
{
    /**
     * The report shape core reads: the lists `changed_unexplained` and `missing_unexplained`
     * (each `string[]` of TENANT-RELATIVE paths) are turned into one `fixity_mismatch` audit
     * line per entry (`changed` / `missing`). Everything else in the array is returned to the
     * client unchanged (the module's report also carries `changed_explained`,
     * `missing_explained` and its counters).
     *
     * Optional 5th parameter on the implementation, `bool $rebaseline = false` (NOT part of
     * this signature, so an older module keeps loading; callers pass it positionally and
     * answer 501 `module_update_required` when the method takes fewer than 5 parameters):
     * replace a baseline that is invalid, never one that is valid. The report then carries
     * `baseline_mac` (anchored as `mac=` on the `fixity_check` audit entry) and, after a
     * re-baseline, `rebaselined` + `baseline_invalid_reason`.
     *
     * @param array<int,array<string,mixed>>|callable(?int):array<int,array<string,mixed>> $auditEntries the disk's audit rows (or a loader taking a `since` timestamp)
     * @return array<string,mixed>
     */
    public function runFixityCheck(FileManager $fm, string $disk, $auditEntries, int $budgetBytes = 1073741824): array;
}
