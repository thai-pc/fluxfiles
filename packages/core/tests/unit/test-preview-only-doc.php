<?php

/**
 * The docs must say preview-only (watermark_enabled / allow_download=false) is
 * only as private as the storage. Audit 2026-11-20 R1-3: they promised "the
 * clean original is never served" while a web-served local root or a public
 * bucket still served it at its public URL.
 *
 * Usage: php tests/unit/test-preview-only-doc.php
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 4);
$checks = [
    'docs/reference/CONFIG.md' => 'Only as private as the disk',
    'docs/guides/FEATURES.md'  => 'Preview-only needs private storage',
    'docs/guides/GUIDE.md'     => 'Preview-only is only as private as the storage',
];
$failed = 0;
foreach ($checks as $file => $needle) {
    $ok = str_contains((string) @file_get_contents("$repo/$file"), $needle);
    echo ($ok ? '  PASS ' : '  FAIL ') . "$file warns that preview-only needs private storage\n";
    $failed += $ok ? 0 : 1;
}
$cfg = (string) @file_get_contents("$repo/docs/reference/CONFIG.md");
foreach (['watermark_enabled', 'allow_download'] as $claim) {
    $row = '';
    foreach (explode("\n", $cfg) as $l) { if (str_starts_with($l, "| `$claim` |")) { $row = $l; } }
    $ok = str_contains($row, 'Only as private as the disk');
    echo ($ok ? '  PASS ' : '  FAIL ') . "CONFIG.md row for $claim carries the warning\n";
    $failed += $ok ? 0 : 1;
}
echo "\n  Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
