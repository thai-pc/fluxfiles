<?php

/**
 * Paid-module autoloading, from the entrypoints a real install actually uses.
 *
 * This exists because the first fix shipped for half the platforms. The module
 * autoloader was registered inside `autoload.php`, which only the standalone
 * entrypoints (api/index.php, embed.php, bin/fluxfiles) require. The Laravel proxy and
 * the WordPress plugin consume core as a library through `vendor/autoload.php` and
 * never touch it, so on WordPress — the channel ROADMAP.md calls the hero channel — a
 * customer could buy Pro, enter a valid licence key, unpack the module exactly as
 * ACTIVATE.md says, and still get `501 module_not_installed`.
 *
 * The two paths are asserted SEPARATELY and in child processes: an autoloader is
 * process-global, so once one path has registered it, a second check in the same
 * process passes for the wrong reason.
 *
 * Usage: php tests/integration/test-module-autoload.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

$coreDir = dirname(__DIR__, 2);
$vendorMod = $coreDir . '/vendor/fluxfiles/ffautoloadtest';

/**
 * Plant a module in the layout `fluxfiles update <module>` produces, using a name no
 * real module has so a stale directory can never make this pass by accident.
 */
$plant = static function () use ($vendorMod): void {
    @mkdir($vendorMod . '/src', 0777, true);
    file_put_contents(
        $vendorMod . '/src/Probe.php',
        "<?php\nnamespace FluxFiles\\Ffautoloadtest;\nclass Probe {}\n"
    );
};
$unplant = static function () use ($vendorMod): void {
    @unlink($vendorMod . '/src/Probe.php');
    @rmdir($vendorMod . '/src');
    @rmdir($vendorMod);
    @rmdir(dirname($vendorMod));   // vendor/fluxfiles, only if we created it and it's empty
};

/** Run a snippet in a child process; returns trimmed stdout. */
$run = static function (string $php): string {
    $tmp = tempnam(sys_get_temp_dir(), 'ffal') . '.php';
    file_put_contents($tmp, "<?php\n" . $php);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim($out);
};

$probe = '\\FluxFiles\\Ffautoloadtest\\Probe';

echo "\n{$cyan}── Paid-module autoloading ──{$reset}\n\n";

$unplant();   // never trust leftovers from a previous run
$plant();

// The path the Laravel proxy and the WordPress plugin take. This is the one that was
// broken: composer.json's `autoload.files` is what pulls modules-autoload.php in.
test('composer vendor/autoload.php resolves an installed module (library consumers)', function () use ($run, $coreDir, $probe) {
    $out = $run(
        "require " . var_export($coreDir . '/vendor/autoload.php', true) . ";\n" .
        "echo class_exists(" . var_export($probe, true) . ") ? 'yes' : 'no';"
    );
    assertEqual('yes', $out, 'a module in vendor/fluxfiles/ must load for a Composer consumer');
});

test('standalone autoload.php resolves an installed module', function () use ($run, $coreDir, $probe) {
    $out = $run(
        "require " . var_export($coreDir . '/autoload.php', true) . ";\n" .
        "echo class_exists(" . var_export($probe, true) . ") ? 'yes' : 'no';"
    );
    assertEqual('yes', $out, 'the standalone entrypoint path must keep working');
});

// Both files require the same registration; `require_once` + the static guard must
// keep that to one autoloader, or every free-core miss costs two filesystem probes.
test('loading both entrypoints registers the module autoloader once', function () use ($run, $coreDir) {
    $out = $run(
        "require " . var_export($coreDir . '/vendor/autoload.php', true) . ";\n" .
        "\$a = count(spl_autoload_functions());\n" .
        "require " . var_export($coreDir . '/autoload.php', true) . ";\n" .
        "echo \$a === count(spl_autoload_functions()) ? 'same' : 'grew';"
    );
    assertEqual('same', $out, 'the second entrypoint must not register a duplicate autoloader');
});

$unplant();

// The gate's first layer is class_exists(); if the autoloader invented a class for an
// absent module, every "not installed" path would silently become licensable.
test('an absent module still resolves to nothing (free core answers 501)', function () use ($run, $coreDir, $probe) {
    $out = $run(
        "require " . var_export($coreDir . '/vendor/autoload.php', true) . ";\n" .
        "echo class_exists(" . var_export($probe, true) . ") ? 'yes' : 'no';"
    );
    assertEqual('no', $out, 'with nothing installed the class must not exist');
});

// A crafted class name must never walk the filesystem. The module segment is matched
// against ^[a-z0-9]+$ precisely so traversal cannot reach outside vendor/fluxfiles/.
test('a traversal-shaped class name is refused, not resolved', function () use ($run, $coreDir) {
    $out = $run(
        "require " . var_export($coreDir . '/vendor/autoload.php', true) . ";\n" .
        "echo class_exists('\\\\FluxFiles\\\\..\\\\..\\\\Evil') ? 'yes' : 'no';"
    );
    assertEqual('no', $out, 'a dotted module segment must be rejected by the name guard');
});

// The WordPress plugin bundle is assembled by a script, not by Composer. It copies
// core file by file, so a new root-level runtime file is easy to miss — and missing
// this one puts the plugin back to answering 501 for everything paid.
test('the WordPress bundle script copies modules-autoload.php', function () {
    $sh = (string) file_get_contents(dirname(__DIR__, 4) . '/scripts/build-wordpress.sh');
    assertEqual(true, str_contains($sh, 'modules-autoload.php'), 'build-wordpress.sh must bundle modules-autoload.php');
});

// Composer only loads `autoload.files` entries it was told about; if this key is lost
// in a future edit, library consumers silently go back to 501.
test('composer.json declares modules-autoload.php in autoload.files', function () use ($coreDir) {
    $json = json_decode((string) file_get_contents($coreDir . '/composer.json'), true);
    $files = $json['autoload']['files'] ?? [];
    assertEqual(true, in_array('modules-autoload.php', $files, true), 'autoload.files must list modules-autoload.php');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  " . ($failed ? "{$red}Failed: {$failed}{$reset}" : "Failed: 0") . "\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
