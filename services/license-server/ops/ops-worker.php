<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require_once __DIR__ . '/src/Worker.php';

try {
    if (($argv[1] ?? 'run') !== 'run') {
        throw new RuntimeException('Usage: ops-worker.php run');
    }
    $failed = (new \FluxFiles\LicenseServer\Ops\Worker())->run();
    foreach ($failed as $stage) {
        fwrite(STDERR, 'ops stage failed: ' . $stage . "\n");
    }
    exit($failed === [] ? 0 : 1);
} catch (Throwable $e) {
    // Provider bodies, URLs, configuration and secrets never reach journald.
    fwrite(STDERR, "ops stage failed: startup\n");
    exit(1);
}
