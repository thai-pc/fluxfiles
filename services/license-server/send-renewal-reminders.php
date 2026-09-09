<?php

declare(strict_types=1);

/**
 * Cron-invoked renewal/expiry reminder job — NOT a daemon. Runs once and exits,
 * matching this service's existing philosophy (`server.php`'s `php -S`, request-
 * driven, no long-running process). See LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md §6.
 *
 * Recommended crontab line (also documented in README.md), once daily, off-peak:
 *
 *   0 6 * * *  cd /path/to/services/license-server && php send-renewal-reminders.php >> /var/log/fluxfiles-license-reminders.log 2>&1
 *
 * Env (reuses everything server.php already documents — no new mail plumbing):
 *   FLUXFILES_LICENSE_DB                sqlite path (default ./data/licenses.sqlite)
 *   FLUXFILES_MAIL_TRANSPORT / _FROM / _FROM_NAME / …   same as server.php
 *   FLUXFILES_LICENSE_REMINDER_DAYS     comma-separated day-out thresholds,
 *                                       descending (default "30,14,7,1")
 *
 * Idempotent/at-least-once: reminder_stage only advances after a send actually
 * succeeds, so a mail outage leaves the row due again on the next run instead of
 * silently losing the reminder.
 */

require_once __DIR__ . '/LicenseStore.php';
require_once __DIR__ . '/LicenseMailer.php';

use FluxFiles\LicenseServer\LicenseMailer;
use FluxFiles\LicenseServer\LicenseStore;

$store = new LicenseStore();
$mailer = new LicenseMailer();

$thresholdsRaw = (string) (getenv('FLUXFILES_LICENSE_REMINDER_DAYS') ?: '30,14,7,1');
$thresholds = array_values(array_filter(array_map(
    static fn (string $s): int => (int) trim($s),
    explode(',', $thresholdsRaw)
), static fn (int $n): bool => $n > 0));
if (empty($thresholds)) {
    $thresholds = [30, 14, 7, 1];
}

$due = $store->needingReminder($thresholds, time());

foreach ($due as $record) {
    $bucket = (string) ($record['_reminder_bucket'] ?? '');
    $jti = (string) ($record['jti'] ?? '');
    $sent = $mailer->sendReminder($record, $bucket);
    if ($sent) {
        // Only advance the stage once the send actually succeeded — a failed send
        // must leave reminder_stage unchanged so the next run retries this row.
        $store->markReminderSent($jti, $bucket);
    }
    echo "[reminder] jti={$jti} bucket={$bucket} sent=" . ($sent ? 'yes' : 'no') . "\n";
}

echo '[reminder] done: ' . count($due) . " row(s) processed\n";
