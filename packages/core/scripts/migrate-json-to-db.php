#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

// Load .env — same two-directory search as api/index.php (package root when
// installed via composer, or the monorepo root for a developer checkout).
$envDirs = [
    realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'),
    realpath(__DIR__ . '/../../..') ?: (__DIR__ . '/../../..'),
];
foreach ($envDirs as $dir) {
    if (is_string($dir) && file_exists(rtrim($dir, '/') . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable($dir);
        $dotenv->safeLoad();
        break;
    }
}

// Deliberately does NOT gate on FLUXFILES_STORAGE_BACKEND — the cutover flow
// (dry-run -> real run -> verify -> flip the backend -> optional cleanup)
// requires this to run and be verified BEFORE the backend config changes.

$opts = getopt('', ['disk:', 'prefix:', 'dry-run', 'verify', 'yes']);
$disk = $opts['disk'] ?? 'local';
$prefix = $opts['prefix'] ?? '';
$dryRun = array_key_exists('dry-run', $opts);
$verifyOnly = array_key_exists('verify', $opts);
$assumeYes = array_key_exists('yes', $opts);

try {
    $conn = \FluxFiles\Db\Connection::fromEnv();
    // Fail clearly if the schema-migration script hasn't run yet, rather than
    // a raw "no such table" PDO error deep in a handler method.
    $conn->pdo()->query('SELECT 1 FROM file_metadata LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not query the DB schema — run scripts/fluxfiles-migrate.php first.\n");
    fwrite(STDERR, 'Detail: ' . $e->getMessage() . "\n");
    exit(1);
}

$diskConfigs = require __DIR__ . '/../config/disks.php';
$diskManager = new \FluxFiles\DiskManager($diskConfigs);
$source = new \FluxFiles\StorageMetadataHandler($diskManager);
$destination = new \FluxFiles\Db\DbMetadataHandler($conn, $diskManager);
$migrator = new \FluxFiles\Db\JsonToDbMigrator($diskManager, $source, $destination);

if ($verifyOnly) {
    $result = $migrator->verify($disk, $prefix);
    foreach ($result as $section => $diff) {
        $missing = count($diff['missing_in_db'] ?? []);
        $mismatched = count($diff['mismatched'] ?? []);
        fwrite(STDOUT, sprintf("%-16s missing_in_db=%d mismatched=%d\n", $section, $missing, $mismatched));
    }
    $clean = \FluxFiles\Db\JsonToDbMigrator::isClean($result);
    fwrite(STDOUT, $clean ? "\nClean — DB matches JSON source.\n" : "\nDrift detected — see above.\n");
    exit($clean ? 0 : 1);
}

if (!$dryRun && !$assumeYes) {
    fwrite(STDOUT, "About to migrate disk '{$disk}' (prefix: '" . ($prefix !== '' ? $prefix : '(all)') . "') from JSON to DB. Continue? [y/N] ");
    $answer = trim((string) fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        fwrite(STDOUT, "Aborted.\n");
        exit(1);
    }
}

$label = $dryRun ? 'would_' : '';
$result = $migrator->migrate($disk, $prefix, $dryRun);
foreach ($result as $section => $counts) {
    $parts = [];
    foreach ($counts as $bucket => $n) {
        $parts[] = "{$label}{$bucket}={$n}";
    }
    fwrite(STDOUT, sprintf("%-16s %s\n", $section, implode(' ', $parts)));
}
if ($prefix !== '') {
    fwrite(STDOUT, "(note: --prefix does not apply to the audit log — the whole disk's audit trail always migrates together)\n");
}

fwrite(STDOUT, $dryRun ? "\nDry run — no changes written. Re-run with --verify after a real run to confirm.\n" : "\nDone. Run with --verify to confirm the DB now matches the JSON source.\n");
exit(0);
