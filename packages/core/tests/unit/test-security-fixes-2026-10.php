<?php

/**
 * Regression tests for the 2026-10 (second pass) security fixes — see
 * docs/security/SECURITY-AUDIT-2026-10.md for the finding behind each block.
 *
 * Usage: php tests/unit/test-security-fixes-2026-10.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DemoMode;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtCompat;
use FluxFiles\SsoBootToken;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function expectCode(string $code, callable $fn): void {
    try {
        $fn();
    } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === $code, "expected {$code}, got " . (string) $e->getErrorCode());
        return;
    }
    throw new \RuntimeException("expected {$code}, nothing thrown");
}

/** @return array{0:FileManager,1:StorageMetadataHandler,2:string} */
function sfEnv(array $claimsSetup = []): array {
    $root = sys_get_temp_dir() . '/fluxfiles-sf-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('u1', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    foreach ($claimsSetup as $k => $v) {
        $claims->{$k} = $v;
    }
    return [new FileManager($dm, $claims, $meta), $meta, $root];
}

function sfUpload(FileManager $fm, string $dir, string $name, string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'sf');
    file_put_contents($tmp, $bytes);
    try {
        return $fm->upload('local', $dir, ['name' => $name, 'size' => strlen($bytes), 'tmp_name' => $tmp], true);
    } finally {
        @unlink($tmp);
    }
}

echo "\n{$cyan}══ 2026-10 security fixes ══{$reset}\n\n";

// ── MED-1: dangerous extensions / filenames ─────────────────────────────────
test('MED-1: handler-mapped PHP spellings + server-config filenames are refused', function () {
    [$fm] = sfEnv();
    foreach (['shell.php8', 'x.pht', 'x.phtm', 'x.phpt', 'x.pgif', 'page.shtml', 'a.stm', '.user.ini', 'web.config', 'WEB.CONFIG', 'x.php ', 'x.php.'] as $name) {
        expectCode('ext_dangerous', fn () => sfUpload($fm, '', $name, 'x'));
    }
});

test('MED-1: ordinary names still upload', function () {
    [$fm, , $root] = sfEnv();
    sfUpload($fm, '', 'notes.txt', 'hi');
    sfUpload($fm, '', 'php-guide.pdf', 'pdf');
    assertTrue(is_file($root . '/notes.txt') && is_file($root . '/php-guide.pdf'), 'written');
});

// ── MED-2: .git block reaches extract entries + module writes ───────────────
test('MED-2: validateScopedPath/validateUserPath are disk-aware for the SFTP .git block', function () {
    $dm = new DiskManager(['sftp' => ['driver' => 'sftp', 'host' => '127.0.0.1', 'username' => 'n', 'password' => 'n', 'root' => '/tmp']]);
    $fm = new FileManager($dm, new Claims('u', ['read', 'write'], ['sftp'], '', 50, null, 0, false), new StorageMetadataHandler($dm));
    expectCode('git_internal_path', fn () => $fm->validateScopedPath('site/.git/config', 'sftp'));
    expectCode('git_internal_path', fn () => $fm->validateUserPath('site/.GIT/hooks/post-merge', 'sftp'));
    // Without a disk the legacy behaviour is unchanged (reserved-namespace only).
    assertTrue($fm->validateScopedPath('site/.git/config') === 'site/.git/config', 'disk-less call unchanged');
});

test('MED-2: extractZip checks every entry target with the disk (source guard)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../api/FileManager.php');
    assertTrue(strpos($src, '$this->assertNotSystem($target, $disk);') !== false, 'per-entry disk-aware guard present');
    assertTrue(strpos($src, '$scopedPath = $this->validateScopedPath($scopedPath, $disk);') !== false, 'module write helpers re-validate');
});

// ── LOW-MED-5: inflated bytes capped at the declared size ───────────────────
test('LOW-MED-5: an archive that under-declares its entry size is refused, nothing written', function () {
    if (!class_exists(\ZipArchive::class)) {
        return;
    }
    [$fm, , $root] = sfEnv();
    $zipPath = $root . '/liar.zip';
    $z = new \ZipArchive();
    $z->open($zipPath, \ZipArchive::CREATE);
    $z->addFromString('big.txt', str_repeat("\0", 2 * 1024 * 1024));
    $z->setCompressionName('big.txt', \ZipArchive::CM_DEFLATE);
    $z->close();
    $b = (string) file_get_contents($zipPath);
    $lh = strpos($b, "PK\x03\x04");
    $b = substr_replace($b, pack('V', 10), $lh + 22, 4);
    $cd = strpos($b, "PK\x01\x02");
    $b = substr_replace($b, pack('V', 10), $cd + 24, 4);
    file_put_contents($zipPath, $b);

    expectCode('zip_too_large', fn () => $fm->extractZip('local', 'liar.zip'));
    assertTrue(!is_file($root . '/liar/big.txt'), 'the inflated bomb never reached storage');
});

test('LOW-MED-5: an honest archive still extracts', function () {
    [$fm, , $root] = sfEnv();
    $z = new \ZipArchive();
    $z->open($root . '/ok.zip', \ZipArchive::CREATE);
    $z->addFromString('a.txt', str_repeat('a', 100000));
    $z->close();
    $res = $fm->extractZip('local', 'ok.zip');
    assertTrue(($res['extracted'] ?? 0) === 1 && filesize($root . '/ok/a.txt') === 100000, 'extracted intact');
});

// ── MED-3: presigned GET pins the Content-Type ──────────────────────────────
test('MED-3: presignContentType derives from the extension, never the stored type', function () {
    assertTrue(FileManager::presignContentType('a/photo.png') === 'image/png', 'png');
    assertTrue(FileManager::presignContentType('x.html') === 'text/html', 'html');
    assertTrue(FileManager::presignContentType('noext') === 'application/octet-stream', 'fallback');
});

test('MED-3: a presigned S3 GET URL carries response-content-type', function () {
    $dm = new DiskManager(['s3' => [
        'driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'b', 'key' => 'AKIDEXAMPLE', 'secret' => 'x',
        'endpoint' => 'https://s3.us-east-1.amazonaws.com',
    ]]);
    $fm = new FileManager($dm, new Claims('u', ['read'], ['s3'], '', 50, null, 0, false), new StorageMetadataHandler($dm));
    $url = $fm->presign('s3', 'pics/photo.png', 'GET', 60)['url'];
    assertTrue(strpos($url, 'response-content-type=image%2Fpng') !== false, 'type pinned: ' . $url);
    assertTrue(strpos($url, 'response-content-disposition=') !== false, 'disposition still pinned');
});

// ── show_hidden is enforced on direct reads ─────────────────────────────────
test('show_hidden=false: dotfiles are not readable by name', function () {
    [$fm, , $root] = sfEnv();
    file_put_contents($root . '/.env', 'DB_PASSWORD=x');
    @mkdir($root . '/site/.git', 0777, true);
    file_put_contents($root . '/site/.git/config', '[core]');
    expectCode('path_denied', fn () => $fm->getContent('local', '.env'));
    expectCode('path_denied', fn () => $fm->getContent('local', 'site/.git/config'));
    expectCode('path_denied', fn () => $fm->fileMeta('local', '.env'));
    expectCode('path_denied', fn () => $fm->zipManifest('local', ['.env']));
    // A folder zip silently skips its hidden children.
    file_put_contents($root . '/site/index.html', 'x');
    $m = $fm->zipManifest('local', ['site']);
    assertTrue(count($m['entries']) === 1 && $m['entries'][0]['name'] === 'site/index.html', 'hidden children skipped');
});

test('show_hidden=true: dotfiles stay readable', function () {
    [$fm, , $root] = sfEnv(['showHidden' => true]);
    file_put_contents($root . '/.env', 'A=1');
    assertTrue($fm->getContent('local', '.env')['content'] === 'A=1', 'readable');
});

test('show_hidden: a dotted tenant PREFIX does not hide the tenant\'s own tree', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-sf-' . uniqid();
    @mkdir($root . '/.tenants/acme', 0777, true);
    file_put_contents($root . '/.tenants/acme/a.txt', 'ok');
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $fm = new FileManager($dm, new Claims('u', ['read'], ['local'], '.tenants/acme', 50, null, 0, false), new StorageMetadataHandler($dm));
    assertTrue($fm->getContent('local', 'a.txt')['content'] === 'ok', 'prefix not judged as hidden');
});

// ── Legal hold blocks in-place mutation ─────────────────────────────────────
test('legal hold: overwrite-upload / editor save / metadata edit on a held file are refused', function () {
    [$fm, $meta, $root] = sfEnv(['allowCodeEdit' => true, 'uploadCollision' => 'overwrite']);
    sfUpload($fm, 'evidence', 'memo.txt', 'original');
    $meta->addHold('local', 'h1', ['path' => 'evidence', 'placed_at' => time(), 'released_at' => null]);

    expectCode('legal_hold_active', fn () => sfUpload($fm, 'evidence', 'memo.txt', 'tampered'));
    expectCode('legal_hold_active', fn () => $fm->putContent('local', 'evidence/memo.txt', 'tampered'));
    expectCode('legal_hold_active', fn () => $fm->assertCanModifyScopedPath('local', 'evidence/memo.txt'));
    expectCode('legal_hold_active', fn () => $fm->writeScopedFile('local', 'evidence/memo.txt', 'tampered'));
    assertTrue(file_get_contents($root . '/evidence/memo.txt') === 'original', 'bytes untouched');

    // A NEW file inside the held folder is still allowed (same as move-into).
    sfUpload($fm, 'evidence', 'new.txt', 'n');
    assertTrue(is_file($root . '/evidence/new.txt'), 'new file allowed');
});

// ── MED-4: SSO boot token no longer carries the JWT in the clear ────────────
test('MED-4: the boot token payload does not expose the real JWT', function () {
    $secret = str_repeat('s', 40);
    $real = JwtCompat::encode(['sub' => 'alice', 'perms' => ['read'], 'exp' => time() + 3600], $secret);
    $boot = SsoBootToken::mint($real, $secret);
    $payload = (string) base64_decode(strtr(explode('.', $boot)[1], '-_', '+/'));
    assertTrue(strpos($payload, 'real_jwt') === false, 'no plaintext field');
    assertTrue(strpos($payload, explode('.', $real)[1]) === false, 'real JWT body not present');
    assertTrue(SsoBootToken::verify($boot, $secret) === $real, 'round-trips');
    // A different secret can neither verify nor decrypt it.
    expectCode('sso_boot_token_invalid', fn () => SsoBootToken::verify($boot, str_repeat('t', 40)));
    // A legacy plaintext-shape token (signed, right type) is refused.
    $legacy = JwtCompat::encode(['t' => 'sso_boot', 'real_jwt' => $real, 'exp' => time() + 60], $secret);
    expectCode('sso_boot_token_invalid', fn () => SsoBootToken::verify($legacy, $secret));
});

// ── Demo: proxy headers only from declared proxies ──────────────────────────
test('demo clientIp: spoofed XFF / CF headers are ignored without a trusted proxy', function () {
    $keep = [$_SERVER, $_ENV['FLUXFILES_DEMO_TRUSTED_PROXIES'] ?? null];
    putenv('FLUXFILES_DEMO_TRUSTED_PROXIES');
    unset($_ENV['FLUXFILES_DEMO_TRUSTED_PROXIES']);
    try {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';
        assertTrue(DemoMode::clientIp() === '198.51.100.9', 'peer wins');

        $_ENV['FLUXFILES_DEMO_TRUSTED_PROXIES'] = '198.51.100.0/24, 10.0.0.1';
        assertTrue(DemoMode::clientIp() === '5.6.7.8', 'CF header honoured from a trusted proxy');
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        // Right-most untrusted hop, not the client-forgeable left-most one.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.5, 10.0.0.1';
        assertTrue(DemoMode::clientIp() === '203.0.113.5', 'xff walked from the right');
        $_ENV['FLUXFILES_DEMO_TRUSTED_PROXIES'] = '2001:db8::/32';
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
        assertTrue(DemoMode::clientIp() === '203.0.113.77', 'ipv6 cidr');
    } finally {
        $_SERVER = $keep[0];
        if ($keep[1] === null) { unset($_ENV['FLUXFILES_DEMO_TRUSTED_PROXIES']); } else { $_ENV['FLUXFILES_DEMO_TRUSTED_PROXIES'] = $keep[1]; }
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
