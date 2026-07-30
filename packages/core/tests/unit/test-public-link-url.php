<?php

/**
 * The recipient-link fallback the Share/Intake create routes use when the token
 * carries no `share_base_url` / `intake_base_url`. It is the only URL a UI can show
 * for a one-shot token, so its escaping matters: a JWT is base64url (`-`/`_`), but
 * the helper must survive any token shape a module hands it.
 *
 * Why the eval: `api/index.php` is an executing script (it routes and exits), so a
 * test can't require it. Its top-level helpers are extracted VERBATIM from the
 * source into a test namespace — the same technique as
 * tests/integration/test-share-public.php, and the extraction fails loudly if a
 * helper is renamed, so it can never silently stop testing anything.
 *
 * Usage: php packages/core/tests/unit/test-public-link-url.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

echo "\n{$cyan}══ ff_public_link_url() — the create-response link fallback ══{$reset}\n\n";

// ── Extract the helpers from api/index.php ──────────────────────────────────
$indexSrc = (string) file_get_contents(__DIR__ . '/../../api/index.php');
$code = '';
foreach (['ff_request_origin', 'ff_public_link_url'] as $fn) {
    if (!preg_match('#\nfunction ' . $fn . '\(.*?\n\}\n#s', $indexSrc, $m)) {
        fwrite(STDERR, "FAIL: {$fn}() not found in api/index.php — the create route changed shape.\n");
        exit(1);
    }
    $code .= $m[0];
}
eval('namespace LinkUrl;' . $code);

/** Both create branches must go through the helper — no inline duplicate. */
test('both create routes build the fallback with the helper', function () use ($indexSrc) {
    assertTrue(strpos($indexSrc, "ff_public_link_url('share.html'") !== false, 'share branch');
    assertTrue(strpos($indexSrc, "ff_public_link_url('intake.html'") !== false, 'intake branch');
    // The old inline form must not creep back in alongside it.
    assertTrue(strpos($indexSrc, "ff_request_origin() . '/public/share.html?token='") === false, 'no inline duplicate');
});

test('origin + page + token, http by default', function () {
    $_SERVER['HTTP_HOST'] = 'files.acme.com';
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    assertEqual(
        'http://files.acme.com/public/share.html?token=abc',
        \LinkUrl\ff_public_link_url('share.html', 'abc')
    );
    assertEqual(
        'http://files.acme.com/public/intake.html?token=abc',
        \LinkUrl\ff_public_link_url('intake.html', 'abc')
    );
});

test('a TLS-terminating proxy yields https', function () {
    $_SERVER['HTTP_HOST'] = 'files.acme.com';
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    try {
        assertEqual('https://files.acme.com/public/intake.html?token=t', \LinkUrl\ff_public_link_url('intake.html', 't'));
    } finally {
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    }
});

test('the token is rawurlencoded — `+` and `/` never reach the query raw', function () {
    $_SERVER['HTTP_HOST'] = 'h';
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    // A JWT is base64url, but the helper must not depend on that: `+` decodes to a
    // space and `/`&`?`&`#` would truncate or re-route the link.
    $url = \LinkUrl\ff_public_link_url('share.html', 'a+b/c=d?e#f&g');
    assertEqual('http://h/public/share.html?token=a%2Bb%2Fc%3Dd%3Fe%23f%26g', $url);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    assertEqual('a+b/c=d?e#f&g', $q['token'] ?? '', 'the recipient parses back the exact token');
});

test('a base64url token passes through unchanged', function () {
    $_SERVER['HTTP_HOST'] = 'h';
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    $jwt = 'eyJ0IjoiaW50YWtlIn0.eyJqdGkiOiJhLWJfYyJ9.sig-with_dashes';
    assertEqual('http://h/public/intake.html?token=' . $jwt, \LinkUrl\ff_public_link_url('intake.html', $jwt));
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
