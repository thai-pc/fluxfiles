<?php

/**
 * Regression guard: every closure in the host helpers and the public-route gate that reads a variable from the
 * enclosing function must capture it in its `use` list. (core-v1.0.4 shipped the intake
 * virus/DLP scanner closures, then in PublicLinks.php, reading $license without capturing
 * it, so every scanned portal upload on Laravel/WordPress died with a TypeError.)
 *
 * Since core-v1.1.0 the Share/Intake handlers live in the private module repos, so this
 * guard now covers only the few closures left in core (and the module repos carry the
 * equivalent check for theirs). Arrow functions (`fn`, T_FN) are skipped on purpose: they
 * auto-capture the enclosing scope, so they cannot have the uncaptured-variable bug.
 * The guard asserts it saw the expected files and proves itself on a bad-closure fixture.
 *
 * Usage: php tests/unit/test-public-links-closures.php
 */

declare(strict_types=1);

/** @return array{0:int,1:string[]} [closures checked, failures] for one source string */
function scanClosures(string $src, string $srcFile): array
{
    $failures = [];
    $checked = 0;
    $tokens = token_get_all($src);

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
                    $failures[] = "{$srcFile}: closure at line {$line} reads {$name} without capturing it";
                }
            }
        }
        $checked++;
    }

    return [$checked, $failures];
}

$failures = [];
$checked = 0;
$files = ['helpers.php', 'PublicRoutes.php', 'PublicContext.php'];
foreach ($files as $srcFile) {
    $path = __DIR__ . '/../../api/' . $srcFile;
    if (!is_file($path)) { $failures[] = "{$srcFile}: expected file is missing, the guard would check nothing"; continue; }
    [$c, $f] = scanClosures((string) file_get_contents($path), $srcFile);
    $checked += $c;
    $failures = array_merge($failures, $f);
}

// Self-test: a closure reading an uncaptured variable MUST be flagged; captured vars and
// arrow functions must not be.
[, $bad] = scanClosures('<?php function f() { $license = 1; return function () { return $license; }; }', 'fixture');
if (count($bad) !== 1) { $failures[] = 'self-test: the injected bad closure was not detected'; }
[$okChecked, $ok] = scanClosures('<?php function f() { $l = 1; $a = function () use ($l) { return $l; }; $b = fn () => $l; }', 'fixture');
if ($ok || $okChecked !== 1) { $failures[] = 'self-test: a captured closure / arrow function was mis-flagged'; }

$failures = array_values(array_unique($failures));
echo "Checked {$checked} closures in ".implode(', ', $files)." (arrow functions skipped: they auto-capture)\n";
foreach ($failures as $f) { echo "  FAIL {$f}\n"; }
echo $failures ? "\033[31mFAILED\033[0m\n" : "\033[32mAll tests passed!\033[0m\n";
exit($failures ? 1 : 0);
