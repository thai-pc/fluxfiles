<?php

/**
 * Config-doc coverage guard. Every JWT claim read in Claims.php (`$payload->X`) MUST
 * be documented in docs/CONFIG.md (the single config reference), so the docs can't
 * drift when a new claim is added. Mirrors the i18n guard (test-i18n.php).
 *
 * Usage: php packages/core/tests/unit/test-config-doc.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";

$claimsFile = __DIR__ . '/../../api/Claims.php';
$configDoc  = __DIR__ . '/../../../../docs/CONFIG.md';

echo "\n{$cyan}══ Config-doc coverage (docs/CONFIG.md) ══{$reset}\n\n";

if (!is_file($configDoc)) {
    fwrite(STDERR, "  {$red}FAIL{$reset} docs/CONFIG.md not found at {$configDoc}\n");
    exit(1);
}

$src = (string) file_get_contents($claimsFile);
$doc = (string) file_get_contents($configDoc);

// Every claim key read off the JWT payload.
preg_match_all('/\$payload->([a-z_0-9]+)/', $src, $m);
$claims = array_values(array_unique($m[1]));
sort($claims);

$missing = [];
foreach ($claims as $claim) {
    // CONFIG.md documents every claim as `claim` (backtick-wrapped). Accept that or a
    // bare word-boundary mention, so the guard is about presence, not formatting.
    if (strpos($doc, '`' . $claim . '`') === false
        && !preg_match('/\b' . preg_quote($claim, '/') . '\b/', $doc)) {
        $missing[] = $claim;
    }
}

echo '  Claims parsed in Claims.php: ' . count($claims) . "\n";

if ($missing !== []) {
    echo "  {$red}✗ undocumented claims (add them to docs/CONFIG.md §2):{$reset} " . implode(', ', $missing) . "\n";
    echo "\n{$red}1 error(s) found.{$reset}\n";
    exit(1);
}

echo "  {$green}✓ every claim is documented in docs/CONFIG.md{$reset}\n";
echo "\n{$green}All tests passed!{$reset}\n";
exit(0);
