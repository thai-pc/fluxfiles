<?php

/**
 * The public Share bytes route, at the level where the two verified defects live:
 * the DRIVER-DISPATCH matrix (a share must never redirect to a static local URL)
 * and the Content-Disposition / MIME matrix, plus the path-containment guard and
 * the preview-URL payload.
 *
 * Why the eval: the `ff_share_*` helpers are extracted VERBATIM from the source and
 * re-declared inside a test namespace — where PHP's
 * function fallback makes their unqualified `header()` calls resolve to our
 * recorder instead of the (silent, uninspectable) CLI builtin. That is the only way
 * to observe "302 vs streamed" without a live web server; the extraction throws if a
 * helper is renamed, so it can never silently stop testing anything.
 * Header/status assertions over real HTTP live in tests/e2e/test-share-http.php.
 *
 * Usage: php tests/integration/test-share-public.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\ImageToken;
use FluxFiles\ShareGrant;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $yellow = "\033[33m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function expectApi(callable $f, string $code, ?int $http = null): void
{
    try { $f(); throw new \RuntimeException("expected {$code}"); }
    catch (ApiException $e) {
        assertEqual($code, $e->getErrorCode());
        if ($http !== null) { assertEqual($http, $e->getHttpCode()); }
    }
}

// ── Extract the route helpers from api/PublicLinks.php ──────────────────────
// They used to live in api/index.php and moved when WordPress needed to serve the
// same public routes; a plain require would still not do, because the namespace
// re-declaration is what lets this test observe header() calls.
$indexSrc = (string) file_get_contents(__DIR__ . '/../../api/PublicLinks.php');
$wanted = ['ff_share_send_bytes', 'ff_share_headers', 'ff_content_disposition', 'ff_share_payload', 'ff_share_token_jti', 'ff_share_rate_limit', 'ff_intake_rate_limit'];
$code = '';
foreach ($wanted as $fn) {
    if (!preg_match('#\nfunction ' . $fn . '\(.*?\n\}\n#s', $indexSrc, $m)) {
        fwrite(STDERR, "FAIL: {$fn}() not found in api/PublicLinks.php — the share bytes route changed shape.\n");
        exit(1);
    }
    $code .= $m[0];
}
eval(
    'namespace ShareRoutes;' .
    'use FluxFiles\ApiException; use FluxFiles\DiskManager; use FluxFiles\RateLimiterFileStorage;' .
    // Header sink: shadows the builtin for the extracted code only (namespace fallback).
    'function header(string $h, bool $replace = true, int $code = 0): void { $GLOBALS["ff_headers"][] = [$h, $code]; }' .
    $code
);

/** Call the bytes route; returns [body, headers[], statusCodes[]]. */
function sendBytes(DiskManager $dm, array $cfgs, array $res, bool $dl = false): array
{
    $GLOBALS['ff_headers'] = [];
    // RangeStreamer emits its own headers through the REAL header() (it's in the
    // FluxFiles namespace, so our sink doesn't shadow it); under CLI that warns
    // "headers already sent" straight into the output buffer. Swallow only that.
    set_error_handler(static fn (int $n, string $s): bool => strpos($s, 'Cannot modify header information') !== false);
    ob_start();
    try {
        \ShareRoutes\ff_share_send_bytes($dm, $cfgs, $res, $dl);
    } finally {
        $body = (string) ob_get_clean();
        restore_error_handler();
    }
    return [$body, $GLOBALS['ff_headers']];
}
/** First value of header $name (case-insensitive), or null. */
function hdr(array $headers, string $name): ?string
{
    foreach ($headers as [$line, $_]) {
        if (stripos($line, $name . ':') === 0) { return trim(substr($line, strlen($name) + 1)); }
    }
    return null;
}
function hdrStatus(array $headers, string $name): int
{
    foreach ($headers as [$line, $code]) {
        if (stripos($line, $name . ':') === 0) { return $code; }
    }
    return 0;
}
function allHeaders(array $headers): string
{
    return implode("\n", array_map(fn ($h) => $h[0], $headers));
}
function disposition(string $name, bool $inline): string
{
    return \ShareRoutes\ff_content_disposition($name, $inline);
}

/** A temp local disk root with a few shared objects under users/42/. */
function makeRoot(): string
{
    $root = sys_get_temp_dir() . '/ff-share-pub-' . bin2hex(random_bytes(5));
    @mkdir($root . '/users/42/reports', 0777, true);
    file_put_contents($root . '/users/42/reports/q3.pdf', 'PDF-BYTES-Q3');
    file_put_contents($root . '/users/42/clip.mp4', 'MP4');
    file_put_contents($root . '/users/42/pic.png', 'PNG');
    file_put_contents($root . '/users/42/vector.svg', '<svg onload="alert(1)"></svg>');
    file_put_contents($root . '/users/42/page.html', '<script>alert(1)</script>');
    file_put_contents($root . '/users/42/notes.txt', 'text');
    return $root;
}
function rmrf(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = "{$dir}/{$f}";
        is_dir($p) && !is_link($p) ? rmrf($p) : @unlink($p);
    }
    @rmdir($dir);
}

$ROOT = makeRoot();
$LOCAL_PUBLIC  = ['driver' => 'local', 'root' => $ROOT, 'url' => '/storage/uploads'];             // statically served
$LOCAL_PRIVATE = ['driver' => 'local', 'root' => $ROOT, 'url' => '/storage/uploads', 'private' => true];
$S3            = ['driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'acme-files', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret-example', 'endpoint' => '', 'visibility' => 'private'];
$R2            = ['driver' => 's3', 'region' => 'auto', 'bucket' => 'acme-r2', 'key' => 'r2key', 'secret' => 'r2secret', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'visibility' => 'private'];

/** resolveShare()'s contract, as the route receives it. */
function res(string $disk, string $path, string $mime = '', int $ttl = 60): array
{
    return ['disk' => $disk, 'path' => $path, 'name' => basename($path), 'mime' => $mime, 'size' => 12, 'jti' => 'j', 'remaining' => null, 'preview' => true, 'url_ttl' => $ttl];
}

echo "\n{$cyan}══ Share — public bytes route (driver dispatch + disposition) ══{$reset}\n\n";

// ── 1. Content-Disposition (RFC 5987) ───────────────────────────────────────

test('disposition: inline vs attachment, both filename forms', function () {
    assertEqual('inline; filename="q3.pdf"; filename*=UTF-8\'\'q3.pdf', disposition('q3.pdf', true));
    assertEqual('attachment; filename="q3.pdf"; filename*=UTF-8\'\'q3.pdf', disposition('q3.pdf', false));
});

test('disposition: a non-ASCII name is ASCII-folded + percent-encoded, never raw', function () {
    $d = disposition('báo-cáo Q3.pdf', false);
    // One underscore per non-ASCII BYTE — the ASCII fallback is a best-effort label;
    // a correct client reads filename* instead.
    assertTrue(strpos($d, 'filename="b__o-c__o Q3.pdf"') !== false, "ascii fallback: {$d}");
    assertTrue(strpos($d, "filename*=UTF-8''b%C3%A1o-c%C3%A1o%20Q3.pdf") !== false, "rfc5987: {$d}");
});

test('disposition: quotes/backslashes/CRLF cannot break out of the header', function () {
    $d = disposition("ev\"il\\; x=\r\nX-Injected: 1.pdf", false);
    assertTrue(strpos($d, "\r") === false && strpos($d, "\n") === false, 'no raw CRLF');
    assertTrue(substr_count($d, '"') === 2, 'exactly one quoted value: ' . $d);
});

// ── 2. Inline/attachment matrix (MIME by extension, never sniffed) ──────────

$inlineCases = [
    ['users/42/reports/q3.pdf', 'application/pdf', true,  'pdf previews inline'],
    ['users/42/clip.mp4',       'video/mp4',       true,  'video inline'],
    ['users/42/pic.png',        'image/png',       true,  'image inline'],
    ['users/42/vector.svg',     'image/svg+xml',   false, 'SVG is active content → attachment'],
    ['users/42/page.html',      'text/html',       false, 'HTML → attachment (no XSS in our origin)'],
    ['users/42/notes.txt',      'text/plain',      false, 'text → attachment'],
];
foreach ($inlineCases as [$path, $mime, $inline, $why]) {
    test("disposition matrix: {$mime} → " . ($inline ? 'inline' : 'attachment') . " ({$why})", function () use ($path, $mime, $inline, $ROOT, $LOCAL_PUBLIC) {
        $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
        [, $h] = sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', $path, $mime));
        assertEqual(0, strpos((string) hdr($h, 'Content-Disposition'), $inline ? 'inline;' : 'attachment;'), (string) hdr($h, 'Content-Disposition'));
    });
}

test('dl=1 forces attachment even for an inline-safe type', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    [, $h] = sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/reports/q3.pdf', 'application/pdf'), true);
    assertEqual(0, strpos((string) hdr($h, 'Content-Disposition'), 'attachment;'));
});

test('MIME comes from the EXTENSION when the record has none (html is never sniffed inline)', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    [, $h] = sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/page.html', ''));
    assertEqual(0, strpos((string) hdr($h, 'Content-Disposition'), 'attachment;'));
});

test('the filename is the basename — the storage key never reaches the recipient', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    [, $h] = sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/reports/q3.pdf', 'application/pdf'));
    $d = (string) hdr($h, 'Content-Disposition');
    assertTrue(strpos($d, 'q3.pdf') !== false, 'has the display name');
    assertTrue(strpos($d, 'users') === false && strpos($d, 'reports') === false, "leaked the key: {$d}");
});

test('every share response carries the hardening headers', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    [, $h] = sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/page.html', 'text/html'));
    assertEqual('nosniff', hdr($h, 'X-Content-Type-Options'));
    assertEqual('sandbox', hdr($h, 'Content-Security-Policy'));
    assertEqual('private, no-store', hdr($h, 'Cache-Control'));
    assertEqual('no-referrer', hdr($h, 'Referrer-Policy'));
});

// ── 3. Driver dispatch — DEFECT 2: never a static local URL ────────────────

test('DEFECT 2 — a PUBLIC local disk streams through the app, never a redirect', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    [$body, $h] = sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/reports/q3.pdf', 'application/pdf'));
    assertEqual('PDF-BYTES-Q3', $body, 'bytes came through the app');
    assertEqual(null, hdr($h, 'Location'), 'no redirect');
    // The disk's static `url` base must appear nowhere — that URL bypasses password/cap.
    assertTrue(strpos(allHeaders($h) . $body, '/storage/uploads') === false, 'static URL leaked');
});

test('a PRIVATE (gated) local disk streams too', function () use ($LOCAL_PRIVATE) {
    $dm = new DiskManager(['local' => $LOCAL_PRIVATE]);
    [$body, $h] = sendBytes($dm, ['local' => $LOCAL_PRIVATE], res('local', 'users/42/reports/q3.pdf', 'application/pdf'));
    assertEqual('PDF-BYTES-Q3', $body);
    assertEqual(null, hdr($h, 'Location'));
});

test('X-Accel fast path only on a PRIVATE local disk (public still streams)', function () use ($LOCAL_PRIVATE, $LOCAL_PUBLIC) {
    $_ENV['FLUXFILES_XACCEL'] = '/internal-uploads';
    try {
        $dm = new DiskManager(['local' => $LOCAL_PRIVATE]);
        [$body, $h] = sendBytes($dm, ['local' => $LOCAL_PRIVATE], res('local', 'users/42/reports/q3.pdf', 'application/pdf'));
        assertEqual('/internal-uploads/users/42/reports/q3.pdf', hdr($h, 'X-Accel-Redirect'));
        assertEqual('', $body, 'nginx serves the bytes, PHP sends none');
        assertEqual('inline; filename="q3.pdf"; filename*=UTF-8\'\'q3.pdf', hdr($h, 'Content-Disposition'), 'disposition still ours, not nginx\'s');

        $dm2 = new DiskManager(['local' => $LOCAL_PUBLIC]);
        [$body2, $h2] = sendBytes($dm2, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/reports/q3.pdf', 'application/pdf'));
        assertEqual(null, hdr($h2, 'X-Accel-Redirect'), 'public root is not the internal location');
        assertEqual('PDF-BYTES-Q3', $body2);
    } finally {
        unset($_ENV['FLUXFILES_XACCEL']);
    }
});

test('s3 → 302 to a presigned URL carrying the disposition, no bytes through the app', function () use ($S3) {
    $dm = new DiskManager(['s3' => $S3]);
    [$body, $h] = sendBytes($dm, ['s3' => $S3], res('s3', 'users/42/reports/q3.pdf', 'application/pdf'), true);
    $loc = (string) hdr($h, 'Location');
    assertEqual(302, hdrStatus($h, 'Location'), 'redirect status');
    assertEqual('', $body, 'no app egress');
    assertTrue(strpos($loc, 'X-Amz-Signature=') !== false, "presigned: {$loc}");
    assertTrue(strpos($loc, 'X-Amz-Expires=60') !== false, "url_ttl honoured: {$loc}");
    assertTrue(stripos($loc, 'response-content-disposition=attachment') !== false, "disposition signed in: {$loc}");
    // The hardening headers ride the redirect too.
    assertEqual('no-referrer', hdr($h, 'Referrer-Policy'));
});

test('s3 url_ttl is clamped to a floor of 10s', function () use ($S3) {
    $dm = new DiskManager(['s3' => $S3]);
    [, $h] = sendBytes($dm, ['s3' => $S3], res('s3', 'users/42/pic.png', 'image/png', 1));
    assertTrue(strpos((string) hdr($h, 'Location'), 'X-Amz-Expires=10') !== false, (string) hdr($h, 'Location'));
});

test('r2 (driver s3 + custom endpoint) also redirects, to the R2 host', function () use ($R2) {
    $dm = new DiskManager(['r2' => $R2]);
    [$body, $h] = sendBytes($dm, ['r2' => $R2], res('r2', 'users/42/pic.png', 'image/png'));
    assertEqual('', $body);
    assertTrue(strpos((string) hdr($h, 'Location'), 'acct.r2.cloudflarestorage.com') !== false, (string) hdr($h, 'Location'));
});

test('sftp → streamed through the app (no presign, no static URL)', function () use ($ROOT) {
    // A DiskManager whose disk() hands back a real Filesystem while the CONFIG says
    // sftp — exercises the sftp branch's readStream() loop without a network.
    $cfg = ['sftp' => ['driver' => 'sftp', 'host' => 'sftp.example.com', 'root' => '/']];
    $dm = new class ($cfg, $ROOT) extends DiskManager {
        private \League\Flysystem\Filesystem $stub;
        public function __construct(array $c, string $root)
        {
            parent::__construct($c);
            $this->stub = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($root));
        }
        public function disk(string $name): \League\Flysystem\Filesystem { return $this->stub; }
    };
    [$body, $h] = sendBytes($dm, $cfg, res('sftp', 'users/42/reports/q3.pdf', 'application/pdf'));
    assertEqual('PDF-BYTES-Q3', $body);
    assertEqual(null, hdr($h, 'Location'), 'never a redirect');
    assertEqual('application/pdf', hdr($h, 'Content-Type'));
});

test('sftp: a vanished object → 404 share_gone, not a 500', function () use ($ROOT) {
    $cfg = ['sftp' => ['driver' => 'sftp', 'host' => 'sftp.example.com']];
    $dm = new class ($cfg, $ROOT) extends DiskManager {
        private \League\Flysystem\Filesystem $stub;
        public function __construct(array $c, string $root)
        {
            parent::__construct($c);
            $this->stub = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($root));
        }
        public function disk(string $name): \League\Flysystem\Filesystem { return $this->stub; }
    };
    expectApi(fn () => sendBytes($dm, $cfg, res('sftp', 'users/42/ghost.pdf', 'application/pdf')), 'share_gone', 404);
});

test('an unknown/unsupported driver → 502 share_unavailable (never a guess)', function () {
    $cfg = ['weird' => ['driver' => 'dropbox']];
    $dm = new DiskManager($cfg);
    expectApi(fn () => sendBytes($dm, $cfg, res('weird', 'users/42/pic.png', 'image/png')), 'share_unavailable', 502);
    // A disk that isn't in the config at all is equally refused.
    expectApi(fn () => sendBytes($dm, $cfg, res('nope', 'users/42/pic.png', 'image/png')), 'share_unavailable', 502);
});

// ── 4. Path containment (the record is signed, but the route re-checks) ─────

test('traversal in the record path → 403 share_invalid, nothing served', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    foreach (['../../etc/passwd', 'users/42/../../../etc/passwd', '..', 'a/../../b'] as $bad) {
        expectApi(fn () => sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', $bad, 'text/plain')), 'share_invalid', 403);
    }
});

test('a NUL byte or an empty path → 403 share_invalid', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    expectApi(fn () => sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', "users/42/pic.png\0.txt", 'image/png')), 'share_invalid', 403);
    expectApi(fn () => sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', '', 'image/png')), 'share_invalid', 403);
});

test('a windows-style ..\\ traversal is caught too', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    expectApi(fn () => sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users\\..\\..\\etc\\passwd', 'text/plain')), 'share_invalid', 403);
});

test('a symlink pointing outside the disk root → 404, the secret is never emitted', function () use ($ROOT, $LOCAL_PUBLIC) {
    $outside = $ROOT . '-evil';                       // sibling dir sharing the root's prefix
    @mkdir($outside, 0777, true);
    file_put_contents($outside . '/secret.txt', 'TOP-SECRET');
    @symlink($outside . '/secret.txt', $ROOT . '/users/42/escape.txt');
    try {
        $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
        try {
            [$body] = sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/escape.txt', 'text/plain'));
            throw new \RuntimeException('should have refused, body=' . $body);
        } catch (ApiException $e) {
            assertEqual('share_gone', $e->getErrorCode());
            assertEqual(404, $e->getHttpCode());
        }
    } finally {
        @unlink($ROOT . '/users/42/escape.txt');
        rmrf($outside);
    }
});

test('a missing file or a directory path → 404 share_gone', function () use ($LOCAL_PUBLIC) {
    $dm = new DiskManager(['local' => $LOCAL_PUBLIC]);
    expectApi(fn () => sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/ghost.pdf', 'application/pdf')), 'share_gone', 404);
    expectApi(fn () => sendBytes($dm, ['local' => $LOCAL_PUBLIC], res('local', 'users/42/reports', 'application/pdf')), 'share_gone', 404);
});

// ── 5. The info payload (preview URLs are minted core-side) ────────────────

$card = static function (array $over = []): array {
    $file = ['name' => 'q3.pdf', 'size' => 12, 'mime' => 'application/pdf', 'kind' => 'pdf', 'preview_url' => null];
    return ['info' => array_merge([
        'label' => 'Q3', 'expires' => time() + 600, 'has_password' => false,
        'downloads' => 0, 'max_downloads' => 0, 'remaining' => null,
        'file' => $file, 'files' => [$file], 'brand' => null,
    ], $over), 'preview' => null];
};

test('payload: no preview → the card is passed through untouched, preview_url stays null', function () use ($card) {
    $out = $card();
    $p = \ShareRoutes\ff_share_payload($out, 'sharetoken', str_repeat('k', 40));
    assertEqual(null, $p['file']['preview_url']);
    assertEqual(null, $p['files'][0]['preview_url']);
});

test('payload: an image preview is a scoped /img token, never the raw bytes route', function () use ($card) {
    $secret = str_repeat('k', 40);
    $out = $card();
    $out['info']['file']['kind'] = 'image';
    $out['info']['files'][0]['kind'] = 'image';
    $out['preview'] = ['kind' => 'image', 'disk' => 'local', 'path' => 'users/42/pic.png', 'sub' => 'share:abc123', 'ttl' => ShareGrant::DEFAULT_TTL];
    $p = \ShareRoutes\ff_share_payload($out, 'sharetoken', $secret);
    $url = (string) $p['file']['preview_url'];
    assertEqual(0, strpos($url, '/api/fm/img?token='), $url);
    assertTrue(strpos($url, '&width=1200') !== false, $url);
    assertTrue(strpos($url, 'sharetoken') === false, 'the share token must not ride the preview URL');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $tok = ImageToken::verify((string) $q['token'], $secret);
    assertEqual('users/42/pic.png', $tok['path'], 'scoped to the shared object');
    assertEqual('local', $tok['disk']);
    assertEqual('share:abc123', $tok['sub'], 'attributable to the share');
    assertEqual(1600, $tok['maxWidth'], 'width clamp bounds variant spawning');
    assertEqual($url, $p['files'][0]['preview_url'], 'both card shapes agree');
});

test('payload: a PDF preview reuses the bytes route and carries the grant when present', function () use ($card) {
    $out = $card();
    $out['preview'] = ['kind' => 'pdf', 'disk' => 'local', 'path' => 'users/42/reports/q3.pdf', 'sub' => 'share:abc', 'ttl' => 600];
    $p = \ShareRoutes\ff_share_payload($out, 'sh are+tok', str_repeat('k', 40));
    assertEqual('/api/fm/share/file?token=sh%20are%2Btok', $p['file']['preview_url'], 'token is URL-encoded');

    $out['info']['grant'] = 'GRANT.TOK.EN';
    $p2 = \ShareRoutes\ff_share_payload($out, 'tok', str_repeat('k', 40));
    assertTrue(strpos((string) $p2['file']['preview_url'], '&g=GRANT.TOK.EN') !== false, (string) $p2['file']['preview_url']);
});

test('payload: a locked card (pre-unlock) gains nothing — no name, no preview', function () {
    $locked = ['info' => ['has_password' => true, 'expires' => time() + 600, 'brand' => null], 'preview' => null];
    $p = \ShareRoutes\ff_share_payload($locked, 'tok', str_repeat('k', 40));
    assertEqual(['has_password', 'expires', 'brand'], array_keys($p));
});

test('payload: no response body KEY carries the storage layout (the tokens still do, by design)', function () use ($card) {
    $out = $card();
    $out['preview'] = ['kind' => 'image', 'disk' => 'local', 'path' => 'users/42/pic.png', 'sub' => 'share:abc', 'ttl' => 600];
    $p = \ShareRoutes\ff_share_payload($out, 'tok', str_repeat('k', 40));
    foreach (['path', 'disk', 'store', 'password_hash', 'owner'] as $k) {
        assertTrue(!array_key_exists($k, $p), "leaked {$k}");
        assertTrue(!array_key_exists($k, $p['file']), "leaked {$k} on the file card");
    }
    // …but the preview token is a READABLE JWT scoped to the shared object, exactly
    // like the share token itself (which must carry `prefix`/`store` to be
    // self-contained). The recipient can decode the key of the one file they are
    // already authorised to read — asserted here so nobody documents otherwise.
    parse_str((string) parse_url((string) $p['file']['preview_url'], PHP_URL_QUERY), $q);
    $tok = ImageToken::verify((string) $q['token'], str_repeat('k', 40));
    assertEqual('users/42/pic.png', $tok['path'], 'disclosure is intentional and scoped');
});

// ── 6. A share/portal token is NOT a main access token ─────────────────────
// The share token is signed with FLUXFILES_SECRET and carries {perms:['read'],
// disks, prefix} — i.e. it LOOKS like a valid access JWT. If the main auth path
// accepted it, a recipient could replay it as `Authorization: Bearer` on
// /api/fm/content, /zip or /presign and read the file with no password, no
// download cap and no working revocation (revoke only deletes the storage record).

/** A token in the exact shape ShareModule::createShare mints. */
function shareShapedToken(string $secret, array $over = []): string
{
    return \FluxFiles\JwtCompat::encode(array_merge([
        't' => 'share', 'sub' => 'share:u42', 'iat' => time(), 'exp' => time() + 600,
        'jti' => bin2hex(random_bytes(12)), 'perms' => ['read'], 'disks' => ['local'],
        'prefix' => 'users/42/reports/q3.pdf', 'share' => true, 'store' => 'users/42/',
        'allow_download' => false, 'allow_zip' => false, 'allow_code_edit' => false,
    ], $over), $secret);
}

test('REPLAY: a share token is refused by the main auth path (403 token_not_access)', function () {
    $secret = str_repeat('k', 40);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle(shareShapedToken($secret), $secret), 'token_not_access', 403);
});

test('REPLAY: the refusal survives dropping the `t` marker or the `share` flag', function () {
    $secret = str_repeat('k', 40);
    // Tokens minted before the type marker existed carry only `share:true`…
    $legacy = shareShapedToken($secret);
    $legacy = \FluxFiles\JwtCompat::encode(array_diff_key(
        (array) \FluxFiles\JwtCompat::decode($legacy, $secret),
        ['t' => 1]
    ), $secret);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle($legacy, $secret), 'token_not_access', 403);
    // …and a forger who strips `share` still can't pass, because `t` is checked too
    // (and stripping either one breaks the signature on a REAL token anyway).
    $noFlag = shareShapedToken($secret, ['share' => false]);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle($noFlag, $secret), 'token_not_access', 403);
});

test('REPLAY: the intake portal token and the stream/img/grant tokens are refused too', function () {
    $secret = str_repeat('k', 40);
    $portal = \FluxFiles\JwtCompat::encode([
        't' => 'intake', 'sub' => 'intake:u42', 'exp' => time() + 600, 'jti' => bin2hex(random_bytes(12)),
        'perms' => ['write'], 'disks' => ['local'], 'prefix' => 'users/42/drop', 'intake' => true, 'store' => 'users/42/',
    ], $secret);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle($portal, $secret), 'token_not_access', 403);
    foreach ([
        \FluxFiles\StreamToken::mint('local', 'users/42/clip.mp4', 'u42', 600, $secret),
        ImageToken::mint('local', 'users/42/pic.png', 'u42', 600, $secret, 1600),
        ShareGrant::mint(bin2hex(random_bytes(12)), 600, $secret),
    ] as $t) {
        expectApi(fn () => \FluxFiles\JwtMiddleware::handle($t, $secret), 'token_not_access', 403);
    }
});

test('a normal access token still authenticates (the guard is not a blanket denial)', function () {
    $secret = str_repeat('k', 40);
    $access = \FluxFiles\JwtCompat::encode([
        'sub' => 'u42', 'exp' => time() + 600, 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => 'users/42/',
    ], $secret);
    $c = \FluxFiles\JwtMiddleware::handle($access, $secret);
    assertEqual('u42', $c->userId);
    assertTrue($c->hasPerm('write'));
});

// ── 7. Rate-limit bucket keying ────────────────────────────────────────────

test('rate-limit bucket: a real share token buckets by its jti, junk by token hash', function () {
    $jti = bin2hex(random_bytes(12));
    $tok = \FluxFiles\JwtCompat::encode(['jti' => $jti, 'share' => true, 'exp' => time() + 60], str_repeat('k', 40));
    assertEqual($jti, \ShareRoutes\ff_share_token_jti($tok), 'per-share bucket');
    // Junk still gets a stable, per-token bucket (never one global bucket that one
    // visitor could exhaust for everybody).
    $a = \ShareRoutes\ff_share_token_jti('garbage');
    $b = \ShareRoutes\ff_share_token_jti('other-garbage');
    assertEqual(24, strlen($a));
    assertTrue($a !== $b, 'distinct buckets');
    assertEqual($a, \ShareRoutes\ff_share_token_jti('garbage'), 'stable');
});

test('unlock: rotating REMOTE_ADDR does NOT escape the per-share ceiling', function () {
    // The per-IP bucket is keyed on the one value an attacker controls for free
    // (proxy pool, or a single IPv6 /64), so it can never be the only limit. The
    // second bucket has no attacker-controlled component at all.
    $dir = sys_get_temp_dir() . '/ff-share-rl-' . bin2hex(random_bytes(5));
    @mkdir($dir, 0777, true);
    $envKeep = [$_ENV['FLUXFILES_STORAGE_PATH'] ?? null, $_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT'] ?? null, $_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL'] ?? null];
    $addrKeep = $_SERVER['REMOTE_ADDR'] ?? null;
    $_ENV['FLUXFILES_STORAGE_PATH'] = $dir;
    $_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT'] = '3';    // per share + IP
    $_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL'] = '5';    // per share, whatever the IP
    try {
        $tok = \FluxFiles\JwtCompat::encode(
            ['t' => 'share', 'jti' => bin2hex(random_bytes(12)), 'share' => true, 'exp' => time() + 60],
            str_repeat('k', 40)
        );
        $allowed = 0;
        for ($i = 0; $i < 40; $i++) {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.' . $i;   // a fresh source every attempt
            try { \ShareRoutes\ff_share_rate_limit($tok, 'unlock'); $allowed++; }
            catch (ApiException $e) { assertEqual('rate_limited', $e->getErrorCode()); break; }
        }
        assertEqual(5, $allowed, 'the per-share ceiling stops a rotating attacker');

        // A DIFFERENT share is unaffected — one attacked link doesn't lock the rest.
        $other = \FluxFiles\JwtCompat::encode(
            ['t' => 'share', 'jti' => bin2hex(random_bytes(12)), 'share' => true, 'exp' => time() + 60],
            str_repeat('k', 40)
        );
        \ShareRoutes\ff_share_rate_limit($other, 'unlock');
        // …and so is the roomier view bucket, so a guesser can't take the link down.
        \ShareRoutes\ff_share_rate_limit($tok, 'view');
    } finally {
        [$sp, $ul, $ut] = $envKeep;
        if ($sp === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $sp; }
        if ($ul === null) { unset($_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT']); } else { $_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT'] = $ul; }
        if ($ut === null) { unset($_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL']); } else { $_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL'] = $ut; }
        if ($addrKeep === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $addrKeep; }
        rmrf($dir);
    }
});

test('unlock: one IP is still capped tighter than the whole share', function () {
    $dir = sys_get_temp_dir() . '/ff-share-rl-' . bin2hex(random_bytes(5));
    @mkdir($dir, 0777, true);
    $keep = [$_ENV['FLUXFILES_STORAGE_PATH'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null];
    $_ENV['FLUXFILES_STORAGE_PATH'] = $dir;
    $_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT'] = '3';
    $_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL'] = '30';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    try {
        $tok = \FluxFiles\JwtCompat::encode(
            ['t' => 'share', 'jti' => bin2hex(random_bytes(12)), 'share' => true, 'exp' => time() + 60],
            str_repeat('k', 40)
        );
        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            try { \ShareRoutes\ff_share_rate_limit($tok, 'unlock'); $allowed++; }
            catch (ApiException $e) { break; }
        }
        assertEqual(3, $allowed, 'one guesser is stopped long before the share ceiling');
    } finally {
        [$sp, $addr] = $keep;
        if ($sp === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $sp; }
        unset($_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT'], $_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL']);
        if ($addr === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $addr; }
        rmrf($dir);
    }
});

test('rate-limit bucket: a forged jti claim cannot be an arbitrary string', function () {
    // The jti is read from the UNVERIFIED payload, so it must be shape-checked.
    $tok = \FluxFiles\JwtCompat::encode(['jti' => '../../etc/passwd', 'exp' => time() + 60], str_repeat('k', 40));
    assertEqual(24, strlen(\ShareRoutes\ff_share_token_jti($tok)), 'fell back to a hash bucket');
});

// ── 7. With the paid module wired in (skipped when it isn't checked out) ───

$modSrc = __DIR__ . '/../../../share/src/ShareModule.php';
if (is_file($modSrc)) {
    require_once $modSrc;
}
if (class_exists('\FluxFiles\Share\ShareModule')) {
    echo "\n{$cyan}── module wired: create → resolve → bytes ──{$reset}\n\n";

    $SECRET = str_repeat('m', 40);
    $mkShare = static function (array $body = [], bool $ownerOnly = false, string $user = 'u42'): array {
        $root = sys_get_temp_dir() . '/ff-share-mod-' . bin2hex(random_bytes(5));
        @mkdir($root . '/users/42/reports', 0777, true);
        file_put_contents($root . '/users/42/reports/q3.pdf', 'PDF-BYTES-Q3');
        $cfg = ['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage/uploads']];
        $dm = new DiskManager($cfg);
        $claims = new \FluxFiles\Claims($user, ['read', 'write'], ['local'], 'users/42/', 50, null, 0, $ownerOnly);
        $claims->allowShare = true;
        $fm = new \FluxFiles\FileManager($dm, $claims, new \FluxFiles\StorageMetadataHandler($dm));
        $mod = new \FluxFiles\Share\ShareModule();
        $r = $mod->createShare($fm, $dm, $claims, str_repeat('m', 40), array_merge(['disk' => 'local', 'path' => 'reports/q3.pdf'], $body));
        return [$mod, $dm, $cfg, $claims, $r, $root];
    };

    test('create under a tenant prefix → the record lands at users/42/_fluxfiles/shares.json', function () use ($mkShare) {
        [, , , , $r, $root] = $mkShare();
        assertTrue(is_file($root . '/users/42/_fluxfiles/shares.json'), 'storage-resident record under the prefix');
        $all = json_decode((string) file_get_contents($root . '/users/42/_fluxfiles/shares.json'), true);
        assertEqual('users/42/reports/q3.pdf', $all[$r['jti']]['path']);
        assertEqual('users/42/', $all[$r['jti']]['store'], 'store recorded');
        rmrf($root);
    });

    test('DEFECT 2 end-to-end: resolve → bytes on a PUBLIC local disk streams, never redirects', function () use ($mkShare, $SECRET) {
        [$mod, $dm, $cfg, , $r, $root] = $mkShare();
        $res = $mod->resolveShare($dm, $SECRET, $r['token'], null, true);
        [$body, $h] = sendBytes($dm, $cfg, $res, true);
        assertEqual('PDF-BYTES-Q3', $body);
        assertEqual(null, hdr($h, 'Location'), 'a public local share must not redirect to the static URL');
        assertEqual('attachment; filename="q3.pdf"; filename*=UTF-8\'\'q3.pdf', hdr($h, 'Content-Disposition'));
        rmrf($root);
    });

    test('DEFECT 1 end-to-end: a token minted without `store` no longer resolves', function () use ($mkShare, $SECRET) {
        [$mod, $dm, , , $r, $root] = $mkShare();
        $noStore = \FluxFiles\JwtCompat::encode([
            't' => 'share', 'sub' => 'share:u42', 'iat' => time(), 'exp' => time() + 600, 'jti' => $r['jti'],
            'perms' => ['read'], 'disks' => ['local'], 'prefix' => 'users/42/reports/q3.pdf', 'share' => true,
        ], $SECRET);
        expectApi(fn () => $mod->resolveShare($dm, $SECRET, $noStore, null, true), 'share_revoked', 404);
        // And a token from before the type marker isn't a share link at all.
        $legacy = \FluxFiles\JwtCompat::encode([
            'sub' => 'share:u42', 'iat' => time(), 'exp' => time() + 600, 'jti' => $r['jti'],
            'perms' => ['read'], 'disks' => ['local'], 'prefix' => 'users/42/reports/q3.pdf',
            'share' => true, 'store' => 'users/42/',
        ], $SECRET);
        expectApi(fn () => $mod->resolveShare($dm, $SECRET, $legacy, null, true), 'share_invalid', 403);
        rmrf($root);
    });

    test('B1 end-to-end: the REAL minted share token is refused by the main auth path', function () use ($mkShare, $SECRET) {
        [, , , , $r, $root] = $mkShare(['max_downloads' => 1, 'password' => 's3cret']);
        // The exact string handed to a recipient, replayed as a Bearer token.
        expectApi(fn () => \FluxFiles\JwtMiddleware::handle((string) $r['token'], $SECRET), 'token_not_access', 403);
        // Belt and braces: even if a route ever forgot the guard, the payload itself
        // denies the routes that would have leaked the bytes.
        $p = \FluxFiles\JwtCompat::decode((string) $r['token'], $SECRET);
        $c = \FluxFiles\Claims::fromJwtPayload($p);
        assertEqual(false, $c->allowDownload, 'presign GET / clean URLs denied');
        assertEqual(false, $c->allowZip, '/zip denied');
        assertEqual(false, $c->allowCodeEdit, 'content edit denied');
        rmrf($root);
    });

    test('N1: revocation writes a TOMBSTONE, and a stale counter write cannot resurrect it', function () use ($mkShare, $SECRET) {
        [$mod, $dm, , $claims, $r, $root] = $mkShare();
        $store = $root . '/users/42/_fluxfiles/shares.json';
        $live = json_decode((string) file_get_contents($store), true)[$r['jti']];
        $mod->revokeShare($dm, $claims, 'local', $r['jti']);
        $after = json_decode((string) file_get_contents($store), true);
        assertEqual(true, $after[$r['jti']]['revoked'], 'tombstone, not a deletion');
        assertTrue(!array_key_exists('password_hash', $after[$r['jti']]), 'the tombstone keeps nothing');

        // The interleave this exists for: a landing view rewrites the WHOLE tenant map
        // on every hit (shareInfo bumps `views`), so a view whose write lands after a
        // revoke used to put the pre-revoke record back. putRecord now refuses to
        // write over a tombstone — reached here through reflection, because every
        // public entrypoint correctly throws before it.
        $put = new \ReflectionMethod($mod, 'putRecord');
        $put->setAccessible(true);
        $live['views'] = 99;
        $put->invoke($mod, $dm->disk('local'), 'users/42/', $r['jti'], $live);
        assertEqual(true, json_decode((string) file_get_contents($store), true)[$r['jti']]['revoked'], 'stale write ignored');
        expectApi(fn () => $mod->shareInfo($dm, $SECRET, $r['token']), 'share_revoked', 404);
        rmrf($root);
    });

    test('N1: a revoked share disappears from share/list and stays revoked after a view attempt', function () use ($mkShare, $SECRET) {
        [$mod, $dm, , $claims, $r, $root] = $mkShare();
        $mod->revokeShare($dm, $claims, 'local', $r['jti']);
        assertEqual(0, count($mod->listShares($dm, $claims, 'local')), 'tombstones are not shares');
        expectApi(fn () => $mod->shareInfo($dm, $SECRET, $r['token']), 'share_revoked', 404);
        expectApi(fn () => $mod->unlockShare($dm, $SECRET, $r['token'], 'x'), 'share_revoked', 404);
        expectApi(fn () => $mod->resolveShare($dm, $SECRET, $r['token'], null, true), 'share_revoked', 404);
        assertEqual(false, $mod->revokeShare($dm, $claims, 'local', $r['jti'])['revoked'], 're-revoke is a no-op');
        rmrf($root);
    });

    test('the whole info payload (module + core) leaks no storage path or disk name', function () use ($mkShare, $SECRET) {
        [$mod, $dm, , , $r, $root] = $mkShare(['path' => 'reports/q3.pdf', 'label' => "Q3\x07 report"]);
        $out = $mod->shareInfo($dm, $SECRET, $r['token']);
        $payload = \ShareRoutes\ff_share_payload($out, $r['token'], $SECRET);
        $json = (string) json_encode($payload);
        assertTrue(strpos($json, 'users/42') === false, "storage prefix leaked: {$json}");
        assertTrue(strpos($json, '"disk"') === false, "disk leaked: {$json}");
        assertEqual('Q3 report', $payload['label'], 'control chars stripped from the label');
        rmrf($root);
    });

    test('the bytes route refuses a resolve result for a revoked share (module throws first)', function () use ($mkShare, $SECRET) {
        [$mod, $dm, , $claims, $r, $root] = $mkShare();
        $mod->revokeShare($dm, $claims, 'local', $r['jti']);
        expectApi(fn () => $mod->resolveShare($dm, $SECRET, $r['token'], null, true), 'share_revoked', 404);
        rmrf($root);
    });

    test('cap, expiry and revocation stay three DISTINCT error codes', function () use ($mkShare, $SECRET) {
        [$mod, $dm, , $claims, $r, $root] = $mkShare(['max_downloads' => 1]);
        $mod->resolveShare($dm, $SECRET, $r['token'], null, true);
        expectApi(fn () => $mod->resolveShare($dm, $SECRET, $r['token'], null, true), 'share_exhausted', 410);
        $expired = \FluxFiles\JwtCompat::encode([
            'sub' => 'share:u42', 'iat' => time() - 100, 'exp' => time() - 10, 'jti' => $r['jti'],
            'perms' => ['read'], 'disks' => ['local'], 'prefix' => 'users/42/reports/q3.pdf', 'share' => true, 'store' => 'users/42/',
        ], $SECRET);
        expectApi(fn () => $mod->shareInfo($dm, $SECRET, $expired), 'share_expired', 410);
        $mod->revokeShare($dm, $claims, 'local', $r['jti']);
        expectApi(fn () => $mod->shareInfo($dm, $SECRET, $r['token']), 'share_revoked', 404);
        rmrf($root);
    });

    test('owner_only: another tenant on the same prefix can neither list nor revoke', function () use ($mkShare) {
        [$mod, $dm, , , $r, $root] = $mkShare([], true, 'u42');
        $other = new \FluxFiles\Claims('u99', ['read'], ['local'], 'users/42/', 50, null, 0, true);
        $other->allowShare = true;
        assertEqual(0, count($mod->listShares($dm, $other, 'local')), 'not their share');
        expectApi(fn () => $mod->revokeShare($dm, $other, 'local', $r['jti']), 'perm_denied', 403);
        rmrf($root);
    });
} else {
    echo "\n  {$yellow}skip{$reset} module-wired section (packages/share not checked out)\n";
}

rmrf($ROOT);

// ── 8. Intake rate-limit bucket keying ───────────────────────────────────────

test('intake upload: rotating REMOTE_ADDR does NOT escape the per-portal ceiling', function () {
    $dir = sys_get_temp_dir() . '/ff-intake-rl-' . bin2hex(random_bytes(5));
    @mkdir($dir, 0777, true);
    $envKeep = [$_ENV['FLUXFILES_STORAGE_PATH'] ?? null, $_ENV['FLUXFILES_INTAKE_UPLOAD_LIMIT'] ?? null, $_ENV['FLUXFILES_INTAKE_UPLOAD_TOTAL'] ?? null];
    $addrKeep = $_SERVER['REMOTE_ADDR'] ?? null;
    $_ENV['FLUXFILES_STORAGE_PATH'] = $dir;
    $_ENV['FLUXFILES_INTAKE_UPLOAD_LIMIT'] = '3';   // per portal + IP
    $_ENV['FLUXFILES_INTAKE_UPLOAD_TOTAL'] = '5';   // per portal, whatever the IP
    try {
        $tok = \FluxFiles\JwtCompat::encode(
            ['t' => 'intake', 'jti' => bin2hex(random_bytes(12)), 'intake' => true, 'exp' => time() + 60],
            str_repeat('k', 40)
        );
        $allowed = 0;
        for ($i = 0; $i < 40; $i++) {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.' . $i;   // a fresh source every attempt
            try { \ShareRoutes\ff_intake_rate_limit($tok, 'upload'); $allowed++; }
            catch (ApiException $e) { assertEqual('rate_limited', $e->getErrorCode()); break; }
        }
        assertEqual(5, $allowed, 'the per-portal ceiling stops a rotating uploader');

        // A DIFFERENT portal is unaffected — one flooded portal doesn't lock the rest.
        $other = \FluxFiles\JwtCompat::encode(
            ['t' => 'intake', 'jti' => bin2hex(random_bytes(12)), 'intake' => true, 'exp' => time() + 60],
            str_repeat('k', 40)
        );
        \ShareRoutes\ff_intake_rate_limit($other, 'upload');
        // …and so is the roomier info bucket.
        \ShareRoutes\ff_intake_rate_limit($tok, 'info');
    } finally {
        [$sp, $ul, $ut] = $envKeep;
        if ($sp === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $sp; }
        if ($ul === null) { unset($_ENV['FLUXFILES_INTAKE_UPLOAD_LIMIT']); } else { $_ENV['FLUXFILES_INTAKE_UPLOAD_LIMIT'] = $ul; }
        if ($ut === null) { unset($_ENV['FLUXFILES_INTAKE_UPLOAD_TOTAL']); } else { $_ENV['FLUXFILES_INTAKE_UPLOAD_TOTAL'] = $ut; }
        if ($addrKeep === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $addrKeep; }
        rmrf($dir);
    }
});

test('intake upload: one IP is still capped tighter than the whole portal', function () {
    $dir = sys_get_temp_dir() . '/ff-intake-rl-' . bin2hex(random_bytes(5));
    @mkdir($dir, 0777, true);
    $keep = [$_ENV['FLUXFILES_STORAGE_PATH'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null];
    $_ENV['FLUXFILES_STORAGE_PATH'] = $dir;
    $_ENV['FLUXFILES_INTAKE_UPLOAD_LIMIT'] = '3';
    $_ENV['FLUXFILES_INTAKE_UPLOAD_TOTAL'] = '30';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    try {
        $tok = \FluxFiles\JwtCompat::encode(
            ['t' => 'intake', 'jti' => bin2hex(random_bytes(12)), 'intake' => true, 'exp' => time() + 60],
            str_repeat('k', 40)
        );
        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            try { \ShareRoutes\ff_intake_rate_limit($tok, 'upload'); $allowed++; }
            catch (ApiException $e) { break; }
        }
        assertEqual(3, $allowed, 'one flooder is stopped long before the portal ceiling');
    } finally {
        [$sp, $addr] = $keep;
        if ($sp === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $sp; }
        unset($_ENV['FLUXFILES_INTAKE_UPLOAD_LIMIT'], $_ENV['FLUXFILES_INTAKE_UPLOAD_TOTAL']);
        if ($addr === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $addr; }
        rmrf($dir);
    }
});

test('intake info: the roomy bucket is independent of the upload bucket', function () {
    $dir = sys_get_temp_dir() . '/ff-intake-rl-' . bin2hex(random_bytes(5));
    @mkdir($dir, 0777, true);
    $keep = $_ENV['FLUXFILES_STORAGE_PATH'] ?? null;
    $_ENV['FLUXFILES_STORAGE_PATH'] = $dir;
    $_ENV['FLUXFILES_INTAKE_RATE_LIMIT'] = '4';
    try {
        $tok = \FluxFiles\JwtCompat::encode(
            ['t' => 'intake', 'jti' => bin2hex(random_bytes(12)), 'intake' => true, 'exp' => time() + 60],
            str_repeat('k', 40)
        );
        $allowed = 0;
        for ($i = 0; $i < 10; $i++) {
            try { \ShareRoutes\ff_intake_rate_limit($tok, 'info'); $allowed++; }
            catch (ApiException $e) { assertEqual('rate_limited', $e->getErrorCode()); break; }
        }
        assertEqual(4, $allowed, 'info bucket caps at its own limit');
    } finally {
        if ($keep === null) { unset($_ENV['FLUXFILES_STORAGE_PATH']); } else { $_ENV['FLUXFILES_STORAGE_PATH'] = $keep; }
        unset($_ENV['FLUXFILES_INTAKE_RATE_LIMIT']);
        rmrf($dir);
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
