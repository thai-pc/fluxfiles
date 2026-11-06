<?php

/**
 * Regression guard: every closure in PublicLinks.php that reads a variable from the
 * enclosing handler must capture it in its `use` list. core-v1.0.4 shipped the intake
 * virus/DLP scanner closures reading $license without capturing it, so every scanned
 * portal upload on Laravel/WordPress died with a TypeError (500).
 *
 * Usage: php tests/unit/test-public-links-closures.php
 */

declare(strict_types=1);

$src = (string) file_get_contents(__DIR__ . '/../../api/PublicLinks.php');
$tokens = token_get_all($src);
$failures = [];
$checked = 0;

for ($i = 0, $n = count($tokens); $i < $n; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
        continue;
    }
    // Only anonymous functions: the next significant token is '(' (or '&' then '(').
    $j = $i + 1;
    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
    if ($j < $n && $tokens[$j] === '&') { $j++; }
    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
    if (!($j < $n && $tokens[$j] === '(')) {
        continue;
    }
    $line = $tokens[$i][2];

    // Parameters.
    $known = ['$this' => true];
    $depth = 0;
    for (; $j < $n; $j++) {
        $t = $tokens[$j];
        if ($t === '(') { $depth++; }
        elseif ($t === ')') { if (--$depth === 0) { $j++; break; } }
        elseif (is_array($t) && $t[0] === T_VARIABLE) { $known[$t[1]] = true; }
    }
    // Optional `use (...)` list.
    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
    if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_USE) {
        for (; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_VARIABLE) { $known[$t[1]] = true; }
            if ($t === ')') { $j++; break; }
        }
    }
    // Body: from the first '{' to its matching '}'.
    while ($j < $n && $tokens[$j] !== '{') { $j++; }
    $depth = 0;
    $assigned = [];
    for (; $j < $n; $j++) {
        $t = $tokens[$j];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; continue; }
        if ($t === '}') { if (--$depth === 0) { break; } continue; }
        if (is_array($t) && $t[0] === T_VARIABLE) {
            $name = $t[1];
            // Look ahead: a plain `$x =` (not `==`) inside the body defines it locally.
            $k = $j + 1;
            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
            if ($k < $n && $tokens[$k] === '=') { $assigned[$name] = true; continue; }
            if (!isset($known[$name]) && !isset($assigned[$name])) {
                $failures[] = "closure at line {$line} reads {$name} without capturing it";
            }
        }
    }
    $checked++;
}

$failures = array_values(array_unique($failures));
echo "Checked {$checked} closures in PublicLinks.php\n";
foreach ($failures as $f) { echo "  FAIL {$f}\n"; }
echo $failures ? "\033[31mFAILED\033[0m\n" : "\033[32mAll tests passed!\033[0m\n";
exit($failures ? 1 : 0);
