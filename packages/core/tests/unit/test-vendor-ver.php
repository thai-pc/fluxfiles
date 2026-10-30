<?php
declare(strict_types=1);

/**
 * fm.js's FM_VENDOR_VER (the ?v= of the lazily loaded CodeMirror files)
 * must equal the content hash of those vendor trees, so a vendor-only update
 * changes the URL and the long-lived immutable cache can't pin the old file.
 * Fix: `bash scripts/build-ui.sh` (runs scripts/vendor-ver.mjs).
 */

$assets = dirname(__DIR__, 2) . '/assets';
$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32m✓\033[0m {$name}\n"; }
    else { $fail++; echo "  \033[31m✗\033[0m {$name} {$detail}\n"; }
}

function treeHash(string $dir): string
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        }
    }
    sort($files, SORT_STRING);
    $h = hash_init('md5');
    foreach ($files as $r) {
        hash_update($h, $r . "\0");
        hash_update($h, (string) file_get_contents($dir . '/' . $r));
        hash_update($h, "\0");
    }
    return substr(hash_final($h), 0, 10);
}

echo "FM_VENDOR_VER matches vendor trees\n";
$js = (string) file_get_contents($assets . '/fm.js');
check('fm.js declares FM_VENDOR_VER', preg_match("/^const FM_VENDOR_VER = \\{ codemirror: '([0-9a-f]{10})' \\}; \\/\\/ vendor-ver\$/m", $js, $m) === 1);
foreach (['codemirror' => 1] as $name => $i) {
    $want = treeHash($assets . '/vendor/' . $name);
    check("{$name} hash is current", ($m[$i] ?? '') === $want, "(fm.js has " . ($m[$i] ?? 'none') . ", files hash to {$want} - run bash scripts/build-ui.sh)");
}
check('no leftover FM_ASSETS_VER', !str_contains($js, 'FM_ASSETS_VER'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
