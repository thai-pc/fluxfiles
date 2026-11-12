<?php

/**
 * License the paid modules in the THROWAWAY core copy with a THROWAWAY key.
 *
 * Usage: php license.php <core-copy-dir> share,intake,versioning,...
 *
 * 1. Generates a fresh Ed25519 keypair (in memory, never written anywhere).
 * 2. Rewrites the copy's api/LicenseManager.php so its embedded `k1` public key
 *    is the throwaway one. The copy is deleted after the run; the repo's own
 *    LicenseManager.php is never touched.
 * 3. Signs a 365-day local licence with scripts/license-gen.php and the
 *    throwaway secret (passed via env, so it never shows up in `ps`).
 * 4. Checks the copy's LicenseManager accepts it for every module.
 *
 * Prints ONLY the licence token on stdout (nothing at all on any failure — the
 * caller then skips the paid-module shots). The vendor signing key
 * (docs/license-signing-key.SECRET.txt) is never read: a screenshot run must
 * not need it, and a licence minted here is useless against a real install.
 */

declare(strict_types=1);

$copy = rtrim((string) ($argv[1] ?? ''), '/');
$modules = (string) ($argv[2] ?? '');
$repoRoot = realpath(__DIR__ . '/../../..');
$gen = "{$repoRoot}/scripts/license-gen.php";
$lmFile = "{$copy}/api/LicenseManager.php";
if ($copy === '' || $modules === '' || !is_file($gen) || !is_file($lmFile) || !is_file("{$copy}/vendor/autoload.php")
    || !function_exists('sodium_crypto_sign_keypair')) {
    exit(0);
}

$pair = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = base64_encode(sodium_crypto_sign_publickey($pair));

$src = (string) file_get_contents($lmFile);
$patched = preg_replace(
    '/(private const PUBLIC_KEYS = \[)[^\]]*(\];)/s',
    "\$1\n        'k1' => '{$public}', // throwaway key (landing screenshots)\n    \$2",
    $src,
    1,
    $n
);
if (!is_string($patched) || $n !== 1) {
    exit(0);
}
file_put_contents($lmFile, $patched);

// 365 days: an `active` licence more than 30 days out, so the UI shows no
// renew-soon badge in the shots.
$env = array_merge(getenv(), ['FLUXFILES_LICENSE_PRIVATE_KEY' => $secret]);
$p = proc_open(
    ['php', $gen, '--customer=Landing screenshots', '--edition=enterprise', "--modules={$modules}",
        '--enforcement=perpetual', '--expires=+365d', '--kid=k1'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    $env
);
if (!is_resource($p)) {
    exit(0);
}
$tok = trim((string) stream_get_contents($pipes[1]));
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($p);

// The copy's own classes (its vendor/ autoloads its api/), so this checks the
// patched key, exactly what the server will load.
require "{$copy}/vendor/autoload.php";
$lm = new \FluxFiles\LicenseManager($tok);
foreach (explode(',', $modules) as $m) {
    if (!$lm->licensed($m)) {
        exit(0);
    }
}
echo $tok;
