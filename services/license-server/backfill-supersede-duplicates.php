<?php

declare(strict_types=1);

/**
 * One-off cleanup for the B2 fix (2026-09, LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md):
 * before that fix, every subscription renewal minted a NEW `active` row instead of
 * retiring the old one, so a customer with N renewals could have N `active` rows for
 * the same (customer, plan) — each independently eligible for needingReminder(),
 * producing contradictory reminder emails (an "expired" nag for the stale row next
 * to a "renews soon" nag for the current one) every billing cycle.
 *
 * LicenseIssuer::issue() now calls LicenseStore::supersedeActiveForCustomerPlan() on
 * every fresh issuance, so this self-heals going forward — but only on that pair's
 * NEXT renewal. Rows that were already duplicated before the fix shipped sit there
 * until then. This script closes that gap immediately by keeping only the
 * newest-issued `active` row per (customer, plan) and marking the rest `superseded`,
 * the same status supersedeActiveForCustomerPlan() uses.
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

require_once __DIR__ . '/LicenseStore.php';

use FluxFiles\LicenseServer\LicenseStore;

$apply = in_array('--apply', $argv, true);

$store = new LicenseStore();
$pairs = $store->duplicateActiveCustomerPlans();

if (empty($pairs)) {
    echo "[backfill] no duplicate active (customer, plan) pairs found — nothing to do\n";
    exit(0);
}

echo '[backfill] ' . count($pairs) . ' duplicate (customer, plan) pair(s) found'
    . ($apply ? '' : ' (dry run — pass --apply to actually supersede)') . "\n";

$totalSuperseded = 0;

foreach ($pairs as $pair) {
    $customer = (string) $pair['customer'];
    $plan = (string) $pair['plan'];
    $rows = $store->activeRowsForCustomerPlan($customer, $plan);

    if (count($rows) < 2) {
        // Raced with concurrent issuance between the two queries above — already
        // down to one active row for this pair, nothing left to do.
        continue;
    }

    $keep = $rows[0]; // newest issued, per activeRowsForCustomerPlan()'s ORDER BY
    $stale = array_slice($rows, 1);

    echo "[backfill] customer={$customer} plan={$plan}: keeping jti={$keep['jti']}"
        . ' (issued=' . date('c', (int) $keep['issued']) . '),'
        . ' superseding ' . count($stale) . " older row(s):\n";
    foreach ($stale as $row) {
        echo "           - jti={$row['jti']} issued=" . date('c', (int) $row['issued'])
            . ' expires=' . ($row['expires'] !== null ? date('c', (int) $row['expires']) : 'never') . "\n";
    }

    if ($apply) {
        $n = $store->supersedeActiveForCustomerPlan($customer, $plan, (string) $keep['jti']);
        $totalSuperseded += $n;
    } else {
        $totalSuperseded += count($stale);
    }
}

echo '[backfill] done: ' . $totalSuperseded . ' row(s) '
    . ($apply ? 'superseded' : 'would be superseded (dry run, no changes made)') . "\n";
