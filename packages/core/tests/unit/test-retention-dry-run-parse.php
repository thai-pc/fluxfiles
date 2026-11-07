<?php

/**
 * POST /api/fm/retention/run: `dry_run` must fail safe. Only an explicit false
 * (false, 0, "0", "false", "no", "off") runs live; missing/null/"yes please"/""
 * is a dry run. The route lives in core's index.php and is re-implemented by the
 * Laravel and WordPress proxies, so this evaluates the `$dryRun = ...;` line of
 * each against the same inputs and keeps them identical.
 *
 * Usage: php packages/core/tests/unit/test-retention-dry-run-parse.php
 */

declare(strict_types=1);

$files = [
    'core'      => __DIR__ . '/../../api/index.php',
    'wordpress' => __DIR__ . '/../../../wordpress/includes/FluxFilesApi.php',
    'laravel'   => __DIR__ . '/../../../laravel/src/Http/Controllers/FluxFilesController.php',
];

$cases = [
    // [label, present?, value, expected dryRun]
    ['missing', false, null, true],
    ['null', true, null, true],
    ['true', true, true, true],
    ['"true"', true, 'true', true],
    ['"yes please"', true, 'yes please', true],
    ['empty string', true, '', true],
    ['"1"', true, '1', true],
    ['array', true, ['x'], true],
    ['false', true, false, false],
    ['0', true, 0, false],
    ['"0"', true, '0', false],
    ['"false"', true, 'false', false],
    ['"FALSE"', true, 'FALSE', false],
    ['"no"', true, 'no', false],
    ['"off"', true, 'off', false],
];

$failed = 0;
foreach ($files as $name => $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match('/^\s*\$dryRun = (.+);$/m', $src, $m)) {
        echo "FAIL {$name}: no \$dryRun line\n";
        $failed++;
        continue;
    }
    // The Laravel proxy reads the request object; feed it the same body.
    $expr = str_replace("\$request->input('dry_run', true)", "(\$body['dry_run'] ?? true)", $m[1]);
    foreach ($cases as [$label, $present, $value, $want]) {
        $body = $present ? ['dry_run' => $value] : [];
        $got = eval("return {$expr};");
        if ($got !== $want) {
            echo "FAIL {$name}: dry_run={$label} expected " . json_encode($want) . ' got ' . json_encode($got) . "\n";
            $failed++;
        }
    }
    echo "  checked {$name}\n";
}
echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
