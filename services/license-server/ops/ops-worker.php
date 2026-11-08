<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/src/Worker.php';

try {
    if (($argv[1] ?? 'run') !== 'run') {
        throw new RuntimeException('Usage: ops-worker.php run');
    }
    (new \FluxFiles\LicenseServer\Ops\Worker())->run();
} catch (Throwable $e) {
    // Provider bodies, URLs, configuration and secrets never reach journald.
    fwrite(STDERR, "ops worker failed; check the API and worker configuration\n");
    exit(1);
}
