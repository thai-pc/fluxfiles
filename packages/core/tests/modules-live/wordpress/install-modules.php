<?php
// Install the 13 paid modules into the BUILT plugin the way a customer does it:
// a module zip (the pack-modules.php layout: composer.json, LICENSE, README.md,
// src/ at the root) extracted into <plugin>/vendor/fluxfiles/<id>/ by core's own
// UpdateClient::install() — the same Zip-Slip-guarded extractor `fluxfiles update`
// uses. The zips are built from the module WORKTREES (not their tags), so an
// uncommitted module fix under test is what gets installed.
declare(strict_types=1);
[$self, $repo, $plugin, $zipDir] = $argv + [null, null, null, null];
require "$repo/packages/core/vendor/autoload.php";
$mods = ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr', 'virus', 'backup', 'c2pa', 'audit-export', 'sso', 'dlp', 'legal-hold'];
@mkdir($zipDir, 0755, true);
$client = new \FluxFiles\UpdateClient();
foreach ($mods as $id) {
    $src = "$repo/packages/$id";
    if (!is_dir("$src/src")) { fwrite(STDERR, "module $id not checked out\n"); exit(2); }
    $zipPath = "$zipDir/$id.zip";
    @unlink($zipPath);
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach (['composer.json', 'LICENSE', 'README.md'] as $f) {
        if (is_file("$src/$f")) { $zip->addFile("$src/$f", $f); }
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$src/src", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $zip->addFile($file->getPathname(), 'src/' . substr($file->getPathname(), strlen("$src/src/")));
    }
    $zip->close();
    $client->install((string) file_get_contents($zipPath), "$plugin/vendor/fluxfiles/$id");
    if (!is_dir("$plugin/vendor/fluxfiles/$id/src")) { fwrite(STDERR, "install of $id produced no src/\n"); exit(3); }
}
echo "installed " . count($mods) . " modules into vendor/fluxfiles/\n";
