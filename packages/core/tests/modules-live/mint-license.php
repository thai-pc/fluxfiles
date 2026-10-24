<?php
// Mint an all-modules license with the vendor's local signing key, the same way
// tests/e2e/test-share-http.php does. Writes it to argv[2]; prints neither the
// key nor the license. Exits 3 when the signing key isn't on this machine.
declare(strict_types=1);
[$self, $repo, $out] = $argv + [null, null, null];
require "$repo/packages/core/vendor/autoload.php";
$keyFile = "$repo/docs/license-signing-key.SECRET.txt";
if (!is_file($keyFile)) { fwrite(STDERR, "no signing key on this machine\n"); exit(3); }
$secret = '';
foreach (preg_split('/\R/', (string) file_get_contents($keyFile)) ?: [] as $line) {
    $line = trim($line);
    if (preg_match('#^[A-Za-z0-9+/=]{80,}$#', $line) && strlen((string) base64_decode($line, true)) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { $secret = $line; }
}
if ($secret === '') { fwrite(STDERR, "signing key file has no usable key\n"); exit(3); }
$mods = 'share,intake,versioning,webhooks,ai,ocr,virus,backup,c2pa,audit-export,sso,dlp,legal-hold';
$p = proc_open(['php', "$repo/scripts/license-gen.php", '--customer=modules-live', '--edition=enterprise', "--modules=$mods",
    '--enforcement=perpetual', '--expires=+30d', '--kid=k1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
    array_merge(getenv(), ['FLUXFILES_LICENSE_PRIVATE_KEY' => $secret]));
$tok = trim((string) stream_get_contents($pipes[1])); $err = stream_get_contents($pipes[2]); proc_close($p);
$lm = new \FluxFiles\LicenseManager($tok);
foreach (explode(',', $mods) as $m) {
    if (!$lm->licensed($m)) { fwrite(STDERR, "license does not cover $m: " . substr((string) $err, 0, 200) . "\n"); exit(4); }
}
umask(077);
file_put_contents($out, $tok);
echo "license minted for 13 modules\n";
