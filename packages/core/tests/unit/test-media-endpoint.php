<?php

/**
 * MediaEndpoint: the framework-neutral /stream + /img logic. A subclass captures
 * status/headers/body through the output seams, so no web server is needed (the
 * self-booting test-stream-http.php / test-img-http.php cover the real wire).
 *
 * Usage: php tests/unit/test-media-endpoint.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\ImageToken;
use FluxFiles\MediaEndpoint;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\StreamToken;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/** Captures what MediaEndpoint emits instead of sending it. */
class CapturingEndpoint extends MediaEndpoint
{
    public int $code = 200;
    /** @var string[] */
    public array $headers = [];
    public string $body = '';
    public ?array $streamed = null;

    protected function status(int $code): void { $this->code = $code; }
    protected function header(string $line, bool $replace = true, int $code = 0): void
    {
        $this->headers[] = $line;
        if ($code > 0) { $this->code = $code; }
    }
    protected function write(string $bytes): void { $this->body .= $bytes; }
    protected function flushOutput(): void {}
    protected function streamLocal(string $abs, string $mime, ?string $range): void
    {
        $this->streamed = [$abs, $mime, $range];
    }
    public function hdr(string $name): ?string
    {
        foreach ($this->headers as $h) {
            if (stripos($h, $name . ':') === 0) { return trim(substr($h, strlen($name) + 1)); }
        }
        return null;
    }
}

$SECRET = str_repeat('s', 40);
$root = sys_get_temp_dir() . '/ff-media-' . bin2hex(random_bytes(4));
$pubRoot = $root . '/pub';
mkdir($root . '/priv/sub', 0755, true);
mkdir($pubRoot, 0755, true);
register_shutdown_function(static function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); });

file_put_contents($root . '/priv/sub/50% off #1.mp4', str_repeat('v', 100));
file_put_contents($root . '/priv/page.html', '<script>1</script>');
file_put_contents($root . '/pub/x.mp4', 'x');
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(400, 300);
    ob_start(); imagepng($im); file_put_contents($root . '/priv/pic.png', ob_get_clean());
}
file_put_contents($root . '/priv/note.txt', 'hello');

$dm = new DiskManager([
    'priv' => ['driver' => 'local', 'root' => $root . '/priv', 'private' => true],
    'pub'  => ['driver' => 'local', 'root' => $pubRoot],
    's3x'  => ['driver' => 's3'],
]);
$allow = static fn (string $sub, string $bucket, int $default): bool => true;
$deny = static fn (string $sub, string $bucket, int $default): bool => false;
function ep(DiskManager $dm, string $secret, callable $rl, string $xaccel = '', $repo = null): CapturingEndpoint {
    return new CapturingEndpoint($dm, $secret, $rl, $xaccel, $repo);
}
function st(string $disk, string $path, string $secret, bool $ar = false): array {
    return ['token' => StreamToken::mint($disk, $path, 'u1', 300, $secret, $ar)];
}

echo "\n{$cyan}══ MediaEndpoint ══{$reset}\n\n";

test('placeholder / empty secret: 500 and no token is ever verified', function () use ($dm, $allow, $SECRET) {
    foreach (['', 'change-me-to-random-32-char-string'] as $bad) {
        $e = ep($dm, $bad, $allow);
        $e->stream(st('priv', 'page.html', $SECRET));
        assertEqual(500, $e->code);
        assertEqual('FLUXFILES_SECRET is not configured', $e->body);
        $e = ep($dm, $bad, $allow);
        $e->image(['token' => 'x']);
        assertEqual(500, $e->code);
    }
});

test('stream: bad token, wrong token type -> 403 text/plain', function () use ($dm, $allow, $SECRET) {
    $e = ep($dm, $SECRET, $allow);
    $e->stream(['token' => 'garbage']);
    assertEqual(403, $e->code);
    assertTrue(str_starts_with((string) $e->hdr('Content-Type'), 'text/plain'));
    $imgTok = ImageToken::mint('priv', 'pic.png', 'u1', 300, $SECRET, 2000);
    $e = ep($dm, $SECRET, $allow);
    $e->stream(['token' => $imgTok]);
    assertEqual(403, $e->code, 'an /img token must not open /stream');
    $e = ep($dm, $SECRET, $allow);
    $e->image(['token' => StreamToken::mint('priv', 'pic.png', 'u1', 300, $SECRET)]);
    assertEqual(403, $e->code, 'a /stream token must not open /img');
});

test('stream: rate-limit callable false -> 429 and nothing else', function () use ($dm, $deny, $SECRET) {
    $e = ep($dm, $SECRET, $deny);
    $e->stream(st('priv', 'page.html', $SECRET));
    assertEqual(429, $e->code);
    assertEqual('Too many requests', $e->body);
    assertEqual(null, $e->streamed);
});

test('stream: traversal in the signed path is refused', function () use ($dm, $allow, $SECRET) {
    foreach (['../etc/passwd', 'a/../../b', "a\0b", ''] as $p) {
        $e = ep($dm, $SECRET, $allow);
        $e->stream(st('priv', $p, $SECRET));
        assertEqual(403, $e->code, json_encode($p));
        // '' is already refused by the token layer; the rest reach the endpoint's own guard.
        assertTrue(in_array($e->body, ['Invalid path', 'Stream token missing scope'], true), $e->body);
        assertEqual(null, $e->streamed);
    }
});

test('stream: only a gated local or SFTP disk is servable', function () use ($dm, $allow, $SECRET) {
    foreach (['pub', 's3x', 'nope'] as $disk) {
        $e = ep($dm, $SECRET, $allow);
        $e->stream(st($disk, 'x.mp4', $SECRET));
        assertEqual(403, $e->code, $disk);
        assertEqual('Streaming not available for this disk', $e->body);
    }
});

test('stream: gated local hands the file + Range to the streamer; non-media is forced to download', function () use ($dm, $allow, $SECRET, $root) {
    $e = ep($dm, $SECRET, $allow);
    $e->stream(st('priv', 'sub/50% off #1.mp4', $SECRET), ['range' => 'bytes=0-9']);
    assertEqual(200, $e->code);
    assertEqual([realpath($root . '/priv/sub/50% off #1.mp4'), 'video/mp4', 'bytes=0-9'], $e->streamed);
    assertEqual('private, no-store', $e->hdr('Cache-Control'));
    assertEqual('nosniff', $e->hdr('X-Content-Type-Options'));
    assertTrue(str_starts_with((string) $e->hdr('Content-Disposition'), 'inline;'));
    $e = ep($dm, $SECRET, $allow);
    $e->stream(st('priv', 'page.html', $SECRET));
    assertTrue(str_starts_with((string) $e->hdr('Content-Disposition'), 'attachment;'), 'html must never render inline');
});

test('stream: missing file -> 404', function () use ($dm, $allow, $SECRET) {
    $e = ep($dm, $SECRET, $allow);
    $e->stream(st('priv', 'gone.mp4', $SECRET));
    assertEqual(404, $e->code);
});

test('stream: X-Accel target is percent-encoded per segment and bypasses PHP streaming', function () use ($dm, $allow, $SECRET) {
    $e = ep($dm, $SECRET, $allow, '/internal-priv/');
    $e->stream(st('priv', 'sub/50% off #1.mp4', $SECRET));
    assertEqual('/internal-priv/sub/50%25%20off%20%231.mp4', $e->hdr('X-Accel-Redirect'));
    assertEqual('no', $e->hdr('X-Accel-Buffering'));
    assertEqual(null, $e->streamed);
    assertEqual('/x/a/b%20c', MediaEndpoint::xaccelUri('/x', '/a/b c'));
});

test('stream: audit_reads logs stream_read once, only for a first (Range-less / bytes=0-) request', function () use ($dm, $allow, $SECRET) {
    $repo = new StorageMetadataHandler($dm);
    $e = ep($dm, $SECRET, $allow, '', $repo);
    $e->stream(st('priv', 'page.html', $SECRET, true), ['range' => 'bytes=50-']);
    assertEqual(0, count($repo->readAudit('priv')), 'a seek is not a new read');
    $e = ep($dm, $SECRET, $allow, '', static fn () => $repo);
    $e->stream(st('priv', 'page.html', $SECRET, true), ['range' => 'bytes=0-']);
    $rows = $repo->readAudit('priv');
    assertEqual(1, count($rows));
    assertEqual('stream_read', $rows[0]['action']);
    $e = ep($dm, $SECRET, $allow, '', $repo);
    $e->stream(st('priv', 'page.html', $SECRET, false));
    assertEqual(1, count($repo->readAudit('priv')), 'token without ar=1 logs nothing');
});

test('defaultRateLimit: <=0 disables; a 429 blocks; any other limiter failure fails OPEN', function () {
    $never = static fn (int $l) => new class implements \FluxFiles\RateLimiterStorageInterface {
        public function check(string $i, string $a): void { throw new \FluxFiles\ApiException('x', 429); }
    };
    $broken = static fn (int $l) => new class implements \FluxFiles\RateLimiterStorageInterface {
        public function check(string $i, string $a): void { throw new \FluxFiles\ApiException('disk full', 500); }
    };
    assertEqual(true, MediaEndpoint::defaultRateLimit(static fn () => 0, $never)('s', 'img', 120));
    assertEqual(false, MediaEndpoint::defaultRateLimit(static fn () => 5, $never)('s', 'img', 120));
    assertEqual(true, MediaEndpoint::defaultRateLimit(static fn () => 5, $broken)('s', 'img', 120));
});

test('defaultRateLimit: limit comes from FLUXFILES_<BUCKET>_RATE_LIMIT, keyed on sub', function () {
    $seen = [];
    $spy = static function (int $l) use (&$seen) {
        return new class($seen, $l) implements \FluxFiles\RateLimiterStorageInterface {
            public function __construct(private array &$seen, private int $l) {}
            public function check(string $i, string $a): void { $this->seen[] = [$i, $a, $this->l]; }
        };
    };
    $_ENV['FLUXFILES_STREAM_RATE_LIMIT'] = '7';
    MediaEndpoint::defaultRateLimit(null, $spy)('', 'stream', 300);
    MediaEndpoint::defaultRateLimit(null, $spy)('bob', 'img', 120);
    unset($_ENV['FLUXFILES_STREAM_RATE_LIMIT']);
    assertEqual([['anonymous', 'stream', 7]], [$seen[0]]);
    assertEqual(['bob', 'img', 120], $seen[1]);
});

test('headersFromServer / snapQuality', function () {
    assertEqual(['range' => 'bytes=1-', 'accept' => 'image/webp'], MediaEndpoint::headersFromServer(['HTTP_RANGE' => 'bytes=1-', 'HTTP_ACCEPT' => 'image/webp']));
    assertEqual(['range' => null, 'accept' => ''], MediaEndpoint::headersFromServer([]));
    assertEqual(80, MediaEndpoint::snapQuality(0));
    assertEqual(60, MediaEndpoint::snapQuality(10));
    assertEqual(90, MediaEndpoint::snapQuality(99));
    assertEqual(75, MediaEndpoint::snapQuality('74'));
});

// ── /img ────────────────────────────────────────────────────────────────
$hasGd = function_exists('imagecreatetruecolor') && function_exists('imagewebp');
function it(string $disk, string $path, string $secret, ?array $wm = null, bool $dl = true): array {
    return ['token' => ImageToken::mint($disk, $path, 'u1', 300, $secret, 2000, 0, $wm, $dl)];
}

test('img: not an image / missing file / unknown disk', function () use ($dm, $allow, $SECRET) {
    $e = ep($dm, $SECRET, $allow);
    $e->image(it('priv', 'note.txt', $SECRET));
    assertEqual([403, 'Not an image'], [$e->code, $e->body]);
    $e = ep($dm, $SECRET, $allow);
    $e->image(it('priv', 'gone.png', $SECRET));
    assertEqual([404, 'Not found'], [$e->code, $e->body]);
    $e = ep($dm, $SECRET, $allow);
    $e->image(it('ghost', 'pic.png', $SECRET));
    assertEqual([404, 'Disk not available'], [$e->code, $e->body]);
});

if ($hasGd) {
    test('img: format=auto negotiates from Accept; a plain client gets the original', function () use ($dm, $allow, $SECRET) {
        $e = ep($dm, $SECRET, $allow);
        $e->image(it('priv', 'pic.png', $SECRET) + ['width' => 200], ['accept' => 'image/webp,*/*']);
        assertEqual(200, $e->code);
        assertEqual('image/webp', $e->hdr('Content-Type'));
        assertEqual('Accept', $e->hdr('Vary'));
        assertTrue(str_contains((string) $e->hdr('Cache-Control'), 'immutable'));
        assertEqual((string) strlen($e->body), $e->hdr('Content-Length'));
        $e = ep($dm, $SECRET, $allow);
        $e->image(it('priv', 'pic.png', $SECRET) + ['width' => 200], ['accept' => '*/*']);
        assertEqual('image/png', $e->hdr('Content-Type'));
        assertEqual('private, no-store', $e->hdr('Cache-Control'));
    });

    test('img: a no-download token never gets the clean original, even with a wildcard Accept', function () use ($dm, $allow, $SECRET) {
        $e = ep($dm, $SECRET, $allow);
        $e->image(it('priv', 'pic.png', $SECRET, null, false) + ['width' => 200], ['accept' => '*/*']);
        assertEqual('image/webp', $e->hdr('Content-Type'));
    });

    test('img: watermark overlay is applied, cached under its own key, and a missing logo falls back to text with a warning', function () use ($dm, $allow, $SECRET, $root) {
        $wm = ['enabled' => true, 'type' => 'text', 'text' => 'PREVIEW', 'position' => 'center', 'opacity' => 0.5, 'font_size' => 24];
        $plain = ep($dm, $SECRET, $allow);
        $plain->image(it('priv', 'pic.png', $SECRET) + ['width' => 200, 'format' => 'webp']);
        $marked = ep($dm, $SECRET, $allow);
        $marked->image(it('priv', 'pic.png', $SECRET, $wm) + ['width' => 200, 'format' => 'webp']);
        assertEqual(200, $marked->code);
        assertTrue($marked->body !== $plain->body, 'overlay changed the pixels');
        $variants = glob($root . '/priv/_variants/*') ?: glob($root . '/priv/*/_variants/*') ?: [];
        assertTrue(count($variants) >= 2, 'plain and watermarked variants cached separately');
        $logo = ['enabled' => true, 'type' => 'logo', 'logo_path' => 'no-such-logo.png', 'position' => 'center', 'opacity' => 0.5];
        $e = ep($dm, $SECRET, $allow);
        $e->image(it('priv', 'pic.png', $SECRET, $logo) + ['width' => 200, 'format' => 'webp']);
        assertEqual('watermark-logo-missing', $e->hdr('X-FluxFiles-Warning'));
        assertEqual(200, $e->code);
    });
}

test('resolveWatermark: disabled -> not enabled; text fallback uses the sub', function () use ($dm, $allow, $SECRET) {
    $e = ep($dm, $SECRET, $allow);
    assertEqual([false, null, null, ''], $e->resolveWatermark($dm->disk('priv'), null, 'u1'));
    [$on, $sig] = $e->resolveWatermark($dm->disk('priv'), ['enabled' => true, 'type' => 'text'], 'alice');
    assertEqual(true, $on);
    assertEqual('alice', $sig['text']);
    assertEqual('bottom-right', $sig['position']);
});

// ── BYOB disks: the per-file token carries the encrypted config ──────────
$sftpCfg = ['driver' => 'sftp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'top-secret-pass', 'root' => 'r'];

test('tokens without a BYOB blob verify with byob=null (older tokens keep working)', function () use ($SECRET) {
    assertEqual(null, StreamToken::verify(StreamToken::mint('d', 'a.mp4', 'u', 60, $SECRET), $SECRET)['byob']);
    assertEqual(null, ImageToken::verify(ImageToken::mint('d', 'a.png', 'u', 60, $SECRET, 100), $SECRET)['byob']);
});

test('a BYOB token carries only ciphertext, and round-trips it', function () use ($SECRET, $sftpCfg) {
    $blob = \FluxFiles\CredentialEncryptor::encrypt($sftpCfg, $SECRET);
    $t = StreamToken::mint('b', 'a.mp4', 'u', 60, $SECRET, false, $blob);
    assertEqual($blob, StreamToken::verify($t, $SECRET)['byob']);
    assertEqual($blob, ImageToken::verify(ImageToken::mint('b', 'a.png', 'u', 60, $SECRET, 100, 0, null, true, $blob), $SECRET)['byob']);
    $payload = (string) base64_decode(strtr(explode('.', $t)[1], '-_', '+/'));
    assertTrue(!str_contains($payload, 'top-secret-pass') && !str_contains($payload, '127.0.0.1'), 'plaintext config in the token');
});

test('a BYOB token pointing at a private host is refused (SSRF), 403 with no details', function () use ($dm, $SECRET, $allow, $sftpCfg) {
    $blob = \FluxFiles\CredentialEncryptor::encrypt($sftpCfg, $SECRET);
    foreach ([
        ['stream', StreamToken::mint('byobx', 'a.mp4', 'u', 60, $SECRET, false, $blob)],
        ['image', ImageToken::mint('byobx', 'a.png', 'u', 60, $SECRET, 100, 0, null, true, $blob)],
    ] as [$method, $tok]) {
        $e = ep($dm, $SECRET, $allow);
        $e->$method(['token' => $tok]);
        assertEqual(403, $e->code, $method);
        assertTrue(!str_contains($e->body, '127.0.0.1'), 'host leaked');
    }
    assertEqual([], $dm->config('byobx'), 'not registered');
});

test('a BYOB token cannot shadow a statically configured disk', function () use ($dm, $SECRET, $allow, $sftpCfg) {
    \FluxFiles\SsrfGuard::$allowTestHosts[] = '127.0.0.1';
    try {
        $blob = \FluxFiles\CredentialEncryptor::encrypt($sftpCfg, $SECRET);
        $e = ep($dm, $SECRET, $allow);
        $e->stream(['token' => StreamToken::mint('priv', 'sub/x.mp4', 'u', 60, $SECRET, false, $blob)]);
        assertEqual(403, $e->code);
        assertEqual('local', $dm->config('priv')['driver'] ?? '', 'static disk untouched');
    } finally {
        \FluxFiles\SsrfGuard::$allowTestHosts = [];
    }
});

test('a blob sealed with another secret is refused', function () use ($dm, $SECRET, $allow, $sftpCfg) {
    $blob = \FluxFiles\CredentialEncryptor::encrypt($sftpCfg, str_repeat('z', 40));
    $e = ep($dm, $SECRET, $allow);
    $e->stream(['token' => StreamToken::mint('byoby', 'a.mp4', 'u', 60, $SECRET, false, $blob)]);
    assertEqual(403, $e->code);
});

test('an unreachable BYOB server answers 502 without the connection error', function () use ($dm, $SECRET, $allow, $sftpCfg) {
    \FluxFiles\SsrfGuard::$allowTestHosts[] = '127.0.0.1';
    try {
        $blob = \FluxFiles\CredentialEncryptor::encrypt($sftpCfg, $SECRET);
        foreach ([
            ['stream', StreamToken::mint('byobz', 'a.mp4', 'u', 60, $SECRET, false, $blob)],
            ['image', ImageToken::mint('byobz', 'a.png', 'u', 60, $SECRET, 100, 0, null, true, $blob)],
        ] as [$method, $tok]) {
            $e = ep($dm, $SECRET, $allow);
            $e->$method(['token' => $tok]);
            assertEqual(502, $e->code, $method);
            assertTrue(!str_contains($e->body, 'top-secret-pass') && !str_contains($e->body, '127.0.0.1'), 'leak: ' . $e->body);
        }
    } finally {
        \FluxFiles\SsrfGuard::$allowTestHosts = [];
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
