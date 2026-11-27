<?php

/**
 * Performance pass on image delivery + listing:
 *   1. ImageOptimizer::process() decodes once and cascades large -> medium -> thumb
 *   2. list(dirs_only) returns folders only, with the same scope/hidden rules
 *   3. /img: ETag + 304, per-key transform lock, X-Accel on a local cache hit
 *   4. ImageToken start-window bounds
 *   5. list(compact_srcset): img_widths instead of the token-repeating img_srcset
 *
 * Usage: php tests/unit/test-perf-media.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\ImageOptimizer;
use FluxFiles\ImageToken;
use FluxFiles\JwtCompat;
use FluxFiles\MediaEndpoint;
use FluxFiles\StorageMetadataHandler;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
    echo "SKIP: GD with WebP is not available\n";
    exit(0);
}

$SECRET = str_repeat('s', 40);
$root = sys_get_temp_dir() . '/ff-perf-' . bin2hex(random_bytes(4));
mkdir($root, 0755, true);
register_shutdown_function(static function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); });

function jpeg(int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2), $h, imagecolorallocate($im, 200, 40, 40));
    ob_start(); imagejpeg($im, null, 90); return (string) ob_get_clean();
}

echo "\n{$cyan}══ 1. single-decode variants ══{$reset}\n\n";

test('3000x2000 -> thumb/medium/large keep their widths and aspect ratio', function () use ($root) {
    $fs = new Filesystem(new LocalFilesystemAdapter($root . '/v1'));
    file_put_contents($tmp = $root . '/big.jpg', jpeg(3000, 2000));
    $v = (new ImageOptimizer())->process($fs, 'a/big.jpg', $tmp);
    assertEqual(['thumb', 'medium', 'large'], array_keys($v), 'historical key order');
    assertEqual([150, 100], [$v['thumb']['width'], $v['thumb']['height']]);
    assertEqual([768, 512], [$v['medium']['width'], $v['medium']['height']]);
    assertEqual([1920, 1280], [$v['large']['width'], $v['large']['height']]);
    assertEqual('a/_variants/big.jpg_large.webp', $v['large']['key']);
    foreach ($v as $n => $x) {
        $info = getimagesizefromstring($fs->read($x['key']));
        assertEqual('image/webp', $info['mime'], $n);
        assertEqual([$x['width'], $x['height']], [$info[0], $info[1]], "$n bytes match the reported size");
    }
});

test('a small image only gets a thumb, never upscaled', function () use ($root) {
    $fs = new Filesystem(new LocalFilesystemAdapter($root . '/v2'));
    file_put_contents($tmp = $root . '/small.jpg', jpeg(100, 80));
    $v = (new ImageOptimizer())->process($fs, 'small.jpg', $tmp);
    assertEqual(['thumb'], array_keys($v));
    assertEqual(100, $v['thumb']['width']);
});

test('tenant width overrides (even out of order) still cascade correctly', function () use ($root) {
    $fs = new Filesystem(new LocalFilesystemAdapter($root . '/v3'));
    file_put_contents($tmp = $root . '/o.jpg', jpeg(2000, 1000));
    $v = (new ImageOptimizer(['thumb' => 200, 'medium' => 1200, 'large' => 500]))->process($fs, 'o.jpg', $tmp);
    assertEqual([200, 100], [$v['thumb']['width'], $v['thumb']['height']]);
    assertEqual(500, $v['large']['width']);
    assertEqual(1200, $v['medium']['width']);
});

test('a smaller variant is not noticeably worse than one cut from the original', function () use ($root) {
    // Cascade: medium is resized from the 1920 large. A flat-colour edge must survive.
    $fs = new Filesystem(new LocalFilesystemAdapter($root . '/v4'));
    file_put_contents($tmp = $root . '/q.jpg', jpeg(3000, 2000));
    $v = (new ImageOptimizer())->process($fs, 'q.jpg', $tmp);
    $im = imagecreatefromstring($fs->read($v['medium']['key']));
    $left = imagecolorsforindex($im, imagecolorat($im, 100, 256));
    $right = imagecolorsforindex($im, imagecolorat($im, 700, 256));
    assertTrue($left['red'] > 170 && $left['green'] < 80, 'left half stays red');
    assertTrue($right['red'] < 40 && $right['green'] < 40, 'right half stays black');
});

echo "\n{$cyan}══ 2. dirs_only listing ══{$reset}\n\n";

$lroot = $root . '/list';
foreach (['pub/a', 'pub/b', 'pub/.git', 'pub/_variants', 'other/z'] as $d) { mkdir($lroot . '/' . $d, 0755, true); }
foreach (['pub/f1.txt', 'pub/f2.jpg', 'pub/a/inner.txt', 'other/secret.txt'] as $f) { file_put_contents($lroot . '/' . $f, 'x'); }
function lfm(string $prefix, bool $ownerOnly = false): FileManager {
    global $lroot;
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $lroot, 'url' => '/s']]);
    $claims = new Claims('u1', ['read', 'write'], ['local'], $prefix, 50, null, 0, $ownerOnly);
    return new FileManager($dm, $claims, new StorageMetadataHandler($dm));
}

test('dirs_only returns only folders (hidden ones stay hidden), legacy mode', function () {
    $items = lfm('')->list('local', 'pub', 0, '', '', 'asc', 'all', true);
    assertEqual(['a', 'b'], array_column($items, 'name'));
    assertEqual(['dir', 'dir'], array_column($items, 'type'));
    $full = lfm('')->list('local', 'pub');
    assertTrue(in_array('file', array_column($full, 'type'), true), 'sanity: the normal listing has files');
});

test('dirs_only pages: total counts folders only', function () {
    $res = lfm('')->list('local', 'pub', 1, '', '', 'asc', 'all', true);
    assertEqual(2, $res['total']);
    assertEqual(['a'], array_column($res['items'], 'name'));
    assertEqual('pub/a', $res['next_cursor']);
    $res2 = lfm('')->list('local', 'pub', 1, $res['next_cursor'], '', 'asc', 'all', true);
    assertEqual(['b'], array_column($res2['items'], 'name'));
});

test('dirs_only honours the token prefix (keys are relative, never leaks outside)', function () {
    $items = lfm('pub')->list('local', '', 0, '', '', 'asc', 'all', true);
    assertEqual(['a', 'b'], array_column($items, 'name'));
    assertEqual(['a', 'b'], array_column($items, 'key'));
    try {
        $esc = lfm('pub')->list('local', '../other', 0, '', '', 'asc', 'all', true);
    } catch (\FluxFiles\ApiException $e) {
        $esc = [];
    }
    assertTrue(!in_array('z', array_column($esc, 'name'), true), 'cannot escape the prefix');
});

test('dirs_only still refuses system folders', function () {
    try {
        lfm('')->list('local', '_fluxfiles', 0, '', '', 'asc', 'all', true);
        throw new \RuntimeException('expected refusal');
    } catch (\FluxFiles\ApiException $e) {
        assertTrue($e->getHttpCode() >= 400);
    }
});

echo "\n{$cyan}══ 3. /img ETag, lock, X-Accel ══{$reset}\n\n";

class PerfEndpoint extends MediaEndpoint
{
    public int $code = 200;
    public array $headers = [];
    public string $body = '';
    protected function status(int $code): void { $this->code = $code; }
    protected function header(string $line, bool $replace = true, int $code = 0): void
    {
        $this->headers[] = $line;
        if ($code > 0) { $this->code = $code; }
    }
    protected function write(string $bytes): void { $this->body .= $bytes; }
    protected function flushOutput(): void {}
    public function hdr(string $name): ?string
    {
        foreach ($this->headers as $h) {
            if (stripos($h, $name . ':') === 0) { return trim(substr($h, strlen($name) + 1)); }
        }
        return null;
    }
    public function lockFor(string $id) { $m = new \ReflectionMethod(MediaEndpoint::class, 'lockTransform'); $m->setAccessible(true); return $m->invoke($this, $id); }
}

$mroot = $root . '/media';
mkdir($mroot, 0755, true);
file_put_contents($mroot . '/pic.jpg', jpeg(800, 600));
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $mroot]]);
$allow = static fn (string $s, string $b, int $d): bool => true;
$imgQuery = fn (): array => ['token' => ImageToken::mint('local', 'pic.jpg', 'u1', 300, $SECRET, 2000), 'width' => 300];
$accept = ['accept' => 'image/webp'];

test('cold then warm: both carry the same ETag; If-None-Match gets a body-less 304', function () use ($dm, $allow, $SECRET, $imgQuery, $accept, $mroot) {
    $cold = new PerfEndpoint($dm, $SECRET, $allow);
    $cold->image($imgQuery(), $accept);
    assertEqual(200, $cold->code);
    $etag = $cold->hdr('ETag');
    assertTrue($etag !== null && $etag[0] === '"', 'ETag on the cold answer');
    $warm = new PerfEndpoint($dm, $SECRET, $allow);
    $warm->image($imgQuery(), $accept);
    assertEqual($etag, $warm->hdr('ETag'), 'warm ETag equals cold ETag');
    assertEqual($cold->body, $warm->body);
    $cond = new PerfEndpoint($dm, $SECRET, $allow);
    $cond->image($imgQuery(), $accept + ['if_none_match' => $etag]);
    assertEqual(304, $cond->code);
    assertEqual('', $cond->body);
    assertEqual($etag, $cond->hdr('ETag'));
    $weak = new PerfEndpoint($dm, $SECRET, $allow);
    $weak->image($imgQuery(), $accept + ['if_none_match' => 'W/"nope", W/' . $etag]);
    assertEqual(304, $weak->code, 'weak validators match');
});

test('a different size or a changed source gets a different ETag, a stale one is not 304', function () use ($dm, $allow, $SECRET, $accept, $mroot) {
    $a = new PerfEndpoint($dm, $SECRET, $allow);
    $a->image(['token' => ImageToken::mint('local', 'pic.jpg', 'u1', 300, $SECRET, 2000), 'width' => 300], $accept);
    $b = new PerfEndpoint($dm, $SECRET, $allow);
    $b->image(['token' => ImageToken::mint('local', 'pic.jpg', 'u1', 300, $SECRET, 2000), 'width' => 500], $accept);
    assertTrue($a->hdr('ETag') !== $b->hdr('ETag'), 'width is part of the validator');
    touch($mroot . '/pic.jpg', time() + 100);
    clearstatcache();
    $c = new PerfEndpoint($dm, $SECRET, $allow);
    $c->image(['token' => ImageToken::mint('local', 'pic.jpg', 'u1', 300, $SECRET, 2000), 'width' => 300], $accept + ['if_none_match' => (string) $a->hdr('ETag')]);
    assertEqual(200, $c->code, 'source mtime changed: the old ETag no longer validates');
    assertTrue($c->hdr('ETag') !== $a->hdr('ETag'));
});

test('a bad token is never answered with 304', function () use ($dm, $allow, $SECRET, $accept) {
    $e = new PerfEndpoint($dm, $SECRET, $allow);
    $e->image(['token' => 'garbage'], $accept + ['if_none_match' => '*']);
    assertEqual(403, $e->code);
});

test('local cache hit with FLUXFILES_XACCEL hands the file to nginx (no body)', function () use ($dm, $allow, $SECRET, $imgQuery, $accept) {
    (new PerfEndpoint($dm, $SECRET, $allow))->image($imgQuery(), $accept); // warm
    $e = new PerfEndpoint($dm, $SECRET, $allow, '/_protected');
    $e->image($imgQuery(), $accept);
    assertEqual('', $e->body);
    $loc = (string) $e->hdr('X-Accel-Redirect');
    assertTrue(str_starts_with($loc, '/_protected/_variants/pic.jpg_w300_q80_'), $loc);
    assertEqual('image/webp', $e->hdr('Content-Type'));
});

test('transform lock is exclusive per key and released afterwards', function () use ($dm, $allow, $SECRET) {
    $e = new PerfEndpoint($dm, $SECRET, $allow);
    $id = 'local|x/_variants/a.jpg_w300_q80_1.webp';
    $h = $e->lockFor($id);
    assertTrue(is_resource($h), 'lock acquired');
    $file = sys_get_temp_dir() . '/fluxfiles-img-locks/' . hexdec(substr(hash('sha256', $id), 0, 2)) . '.lock';
    $probe = fopen($file, 'c');
    assertTrue(!flock($probe, LOCK_EX | LOCK_NB), 'a second request must wait');
    flock($h, LOCK_UN); fclose($h);
    assertTrue(flock($probe, LOCK_EX | LOCK_NB), 'free once released');
    fclose($probe);
});

echo "\n{$cyan}══ 4. ImageToken start window ══{$reset}\n\n";

function tokenTimes(int $ttl, ?string $version): array {
    global $SECRET;
    $p = JwtCompat::decode(ImageToken::mint('local', 'a.jpg', 'u1', $ttl, $SECRET, 2000, 0, null, true, null, $version), $SECRET);
    return [(int) $p->iat, (int) $p->exp];
}

test('versioned: start rounds back by < window, exp = start + ttl never beyond now + ttl', function () {
    foreach ([3600 => 900, 21600 => 5400, 86400 => ImageToken::MAX_STABLE_WINDOW, 90000 => ImageToken::MAX_STABLE_WINDOW] as $ttl => $window) {
        $now = time();
        [$iat, $exp] = tokenTimes($ttl, '123-456');
        $capped = min($ttl, ImageToken::MAX_TTL);
        assertEqual($exp, $iat + $capped, "ttl $ttl");
        assertTrue($iat <= $now && $now - $iat <= $window, "ttl $ttl: start moved back by at most $window s");
        assertEqual(0, $iat % $window, "ttl $ttl aligned to the window");
        assertTrue($exp <= $now + $capped, "ttl $ttl never outlives its TTL");
    }
});

test('same version inside one window -> same token; no version -> unrounded', function () {
    assertEqual(tokenTimes(86400, 'v1'), tokenTimes(86400, 'v1'));
    [$iat] = tokenTimes(86400, null);
    assertTrue(abs($iat - time()) <= 1, 'unversioned tokens keep their own start');
    assertEqual(21600, ImageToken::MAX_STABLE_WINDOW);
});

echo "\n{$cyan}══ 5. compact srcset ══{$reset}\n\n";

test('compact_srcset: img_widths + img_base, no repeated img_srcset; default keeps both', function () use ($root, $SECRET) {
    $r = $root . '/ss';
    mkdir($r, 0755, true);
    file_put_contents($r . '/a.jpg', jpeg(1500, 900));
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $r, 'url' => '/s']]);
    $claims = new Claims('u1', ['read'], ['local'], '', 50, null, 0);
    $claims->webpEnabled = true;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    $fm->setStreamSecret($SECRET);
    $full = $fm->list('local', '')[0];
    $compact = $fm->list('local', '', 0, '', '', 'asc', 'all', false, true)[0];
    assertTrue(!empty($full['img_srcset']) && !empty($full['img_widths']), 'default: srcset + widths');
    assertTrue(!isset($compact['img_srcset']), 'compact: no srcset string');
    assertEqual($full['img_widths'], $compact['img_widths']);
    assertEqual($full['img_base'], $compact['img_base']);
    $rebuilt = implode(', ', array_map(fn ($w) => $compact['img_base'] . '&width=' . $w . ' ' . $w . 'w', $compact['img_widths']));
    assertEqual($full['img_srcset'], $rebuilt, 'client-side build is byte-identical to the server one');
});

echo "\n";
echo $failed === 0 ? "{$green}All {$passed} passed{$reset}\n" : "{$red}{$failed} failed{$reset}, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
