<?php

/**
 * Mint a short-lived LOCAL license for the screenshot server, the same way
 * tests/e2e/test-sso-http.php does: scripts/license-gen.php signed with the
 * offline key in docs/license-signing-key.SECRET.txt.
 *
 * Usage: php license.php share,intake,versioning,...
 * Prints ONLY the license token on stdout (nothing at all when the signing key
 * isn't on this machine — the caller then skips the paid-module shots). The
 * signing key itself is never printed.
 */

declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/../../..');
require $repoRoot . '/packages/core/vendor/autoload.php';

$modules = (string) ($argv[1] ?? '');
$gen = "{$repoRoot}/scripts/license-gen.php";
$keyFile = "{$repoRoot}/docs/license-signing-key.SECRET.txt";
if ($modules === '' || !is_file($gen) || !is_file($keyFile) || !function_exists('sodium_crypto_sign_detached')) {
    exit(0);
}

$secret = '';
foreach (preg_split('/\R/', (string) file_get_contents($keyFile)) ?: [] as $line) {
    $line = trim($line);
    if (preg_match('#^[A-Za-z0-9+/=]{80,}$#', $line) && strlen((string) base64_decode($line, true)) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        $secret = $line;
    }
}
if ($secret === '') {
    exit(0);
}

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

$lm = new \FluxFiles\LicenseManager($tok);
foreach (explode(',', $modules) as $m) {
    if (!$lm->licensed($m)) {
        exit(0);
    }
}
echo $tok;
