<?php

/**
 * POST /api/fm/retention/run: `dry_run` must fail safe. Only an explicit false
 * (bool false, int 0, or a string that trimmed + lower-cased is exactly "0",
 * "false", "no" or "off") runs live; everything else (missing, null, "yes please",
 * "", 0.0, "0.0", "false\0", arrays) is a dry run. The route lives in core's
 * index.php and is re-implemented by the Laravel and WordPress proxies, so this
 * evaluates the `$dryRun = ...;` line of each against the same inputs and
 * requires the three to be identical once the input accessor is normalised.
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
    ['0.0', true, 0.0, true],
    ['"0.0"', true, '0.0', true],
    ['"false\0"', true, "false\0", true],
    ['"0\0"', true, "0\0", true],
    ['false', true, false, false],
    ['0', true, 0, false],
    ['"0"', true, '0', false],
    ['"false"', true, 'false', false],
    ['" false "', true, ' false ', false],
    ['"FALSE"', true, 'FALSE', false],
    ['"no"', true, 'no', false],
    ['"off"', true, 'off', false],
];

$failed = 0;
$fail = static function (string $msg) use (&$failed): void {
    echo "FAIL {$msg}\n";
    $failed++;
};

// Guard: every PHP source in the host dirs that serves retention/run must be listed above.
$hostReal = array_map('realpath', array_values($files));
$roots = [__DIR__ . '/../../api', __DIR__ . '/../../../laravel/src', __DIR__ . '/../../../wordpress/includes'];
foreach ($roots as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) realpath($root), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($f->getPathname());
        // A host = mentions the route and assigns a statement-level `$dryRun =` (not a parameter default).
        if (str_contains($code, 'retention/run') && preg_match('/^\s*\$dryRun = /m', $code) && !in_array($f->getRealPath(), $hostReal, true)) {
            $fail('unlisted host mentions retention/run: ' . $f->getPathname());
        }
    }
}

$normalised = [];
foreach ($files as $name => $file) {
    $src = (string) file_get_contents($file);
    // Take the line nearest after the retention/run route / handler, not the first `$dryRun =` in the file.
    $at = strpos($src, 'retention/run');
    if ($name === 'laravel') {
        $at = strpos($src, 'function retentionRun');
    }
    if ($at === false || !preg_match('/^\s*\$dryRun = (.+);$/m', $src, $m, 0, $at)) {
        $fail("{$name}: no \$dryRun line after the retention route");
        continue;
    }
    // Normalise the input accessor so the three lines can be compared verbatim.
    $expr = str_replace(["\$request->json('dry_run', true)", "\$body['dry_run'] ?? true"], '@INPUT@', $m[1]);
    $normalised[$name] = $expr;
    $run = str_replace('@INPUT@', "\$body['dry_run'] ?? true", $expr);
    foreach ($cases as [$label, $present, $value, $want]) {
        $body = $present ? ['dry_run' => $value] : [];
        $got = eval("return {$run};");
        if ($got !== $want) {
            $fail("{$name}: dry_run={$label} expected " . json_encode($want) . ' got ' . json_encode($got));
        }
    }
    echo "  checked {$name}\n";
}
if (count(array_unique($normalised)) > 1) {
    $fail('the three hosts parse dry_run differently: ' . json_encode($normalised));
}
echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
