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

$opts = getopt('', ['disk:', 'in:', 'format:']);
$disk = $opts['disk'] ?? 'local';
$inPath = $opts['in'] ?? null;
if ($inPath === null) {
    fwrite(STDERR, "Usage: import-metadata.php --disk=local --in=backup.ndjson [--format=ndjson|csv]\n");
    exit(1);
}

$format = $opts['format'] ?? (str_ends_with((string) $inPath, '.csv') ? 'csv' : 'ndjson');

$handle = fopen($inPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Could not open '{$inPath}' for reading.\n");
    exit(1);
}

$entries = [];
if ($format === 'csv') {
    $header = fgetcsv($handle);
    if ($header === false) {
        fwrite(STDERR, "'{$inPath}' has no header row.\n");
        exit(1);
    }
    while (($row = fgetcsv($handle)) !== false) {
        $entry = array_combine($header, $row);
        // The exporter writes empty string, not null, for CSV — restore null
        // so the importer's `?? null` defaulting behaves the same as NDJSON.
        $entries[] = array_map(fn($v) => $v === '' ? null : $v, $entry);
    }
} else {
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }
}
fclose($handle);

if ($entries === []) {
    fwrite(STDERR, "No entries found in '{$inPath}'.\n");
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

// The CLI runs with direct DB/filesystem access and no JWT — there is no
// caller scope to enforce, unlike the HTTP route which validates every row
// against the requesting token's Claims::isPathInScope().
$importer = new \FluxFiles\Db\MetadataImporter($conn);
$result = $importer->import((string) $disk, $entries, fn(string $path) => true);

if ($result['errors'] !== []) {
    fwrite(STDERR, "Import rejected — " . count($result['errors']) . " invalid row(s):\n");
    foreach ($result['errors'] as $err) {
        fwrite(STDERR, "  row {$err['row']}: {$err['error']}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Imported {$result['imported']} row(s) into disk '{$disk}'.\n");
exit(0);
