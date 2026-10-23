<?php

/**
 * MediaPoster + the `media_posters` upload path. Skips the render cases when
 * Ghostscript / ffmpeg aren't installed (they're optional at runtime too).
 *
 * Usage: php tests/unit/test-media-poster.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\MediaPoster;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function minimalPdf(): string {
    $objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] /Contents 4 0 R >>"];
    $stream = "0 0 1 rg 20 20 160 60 re f";
    $objs[] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
    $out = "%PDF-1.4\n"; $offs = [];
    foreach ($objs as $i => $o) { $offs[] = strlen($out); $out .= ($i + 1) . " 0 obj\n{$o}\nendobj\n"; }
    $x = strlen($out);
    $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offs as $o) { $out .= sprintf("%010d 00000 n \n", $o); }
    return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$x}\n%%EOF\n";
}

function posterEnv(bool $on = true, bool $download = true): array {
    $root = sys_get_temp_dir() . '/fluxfiles-poster-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $c = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    $c->mediaPosters = $on;
    $c->allowDownload = $download;
    return [new FileManager($dm, $c, new StorageMetadataHandler($dm)), $root];
}
function up(FileManager $fm, string $name, string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'pst');
    file_put_contents($tmp, $bytes);
    try { return $fm->upload('local', '', ['name' => $name, 'size' => strlen($bytes), 'tmp_name' => $tmp]); }
    finally { @unlink($tmp); }
}

echo "\n{$cyan}══ Media posters ══{$reset}\n\n";

test('kindFor maps by extension only', function () {
    assertTrue(MediaPoster::kindFor('a/b.PDF') === 'pdf' && MediaPoster::kindFor('x.mp4') === 'video'
        && MediaPoster::kindFor('x.webm') === 'video' && MediaPoster::kindFor('x.jpg') === null
        && MediaPoster::kindFor('x.m3u8') === null, 'mapping');
});

if (MediaPoster::available('pdf')) {
    test('PDF upload gets a poster thumb, listed as `poster`, removed with the file', function () {
        [$fm, $root] = posterEnv();
        $r = up($fm, 'report.pdf', minimalPdf());
        assertTrue(isset($r['poster']) && is_file("{$root}/_variants/report.pdf_thumb.webp"), 'poster written: ' . json_encode($r));
        $item = array_values(array_filter($fm->list('local', ''), fn ($i) => $i['name'] === 'report.pdf'))[0];
        assertTrue(isset($item['poster']), 'listed');
        $fm->delete('local', 'report.pdf');
        assertTrue(!is_file("{$root}/_variants/report.pdf_thumb.webp"), 'cleaned up with the file');
    });
    test('preview-only tokens never get a poster; media_posters=false renders none', function () {
        [$fm] = posterEnv(true, false);
        up($fm, 'p.pdf', minimalPdf());
        $item = array_values(array_filter($fm->list('local', ''), fn ($i) => $i['name'] === 'p.pdf'))[0];
        assertTrue(!isset($item['poster']), 'withheld');
        [$fm, $root] = posterEnv(false);
        $r = up($fm, 'q.pdf', minimalPdf());
        assertTrue(!isset($r['poster']) && !is_file("{$root}/_variants/q.pdf_thumb.webp"), 'off');
    });
} else {
    echo "  {$yellow}SKIP{$reset} PDF poster cases — Ghostscript not installed\n";
}

test('media_posters is opt-in: absent from the token → off', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local']]);
    assertTrue($c->mediaPosters === false, 'default off');
    $on = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'media_posters' => true]);
    assertTrue($on->mediaPosters === true, 'explicit true');
});

test('external tools get a minimal environment (no inherited secrets)', function () {
    $env = \FluxFiles\ProcessEnv::minimal();
    assertTrue(array_keys($env) === ['PATH', 'HOME', 'TMPDIR', 'LANG'], 'only PATH/HOME/TMPDIR/LANG: ' . implode(',', array_keys($env)));
    putenv('FLUXFILES_PROBE_SECRET=leak-me');
    $proc = proc_open(['sh', '-c', 'printf %s "${FLUXFILES_PROBE_SECRET:-none}"'], [1 => ['pipe', 'w']], $pipes, null, $env);
    $seen = stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($proc);
    putenv('FLUXFILES_PROBE_SECRET');
    assertTrue($seen === 'none', 'a parent secret does not reach the child');
});

test('Ghostscript older than the minimum is treated as missing', function () {
    assertTrue(version_compare('10.04.9', \FluxFiles\PdfOptimizer::MIN_GS_VERSION, '<'), 'floor sanity');
    $gs = \FluxFiles\PdfOptimizer::binary();
    if ($gs !== null) {
        $v = trim((string) shell_exec(escapeshellarg($gs) . ' --version'));
        assertTrue(version_compare($v, \FluxFiles\PdfOptimizer::MIN_GS_VERSION, '>='), "a found gs is new enough ({$v})");
    }
});

if (MediaPoster::available('video')) {
    test('video upload gets a poster frame', function () {
        $tmp = tempnam(sys_get_temp_dir(), 'vid') . '.mp4';
        exec('ffmpeg -nostdin -loglevel error -f lavfi -i color=c=blue:s=160x90:d=2 -pix_fmt yuv420p -y ' . escapeshellarg($tmp));
        [$fm, $root] = posterEnv();
        $r = up($fm, 'clip.mp4', (string) file_get_contents($tmp));
        @unlink($tmp);
        assertTrue(isset($r['poster']) && is_file("{$root}/_variants/clip.mp4_thumb.webp"), json_encode($r));
    });
    test('a playlist disguised as .mp4 is not followed (demuxer forced, file protocol only)', function () {
        $secret = sys_get_temp_dir() . '/ff-secret-' . uniqid() . '.txt';
        file_put_contents($secret, 'TOP-SECRET');
        $m3u = "#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:10.0,\nfile://{$secret}\n#EXT-X-ENDLIST\n";
        [$fm, $root] = posterEnv();
        $r = up($fm, 'evil.mp4', $m3u);
        @unlink($secret);
        assertTrue(!isset($r['poster']), 'no poster rendered from a playlist: ' . json_encode($r));
    });
} else {
    echo "  {$yellow}SKIP{$reset} video poster cases — ffmpeg not installed\n";
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
