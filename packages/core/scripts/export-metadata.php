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

$opts = getopt('', ['disk:', 'prefix:', 'owner:', 'format:', 'out:']);
$disk = $opts['disk'] ?? 'local';
$prefix = $opts['prefix'] ?? '';
$owner = array_key_exists('owner', $opts) ? (string) $opts['owner'] : null;
$format = ($opts['format'] ?? 'ndjson') === 'csv' ? 'csv' : 'ndjson';
$outPath = $opts['out'] ?? null;

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

$handle = $outPath !== null ? fopen($outPath, 'w') : STDOUT;
if ($handle === false) {
    fwrite(STDERR, "Could not open '{$outPath}' for writing.\n");
    exit(1);
}

$exporter = new \FluxFiles\Db\MetadataExporter($conn);
$count = $exporter->streamTo($handle, (string) $disk, $format, (string) $prefix, $owner);

if ($outPath !== null) {
    fclose($handle);
}

fwrite(STDERR, "Exported {$count} row(s) from disk '{$disk}'" . ($prefix !== '' ? " (prefix: '{$prefix}')" : '') . ".\n");
exit(0);
