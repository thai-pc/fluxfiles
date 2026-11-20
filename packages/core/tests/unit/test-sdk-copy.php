<?php

/**
 * Core ships its own copy of the browser SDK (packages/core/fluxfiles.js and
 * fluxfiles.min.js) so the Composer package (split from packages/core) and the
 * Docker image can serve /fluxfiles.js. The source of truth is packages/sdk;
 * this guard fails when the copy drifts. Fix: `npm run build` in packages/sdk
 * (it refreshes the copy) or `cp packages/sdk/fluxfiles*.js packages/core/`.
 *
 * Usage: php packages/core/tests/unit/test-sdk-copy.php
 */

declare(strict_types=1);

$core = dirname(__DIR__, 2);
$sdk  = dirname($core) . '/sdk';
$fail = 0;

foreach (['fluxfiles.js', 'fluxfiles.min.js'] as $name) {
    $copy = $core . '/' . $name;
    if (!is_file($copy)) {
        echo "FAIL packages/core/$name is missing\n";
        $fail++;
        continue;
    }
    if (!is_dir($sdk)) {
        echo "SKIP $name (no packages/sdk outside the monorepo)\n";
        continue;
    }
    if (hash_file('sha256', $copy) !== hash_file('sha256', $sdk . '/' . $name)) {
        echo "FAIL packages/core/$name differs from packages/sdk/$name — run `npm run build` in packages/sdk\n";
        $fail++;
        continue;
    }
    echo "PASS $name matches packages/sdk\n";
}

exit($fail === 0 ? 0 : 1);
