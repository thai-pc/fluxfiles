<?php

declare(strict_types=1);

/**
 * One-off cleanup for the B2 fix (2026-09, LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md):
 * before that fix, every subscription renewal minted a NEW `active` row instead of
 * retiring the old one, so a customer with N renewals could have N `active` rows for
 * the same (buyer, plan) — each independently eligible for needingReminder(),
 * producing contradictory reminder emails (an "expired" nag for the stale row next
 * to a "renews soon" nag for the current one) every billing cycle.
 *
 * LicenseIssuer::issue() now calls LicenseStore::supersedeActiveForBuyerPlan() on
 * every fresh issuance, so this self-heals going forward — but only on that pair's
 * NEXT renewal. Rows that were already duplicated before the fix shipped sit there
 * until then. This script closes that gap immediately by keeping only the
 * newest-issued `active` row per (buyer email, plan) and marking the rest
 * `superseded`, the same status supersedeActiveForBuyerPlan() uses. Pairs are keyed
 * on the email, not the display name: two buyers with the same name are not the
 * same customer.
 *
 * Safe to run more than once (no-op once there are no duplicates left) and safe to
 * run alongside the reminder cron/webhook traffic (each supersede is a single
 * targeted UPDATE, not a table-wide lock).
 *
 * Usage:
 *   php backfill-supersede-duplicates.php            # dry run, prints report only
 *   php backfill-supersede-duplicates.php --apply     # actually supersede
 *
 * Env: FLUXFILES_LICENSE_DB (sqlite path, default ./data/licenses.sqlite) — same as
 * every other script in this directory.
 */

if (PHP_SAPI !== 'cli') {   // never reachable over HTTP, even if the dir is served
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/LicenseStore.php';

use FluxFiles\LicenseServer\LicenseStore;

$apply = in_array('--apply', $argv, true);

$store = new LicenseStore();
$pairs = $store->duplicateActiveBuyerPlans();

if (empty($pairs)) {
    echo "[backfill] no duplicate active (buyer, plan) pairs found — nothing to do\n";
    exit(0);
}

echo '[backfill] ' . count($pairs) . ' duplicate (buyer, plan) pair(s) found'
    . ($apply ? '' : ' (dry run — pass --apply to actually supersede)') . "\n";

$totalSuperseded = 0;

foreach ($pairs as $pair) {
    $email = (string) $pair['email'];
    $plan = (string) $pair['plan'];
    $rows = $store->activeRowsForBuyerPlan($email, $plan);

    if (count($rows) < 2) {
        // Raced with concurrent issuance between the two queries above — already
        // down to one active row for this pair, nothing left to do.
        continue;
    }

    $keep = $rows[0]; // newest issued, per activeRowsForBuyerPlan()'s ORDER BY
    $stale = array_slice($rows, 1);

    // Buyer-supplied text: strip control characters before it reaches a terminal.
    $shown = preg_replace('/[\x00-\x1F\x7F]/', '?', $email);
    echo "[backfill] buyer={$shown} plan={$plan}: keeping jti={$keep['jti']}"
        . ' (issued=' . date('c', (int) $keep['issued']) . '),'
        . ' superseding ' . count($stale) . " older row(s):\n";
    foreach ($stale as $row) {
        echo "           - jti={$row['jti']} issued=" . date('c', (int) $row['issued'])
            . ' expires=' . ($row['expires'] !== null ? date('c', (int) $row['expires']) : 'never') . "\n";
    }

    if ($apply) {
        $n = $store->supersedeActiveForBuyerPlan($email, $plan, (string) $keep['jti']);
        $totalSuperseded += $n;
    } else {
        $totalSuperseded += count($stale);
    }
}

echo '[backfill] done: ' . $totalSuperseded . ' row(s) '
    . ($apply ? 'superseded' : 'would be superseded (dry run, no changes made)') . "\n";
