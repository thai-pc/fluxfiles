<?php

/**
 * router.php's static-file policy over the wire. router.php is a real
 * deployment surface — the README's release-ZIP instructions and
 * `bin/fluxfiles serve` both use it — not just a dev convenience, so the deny
 * rules docker/nginx.conf carries must exist here too.
 *
 * What this locks in:
 *   - `/storage/uploads/_fluxfiles/…` is 403, NOT served. The generic
 *     `/storage/` deny further down never fires for this path, because the
 *     public `/storage/uploads/` branch claims the URI first — which is exactly
 *     why nginx.conf's comment says the _fluxfiles rule must PRECEDE it. When
 *     the rule was missing, index.json (cross-tenant metadata + uploaded_by),
 *     audit.jsonl (every action + user_id + IP), trash.json, the soft-deleted
 *     bytes under trash/<id>/ and _fluxfiles/originals/ (the pre-watermark
 *     masters) were all unauthenticated HTTP 200.
 *   - the mixed-case spelling `/storage/uploads/_FLUXFILES/…` is 403 too, since
 *     on a case-insensitive filesystem it resolves to the same directory.
 *   - `_variants/` STAYS servable — variant URLs are public by design.
 *   - everything else under `/storage/` is 403 (ssh-sockets/ holds ephemeral
 *     BYOB SSH private keys at mode 0600).
 *
 * Usage: php tests/e2e/test-router-storage-http.php   (requires the curl extension)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('r', 40);
$PORT = 8119;
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = realpath(__DIR__ . '/../..');
$uploadRoot = $coreDir . '/storage/uploads';
$prefix = 'router_e2e';

// Plant the exact bookkeeping files the finding listed, plus a legitimately
// public variant and an ordinary upload as controls.
$fx = "{$uploadRoot}/{$prefix}/_fluxfiles";
@mkdir("{$fx}/trash/t1", 0777, true);
@mkdir("{$fx}/originals", 0777, true);
@mkdir("{$fx}/audit/archive", 0777, true);
@mkdir("{$uploadRoot}/{$prefix}/_variants", 0777, true);
file_put_contents("{$fx}/index.json", '{"secret":"cross-tenant metadata"}');
file_put_contents("{$fx}/trash.json", '{"secret":"trash manifest"}');
file_put_contents("{$fx}/audit.jsonl", '{"user_id":"victim","ip":"1.2.3.4"}' . "\n");
file_put_contents("{$fx}/trash/t1/deleted.txt", 'soft-deleted bytes');
file_put_contents("{$fx}/originals/clean.jpg", 'pre-watermark master');
file_put_contents("{$fx}/audit/archive/audit-1-aa.jsonl", '{"archived":true}' . "\n");
file_put_contents("{$uploadRoot}/{$prefix}/_variants/pic-thumb.webp", 'variant bytes');
file_put_contents("{$uploadRoot}/{$prefix}/pic.txt", 'ordinary upload');

$sshDir = $coreDir . '/storage/ssh-sockets';
$plantedSsh = !is_dir($sshDir);
@mkdir($sshDir, 0777, true);
file_put_contents($sshDir . '/router-e2e-key', "-----BEGIN OPENSSH PRIVATE KEY-----\n");

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile, "FLUXFILES_SECRET={$SECRET}\n");

$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

function get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
    $body = (string) curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, $body];
}

echo "\n{$cyan}══ router.php static-file policy over HTTP (e2e) ══{$reset}\n\n";

try {
    test('every _fluxfiles/ bookkeeping file is 403, not served', function () use ($BASE, $prefix) {
        $paths = [
            '_fluxfiles/index.json',
            '_fluxfiles/trash.json',
            '_fluxfiles/audit.jsonl',
            '_fluxfiles/trash/t1/deleted.txt',
            '_fluxfiles/originals/clean.jpg',
            '_fluxfiles/audit/archive/audit-1-aa.jsonl',
        ];
        foreach ($paths as $rel) {
            [$st, $body] = get("{$BASE}/storage/uploads/{$prefix}/{$rel}");
            assertEqual(403, $st, $rel);
            assertTrue(strpos($body, 'secret') === false && strpos($body, 'victim') === false
                && strpos($body, 'soft-deleted') === false && strpos($body, 'master') === false,
                "{$rel} leaked its contents in the 403 body");
        }
    });

    test('the mixed-case _FLUXFILES/ spelling is refused too', function () use ($BASE, $prefix) {
        foreach (['_FLUXFILES/index.json', '_FluxFiles/trash.json', '_Fluxfiles/audit.jsonl'] as $rel) {
            [$st] = get("{$BASE}/storage/uploads/{$prefix}/{$rel}");
            assertEqual(403, $st, $rel);
        }
    });

    test('_variants/ stays publicly servable (variant URLs are public by design)', function () use ($BASE, $prefix) {
        [$st, $body] = get("{$BASE}/storage/uploads/{$prefix}/_variants/pic-thumb.webp");
        assertEqual(200, $st, 'variant must still serve');
        assertEqual('variant bytes', $body, 'variant body');
    });

    test('an ordinary upload still serves (the deny does not over-block)', function () use ($BASE, $prefix) {
        [$st, $body] = get("{$BASE}/storage/uploads/{$prefix}/pic.txt");
        assertEqual(200, $st, 'ordinary upload must still serve');
        assertEqual('ordinary upload', $body, 'upload body');
    });

    test('everything else under /storage/ is 403 (incl. ephemeral SSH keys)', function () use ($BASE) {
        foreach (['/storage/ssh-sockets/router-e2e-key', '/storage/rate_limit.json', '/storage/'] as $p) {
            [$st, $body] = get("{$BASE}{$p}");
            assertEqual(403, $st, $p);
            assertTrue(strpos($body, 'PRIVATE KEY') === false, "{$p} leaked key material");
        }
    });

} finally {
    proc_terminate($proc); proc_close($proc);
    @unlink($sshDir . '/router-e2e-key');
    if ($plantedSsh) { @rmdir($sshDir); }
    $dir = $uploadRoot . '/' . $prefix;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
