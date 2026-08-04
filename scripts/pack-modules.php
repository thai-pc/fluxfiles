<?php

/**
 * Build the release artifacts the update channel serves.
 *
 * `docs/update-server.example.php` verifies a licence and returns a SIGNED manifest
 * pointing at a module zip plus its sha256; `UpdateClient` re-hashes the download and
 * refuses a mismatch. That means the channel's integrity is anchored to a checksum
 * someone has to produce — and until this script existed, nobody did. A customer could
 * be issued a key that verifies against a download that does not exist.
 *
 * What it does, per module:
 *   1. Reads the version from the module repo's own git tag — never from a number
 *      typed here, so the artifact cannot disagree with what was released.
 *   2. Builds `<id>-<version>.zip` with `git archive` **from that tag**, not from the
 *      working tree, so uncommitted edits can never leak into a release.
 *   3. Hashes it, and proves the build is reproducible by building a second copy and
 *      comparing. A checksum that changes on every rebuild would be worthless as an
 *      integrity anchor.
 *   4. Checks the layout against what `UpdateClient::install()` actually does.
 *   5. Writes `build/modules/catalogue.json` in the shape the example server's
 *      $CATALOGUE expects.
 *
 * Module ids come from `ModuleRegistry::$map` — the source of truth — so a tenth module
 * is packaged the day it is registered, with nothing to remember here.
 *
 * Usage:
 *   php scripts/pack-modules.php              # every registered module
 *   php scripts/pack-modules.php share intake # a subset
 */

declare(strict_types=1);

require_once __DIR__ . '/../packages/core/vendor/autoload.php';

use FluxFiles\ModuleRegistry;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

$root = dirname(__DIR__);
$outDir = $root . '/build/modules';

/** Run a command, returning [exitCode, stdout, stderr]. No shell, so nothing is interpolated. */
function run(array $cmd, ?string $cwd = null): array
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($p)) {
        return [1, '', 'could not spawn ' . ($cmd[0] ?? '?')];
    }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}

/**
 * Entries a customer needs. `tests/` is deliberately excluded: every module's tests
 * require `../../core/vendor/autoload.php`, a path that does not exist once the package
 * is installed into a customer's tree, so shipping them would ship something that looks
 * broken. Listing paths explicitly (rather than adding .gitattributes export-ignore to
 * nine repos) keeps the already-published tags untouched.
 */
const PACKAGED_PATHS = ['composer.json', 'LICENSE', 'README.md', 'src'];

$ids = array_slice($argv, 1);
if ($ids === []) {
    $map = new ReflectionProperty(ModuleRegistry::class, 'map');
    $map->setAccessible(true);
    $ids = array_keys($map->getValue());
}

echo "\n{$cyan}══ Packaging module release artifacts ══{$reset}\n\n";

@mkdir($outDir, 0755, true);
$catalogue = [];
$failed = 0;
$skipped = [];

foreach ($ids as $id) {
    $dir = $root . '/packages/' . $id;
    if (!is_dir($dir . '/.git')) {
        $skipped[] = "{$id} (not checked out)";
        continue;
    }

    // 1. Version from the tag. `--abbrev=0` gives the tag itself, and the exact-match
    //    check refuses to package a tree that has moved past its last tag: the artifact
    //    would then carry code the version number does not describe.
    [$c, $tag] = run(['git', 'describe', '--tags', '--abbrev=0'], $dir);
    $tag = trim($tag);
    if ($c !== 0 || $tag === '') {
        $skipped[] = "{$id} (no tag — release it first)";
        continue;
    }
    [$c2, $exact] = run(['git', 'describe', '--tags', '--exact-match', 'HEAD'], $dir);
    if ($c2 !== 0) {
        echo "  {$yellow}WARN{$reset} {$id}: HEAD is past {$tag} — packaging the TAG, not HEAD\n";
    }
    $version = ltrim($tag, 'vV');

    // 2. Build from the tag. git archive is deterministic (entry mtimes come from the
    //    tagged commit), which is what makes step 3 meaningful.
    $zipName = "{$id}-{$version}.zip";
    $zipPath = "{$outDir}/{$zipName}";

    // A module may legitimately lack README/LICENSE; ask git what the tag actually holds.
    $paths = [];
    foreach (PACKAGED_PATHS as $p) {
        [$lc] = run(['git', 'cat-file', '-e', "{$tag}:{$p}"], $dir);
        if ($lc === 0) { $paths[] = $p; }
    }
    // Built as a function of the output path rather than by patching an index into a
    // flat arg list — an off-by-one there silently rewrites a different flag.
    $archiveCmd = static fn (string $out): array =>
        array_merge(['git', 'archive', '--format=zip', '-o', $out, $tag], $paths);

    [$c3, , $err] = run($archiveCmd($zipPath), $dir);
    if ($c3 !== 0) {
        echo "  {$red}FAIL{$reset} {$id}: git archive — " . trim($err) . "\n";
        $failed++;
        continue;
    }

    $bytes = (string) file_get_contents($zipPath);
    $sha = hash('sha256', $bytes);

    // 3. Reproducibility: same tag must yield the same bytes, or the checksum in the
    //    catalogue is only true for one particular build.
    $probe = "{$zipPath}.probe";
    run($archiveCmd($probe), $dir);
    $reproducible = is_file($probe) && hash_file('sha256', $probe) === $sha;
    @unlink($probe);
    if (!$reproducible) {
        echo "  {$red}FAIL{$reset} {$id}: rebuild produced different bytes — checksum is not an anchor\n";
        $failed++;
        continue;
    }

    // 4. The layout UpdateClient::install() assumes: it renames the extracted stage dir
    //    ONTO the destination, so entries must sit at the zip root — a wrapper directory
    //    would install as packages/<id>/<id>/src. It also rejects '..' and absolute paths.
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) {
        echo "  {$red}FAIL{$reset} {$id}: produced an unreadable zip\n";
        $failed++;
        continue;
    }
    $layoutError = null;
    $sawSrc = false;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = (string) $za->getNameIndex($i);
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
            $layoutError = "unsafe entry '{$name}'";
            break;
        }
        if (str_starts_with($name, 'src/')) { $sawSrc = true; }
        if (str_starts_with($name, 'tests/')) { $layoutError = 'tests/ leaked into the artifact'; break; }
    }
    $count = $za->numFiles;
    $za->close();
    if ($layoutError === null && !$sawSrc) {
        $layoutError = 'no src/ at the zip root — install() would produce a nested directory';
    }
    if ($layoutError !== null) {
        echo "  {$red}FAIL{$reset} {$id}: {$layoutError}\n";
        $failed++;
        continue;
    }

    $catalogue[$id] = ['version' => $version, 'zip' => $zipName, 'sha256' => $sha];
    printf("  {$green}OK{$reset}   %-11s %-7s %2d files  %6.1f KB  %s…\n",
        $id, $version, $count, strlen($bytes) / 1024, substr($sha, 0, 12));
}

// 5. The catalogue the example update server reads.
$cataloguePath = "{$outDir}/catalogue.json";
file_put_contents($cataloguePath, json_encode($catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "\n";
foreach ($skipped as $s) {
    echo "  {$yellow}SKIP{$reset} {$s}\n";
}
echo "\n  catalogue: {$cataloguePath} (" . count($catalogue) . " modules)\n";
echo "  artifacts: {$outDir}/\n\n";
echo "  Next: upload the zips to your CDN and point the update server at this\n";
echo "  catalogue (docs/update-server.example.php \$CATALOGUE). The sha256 here is\n";
echo "  what UpdateClient re-hashes, so serve exactly these bytes.\n\n";

exit($failed > 0 ? 1 : 0);
