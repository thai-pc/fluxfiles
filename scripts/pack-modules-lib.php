<?php

/**
 * Pure helpers for scripts/pack-modules.php, split out so the catalogue
 * history/backfill logic is testable without any module repo (see
 * packages/core/tests/integration/test-update-client.php).
 */

declare(strict_types=1);

/**
 * The `require["fluxfiles/fluxfiles"]` constraint of a composer.json, or null when the
 * JSON is invalid, the key is missing, or the constraint does not parse. A parseable
 * floor is mandatory: an entry without one would be offered to every core.
 */
function fluxfiles_pack_requires_core(?string $composerJson): ?string
{
    if ($composerJson === null) {
        return null;
    }
    $decoded = json_decode($composerJson, true);
    $rc = is_array($decoded) ? ($decoded['require']['fluxfiles/fluxfiles'] ?? null) : null;
    if (!is_string($rc) || trim($rc) === '') {
        return null;
    }
    $rc = trim($rc);
    return \FluxFiles\CoreVersion::isValid($rc) ? $rc : null;
}

/**
 * Build the `releases` history for a module's new catalogue entry.
 *
 * Starts from the prior catalogue entry (demoted to history when its version differs)
 * plus its own `releases`. Every kept entry must carry a zip and a sha256 and a
 * parseable `requires_core`; one lacking the floor (built before it was recorded) is
 * backfilled via $backfill(version) -> composer.json text of that release, and DROPPED
 * when that fails, so an old build can never become "compatible with everyone".
 *
 * @param array<string,mixed>      $entry    the entry just built
 * @param array<string,mixed>|null $prior    the entry in the existing catalogue
 * @param callable(string):?string $backfill returns the release's composer.json, or null
 * @return list<array<string,mixed>> newest first, max 10
 */
function fluxfiles_pack_history(array $entry, ?array $prior, callable $backfill): array
{
    $history = is_array($prior) ? array_values($prior['releases'] ?? []) : [];
    if (is_array($prior) && ($prior['version'] ?? $entry['version']) !== $entry['version']) {
        unset($prior['releases']);
        array_unshift($history, $prior);
    }
    $kept = [];
    $seen = [(string) $entry['version'] => true];
    foreach ($history as $r) {
        if (!is_array($r)
            || ($r['version'] ?? '') === '' || isset($seen[(string) $r['version']])
            || ($r['sha256'] ?? '') === '' || ($r['zip'] ?? '') === '') {
            continue;
        }
        $need = is_string($r['requires_core'] ?? null) ? trim($r['requires_core']) : '';
        if ($need === '' || !\FluxFiles\CoreVersion::isValid($need)) {
            $need = fluxfiles_pack_requires_core($backfill((string) $r['version']));
            if ($need === null) {
                continue;
            }
        }
        $r['requires_core'] = $need;
        $seen[(string) $r['version']] = true;
        $kept[] = $r;
    }
    return array_slice($kept, 0, 10);
}
