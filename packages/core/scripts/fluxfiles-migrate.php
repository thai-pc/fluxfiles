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

if (($_ENV['FLUXFILES_STORAGE_BACKEND'] ?? 'json') !== 'db') {
    fwrite(STDERR, "FLUXFILES_STORAGE_BACKEND is not 'db' — nothing to migrate.\n");
    exit(1);
}

try {
    $conn = \FluxFiles\Db\Connection::fromEnv();
    $applied = (new \FluxFiles\Db\MigrationRunner($conn))->migrate(__DIR__ . '/../db/migrations');
} catch (\Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($applied === []) {
    fwrite(STDOUT, "No pending migrations — already up to date.\n");
} else {
    fwrite(STDOUT, "Applied migrations:\n");
    foreach ($applied as $filename) {
        fwrite(STDOUT, "  - {$filename}\n");
    }
}

exit(0);
