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

// docs/DB-STORAGE-MIGRATION-DESIGN.md §8: reunite file_metadata rows with S3/R2
// objects an external tool (raw sync, CRR, a bucket migration) moved outside
// FluxFiles, by cross-referencing the x-amz-meta-fluxfiles-id breadcrumb each
// object carries against file_metadata.object_uuid. Read-only by default —
// nothing is written unless --apply is passed.

$opts = getopt('', ['disk:', 'apply', 'yes']);
$disk = $opts['disk'] ?? null;
$apply = array_key_exists('apply', $opts);
$assumeYes = array_key_exists('yes', $opts);

if ($disk === null) {
    fwrite(STDERR, "Usage: php scripts/repair-s3-metadata.php --disk=<name> [--apply] [--yes]\n");
    exit(1);
}

try {
    $conn = \FluxFiles\Db\Connection::fromEnv();
    $conn->pdo()->query('SELECT 1 FROM file_metadata LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not query the DB schema — run scripts/fluxfiles-migrate.php first.\n");
    fwrite(STDERR, 'Detail: ' . $e->getMessage() . "\n");
    exit(1);
}

$diskConfigs = require __DIR__ . '/../config/disks.php';
if (!isset($diskConfigs[$disk])) {
    fwrite(STDERR, "Unknown disk '{$disk}' — check config/disks.php.\n");
    exit(1);
}
if (($diskConfigs[$disk]['driver'] ?? '') !== 's3') {
    fwrite(STDERR, "Disk '{$disk}' is not an S3-compatible disk — this tool only applies to S3/R2 (Local/SFTP preserve paths 1:1 under sync/copy).\n");
    exit(1);
}

$diskManager = new \FluxFiles\DiskManager($diskConfigs);
$repairer = new \FluxFiles\Db\S3MetadataRepairer($conn, $diskManager);

fwrite(STDOUT, "Scanning bucket for disk '{$disk}'...\n");
$dbRows = $repairer->dbRows($disk);
$s3Objects = $repairer->scanBucket($disk);
$result = $repairer->reconcile($dbRows, $s3Objects);

fwrite(STDOUT, sprintf(
    "moved=%d orphaned_objects=%d orphaned_rows=%d\n",
    count($result['moved']),
    count($result['orphaned_objects']),
    count($result['orphaned_rows'])
));

foreach ($result['moved'] as $entry) {
    fwrite(STDOUT, "  moved: {$entry['old_path']} -> {$entry['new_path']} (uuid={$entry['uuid']})\n");
}
foreach ($result['orphaned_objects'] as $entry) {
    fwrite(STDOUT, "  orphaned object (no matching row): {$entry['key']} (uuid={$entry['uuid']})\n");
}
foreach ($result['orphaned_rows'] as $entry) {
    fwrite(STDOUT, "  orphaned row (no matching object): {$entry['path']} (uuid={$entry['uuid']})\n");
}

if (!$apply) {
    fwrite(STDOUT, "\nRead-only report — no changes written. Re-run with --apply to re-point the 'moved' rows above.\n");
    exit(0);
}

if ($result['moved'] === []) {
    fwrite(STDOUT, "\nNothing to apply — no moved objects found.\n");
    exit(0);
}

if (!$assumeYes) {
    fwrite(STDOUT, "\nAbout to re-point " . count($result['moved']) . " row(s) on disk '{$disk}'. Continue? [y/N] ");
    $answer = trim((string) fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        fwrite(STDOUT, "Aborted.\n");
        exit(1);
    }
}

$count = $repairer->apply($disk, $result['moved']);
fwrite(STDOUT, "\nRe-pointed {$count} row(s).\n");
exit(0);
