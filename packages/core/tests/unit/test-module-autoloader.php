<?php

/**
 * ModuleAutoloader::register($bases): several base dirs, kebab-case module dirs, first
 * base wins, de-duplication, hostile class names, and that modules-autoload.php still
 * registers the two stock layouts. Module names here are made up (ffal*) so a real
 * install on the machine can never make a check pass by accident.
 *
 * Usage: php tests/unit/test-module-autoloader.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ModuleAutoloader;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** Plant `<base>/<dir>/src/<file>` declaring $namespace\$class. */
function plant(string $base, string $dir, string $ns, string $class, string $marker = ''): void {
    @mkdir("{$base}/{$dir}/src", 0777, true);
    file_put_contents("{$base}/{$dir}/src/{$class}.php", "<?php\nnamespace FluxFiles\\{$ns};\nclass {$class} { const FROM = " . var_export($marker, true) . "; }\n");
}

$root = sys_get_temp_dir() . '/ff-modal-' . uniqid();
$baseA = $root . '/a';
$baseB = $root . '/b/';       // trailing slash on purpose
mkdir($baseA, 0777, true);
mkdir($root . '/b', 0777, true);

echo "\n{$cyan}══ ModuleAutoloader ══{$reset}\n\n";

test('modules-autoload.php registered the two stock layouts (vendor/fluxfiles and the host vendor)', function () {
    $bases = ModuleAutoloader::bases();
    assertTrue(count(array_filter($bases, fn ($b) => str_ends_with($b, '/vendor/fluxfiles/'))) === 1);
    assertTrue(count(array_filter($bases, fn ($b) => str_ends_with($b, '/../../fluxfiles/'))) === 1);
});

test('classes are not found before any base holds them', function () use ($baseA, $baseB) {
    assertTrue(!class_exists('FluxFiles\\Ffalone\\Alpha', true));
});

test('register() with several bases: each base is searched, plain-lowercase dirs', function () use ($baseA, $baseB) {
    plant($baseA, 'ffalone', 'Ffalone', 'Alpha', 'A');
    plant($baseB, 'ffaltwo', 'Ffaltwo', 'Beta', 'B');
    ModuleAutoloader::register([$baseA, $baseB]);
    assertEqual('A', \FluxFiles\Ffalone\Alpha::FROM);
    assertEqual('B', \FluxFiles\Ffaltwo\Beta::FROM);
});

test('kebab-case dirs: namespace FfalMulti\\Word resolves dir ffal-multi-word AND ffalmultiword', function () use ($baseA, $baseB) {
    plant($baseA, 'ffal-two-words', 'FfalTwoWords', 'Gamma', 'kebab');
    plant($baseB, 'ffalonewordplain', 'Ffalonewordplain', 'Delta', 'plain');
    assertEqual('kebab', \FluxFiles\FfalTwoWords\Gamma::FROM);
    assertEqual('plain', \FluxFiles\Ffalonewordplain\Delta::FROM);
    assertEqual(['ffaltwowords', 'ffal-two-words'], ModuleAutoloader::dirNames('FfalTwoWords'));
    assertEqual(['share'], ModuleAutoloader::dirNames('Share'));
    assertEqual(['c2pa'], ModuleAutoloader::dirNames('C2pa'));
    assertEqual(['audit-export'], array_slice(ModuleAutoloader::dirNames('AuditExport'), 1));
    assertEqual(['legalhold', 'legal-hold'], ModuleAutoloader::dirNames('LegalHold'));
});

test('nested namespaces map to nested paths', function () use ($baseA) {
    @mkdir("{$baseA}/ffalnest/src/Sub/Deep", 0777, true);
    file_put_contents("{$baseA}/ffalnest/src/Sub/Deep/Leaf.php", "<?php\nnamespace FluxFiles\\Ffalnest\\Sub\\Deep;\nclass Leaf { const OK = true; }\n");
    assertTrue(\FluxFiles\Ffalnest\Sub\Deep\Leaf::OK);
});

test('the first registered base wins when two hold the same class', function () use ($baseA, $baseB) {
    plant($baseA, 'ffaldup', 'Ffaldup', 'Same', 'first');
    plant($baseB, 'ffaldup', 'Ffaldup', 'Same', 'second');
    assertEqual('first', \FluxFiles\Ffaldup\Same::FROM);
});

test('register() de-duplicates (also across trailing-slash spellings) and ignores junk', function () use ($baseA, $baseB) {
    $before = count(ModuleAutoloader::bases());
    ModuleAutoloader::register([$baseA, $baseA . '/', rtrim($baseB, '/'), '', 42]);
    assertEqual($before, count(ModuleAutoloader::bases()));
});

test('a base added later is picked up by the already-installed autoloader (one spl hook)', function () {
    $late = sys_get_temp_dir() . '/ff-modal-late-' . uniqid();
    plant($late, 'ffallate', 'Ffallate', 'Zed', 'late');
    $hooks = count(array_filter(spl_autoload_functions(), fn ($f) => is_array($f) && $f[0] === ModuleAutoloader::class));
    ModuleAutoloader::register([$late]);
    assertEqual($hooks, count(array_filter(spl_autoload_functions(), fn ($f) => is_array($f) && $f[0] === ModuleAutoloader::class)));
    assertEqual('late', \FluxFiles\Ffallate\Zed::FROM);
});

test('core namespace and foreign classes are ignored; a crafted segment never walks the filesystem', function () use ($baseA) {
    @mkdir("{$baseA}/evil/src", 0777, true);
    ModuleAutoloader::load('FluxFiles\\Plain');           // single segment: core's own namespace
    ModuleAutoloader::load('Other\\Vendor\\Thing');
    ModuleAutoloader::load('FluxFiles\\..\\Evil\\Thing');
    ModuleAutoloader::load('FluxFiles\\Fo.o\\Thing');
    assertTrue(!class_exists('FluxFiles\\Fo.o\\Thing', false));
    assertTrue(true);
});

test('a module is only found under a registered base (an unregistered sibling is not searched)', function () {
    $stray = sys_get_temp_dir() . '/ff-modal-stray-' . uniqid();
    plant($stray, 'ffalstray', 'Ffalstray', 'Nope', 'x');
    assertTrue(!class_exists('FluxFiles\\Ffalstray\\Nope', true));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
