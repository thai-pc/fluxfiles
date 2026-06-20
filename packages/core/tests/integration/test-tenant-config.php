<?php

declare(strict_types=1);

require_once __DIR__ . '/../../embed.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\ImageOptimizer;
use FluxFiles\JwtCompat;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** FileManager on a fresh local disk with a hand-built Claims (so we can set new claims). */
function fmWith(Claims $claims): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-tenant-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    return [new FileManager($dm, $claims, $meta), $root];
}

/** A JPEG of the given width on disk; returns the tmp path. */
function makeJpeg(int $width = 1000, int $height = 600): string
{
    $im = imagecreatetruecolor($width, $height);
    imagefilledrectangle($im, 0, 0, $width, $height, imagecolorallocate($im, 40, 120, 200));
    $p = tempnam(sys_get_temp_dir(), 'fxti') . '.jpg';
    imagejpeg($im, $p, 85);
    imagedestroy($im);
    return $p;
}

/** Records whether analyze() was invoked, without touching a real provider. */
class StubAiTagger extends \FluxFiles\AiTagger
{
    public bool $called = false;
    public function __construct() { /* skip provider validation */ }
    public function analyze(string $imageData, string $mimeType): array { $this->called = true; return []; }
}

echo "\n{$cyan}══ FluxFiles per-tenant config (ai_auto_tag / rate / variants) ══{$reset}\n\n";

// ── Claims parsing ────────────────────────────────────────────────────────
test('fromJwtPayload parses ai_auto_tag / rate_read / rate_write / variants', function () {
    $c = Claims::fromJwtPayload((object) [
        'sub' => 'u1', 'ai_auto_tag' => true, 'rate_read' => 120, 'rate_write' => 30,
        'variants' => (object) ['thumb' => 64, 'medium' => 1024],
    ]);
    assertEqual(true, $c->aiAutoTag, 'ai_auto_tag');
    assertEqual(120, $c->rateRead, 'rate_read');
    assertEqual(30, $c->rateWrite, 'rate_write');
    assertEqual(['thumb' => 64, 'medium' => 1024], $c->variants, 'variants');
});

test('unset claims inherit defaults (null / 0 / null)', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u1']);
    assertTrue($c->aiAutoTag === null, 'ai_auto_tag null');
    assertEqual(0, $c->rateRead, 'rate_read 0');
    assertEqual(0, $c->rateWrite, 'rate_write 0');
    assertTrue($c->variants === null, 'variants null');
});

test('sanitizeVariants rejects junk, unknown keys and out-of-range widths', function () {
    assertTrue(Claims::sanitizeVariants(null) === null, 'null');
    assertTrue(Claims::sanitizeVariants('nope') === null, 'string');
    assertTrue(Claims::sanitizeVariants(['bogus' => 100]) === null, 'unknown key only');
    assertTrue(Claims::sanitizeVariants(['thumb' => 0, 'medium' => 99999]) === null, 'all out of range');
    assertEqual(['thumb' => 50], Claims::sanitizeVariants(['thumb' => 50, 'large' => 1]), 'keeps valid, drops 1px large');
});

// ── Token round-trip (embed.php helper) ───────────────────────────────────
test('fluxfiles_token embeds the new claims (round-trip)', function () {
    $_ENV['FLUXFILES_SECRET'] = str_repeat('k', 40);
    $jwt = fluxfiles_token('u1', ['read', 'write'], ['local'], '', 10, null, 3600, false, 0, 0,
        true, 100, 25, ['thumb' => 80, 'bad' => 9]);
    $p = JwtCompat::decode($jwt, $_ENV['FLUXFILES_SECRET']);
    assertEqual(true, $p->ai_auto_tag, 'ai_auto_tag');
    assertEqual(100, $p->rate_read, 'rate_read');
    assertEqual(25, $p->rate_write, 'rate_write');
    assertEqual(['thumb' => 80], (array) $p->variants, 'variants sanitized in token');
});

test('fluxfiles_token omits per-tenant keys when unset (lean token)', function () {
    $_ENV['FLUXFILES_SECRET'] = str_repeat('k', 40);
    $p = JwtCompat::decode(fluxfiles_token('u1'), $_ENV['FLUXFILES_SECRET']);
    assertTrue(!isset($p->ai_auto_tag) && !isset($p->rate_read) && !isset($p->variants), 'no extra keys');
    assertTrue(!isset($p->allow_url_import) && !isset($p->max_import_mb), 'no import keys when unset');
});

test('fluxfiles_token forwards URL-import claims so the feature can be enabled', function () {
    $_ENV['FLUXFILES_SECRET'] = str_repeat('k', 40);
    $jwt = fluxfiles_token('u1', ['read', 'write'], ['local'], '', 10, null, 3600, false, 0, 0,
        null, 0, 0, null, [
            'allow_url_import'     => true,
            'max_import_mb'        => 20,
            'import_url_allowlist' => ['*.unsplash.com'],
            'import_path'          => 'imports',
            'import_rate_limit'    => 5,
            'import_concurrency'   => 2,
        ]);
    $claims = Claims::fromJwtPayload(JwtCompat::decode($jwt, $_ENV['FLUXFILES_SECRET']), $_ENV['FLUXFILES_SECRET']);
    assertEqual(true, $claims->allowUrlImport, 'allow_url_import');
    assertEqual(20, $claims->maxImportMb, 'max_import_mb');
    assertEqual(['*.unsplash.com'], $claims->importUrlAllowlist, 'allowlist');
    assertEqual('imports', $claims->importPath, 'import_path');
    assertEqual(5, $claims->importRateLimit, 'import_rate_limit');
    assertEqual(2, $claims->importConcurrency, 'import_concurrency');
});

test('fluxfiles_byob_token forwards URL-import claims', function () {
    $_ENV['FLUXFILES_SECRET'] = str_repeat('k', 40);
    $jwt = fluxfiles_byob_token('u1', [], ['read', 'write'], '', 10, null, 1800, false, [
        'allow_url_import' => true,
        'max_import_mb'    => 15,
    ]);
    $claims = Claims::fromJwtPayload(JwtCompat::decode($jwt, $_ENV['FLUXFILES_SECRET']), $_ENV['FLUXFILES_SECRET']);
    assertEqual(true, $claims->allowUrlImport, 'byob allow_url_import');
    assertEqual(15, $claims->maxImportMb, 'byob max_import_mb');
});

// ── Variant sizes enforcement ─────────────────────────────────────────────
test('per-tenant variants drive the generated WebP widths', function () {
    $claims = new Claims('u1', ['read', 'write'], ['local'], '', 50, null, 0, false, [], 0,
        null, 0, 0, ['thumb' => 50, 'medium' => 200]);
    [$fm] = fmWith($claims);
    $img = makeJpeg(1000, 600);
    $res = $fm->upload('local', '', ['name' => 'p.jpg', 'tmp_name' => $img, 'size' => filesize($img), 'type' => 'image/jpeg', 'error' => 0]);
    assertEqual(50, $res['variants']['thumb']['width'], 'thumb width = 50');
    assertEqual(200, $res['variants']['medium']['width'], 'medium width = 200');
    assertTrue(!isset($res['variants']['large']), 'large skipped (1000 < default 1920)');
});

test('default variants still apply when the claim is unset', function () {
    $claims = new Claims('u1', ['read', 'write'], ['local'], '', 50, null, 0);
    [$fm] = fmWith($claims);
    $img = makeJpeg(1000, 600);
    $res = $fm->upload('local', '', ['name' => 'p.jpg', 'tmp_name' => $img, 'size' => filesize($img), 'type' => 'image/jpeg', 'error' => 0]);
    assertEqual(150, $res['variants']['thumb']['width'], 'default thumb 150');
});

// ── AI auto-tag gate ──────────────────────────────────────────────────────
test('ai_auto_tag=true runs the tagger; =false skips it (overriding the env)', function () {
    $_ENV['FLUXFILES_AI_AUTO_TAG'] = 'true';  // server default ON

    $on  = new Claims('u1', ['read', 'write'], ['local'], '', 50, null, 0, false, [], 0, true);
    [$fm1] = fmWith($on); $stub1 = new StubAiTagger(); $fm1->setAiTagger($stub1);
    $i1 = makeJpeg(); $fm1->upload('local', '', ['name' => 'a.jpg', 'tmp_name' => $i1, 'size' => filesize($i1), 'type' => 'image/jpeg', 'error' => 0]);
    assertTrue($stub1->called, 'tagger called when claim true');

    $off = new Claims('u1', ['read', 'write'], ['local'], '', 50, null, 0, false, [], 0, false);
    [$fm2] = fmWith($off); $stub2 = new StubAiTagger(); $fm2->setAiTagger($stub2);
    $i2 = makeJpeg(); $fm2->upload('local', '', ['name' => 'b.jpg', 'tmp_name' => $i2, 'size' => filesize($i2), 'type' => 'image/jpeg', 'error' => 0]);
    assertTrue(!$stub2->called, 'tagger skipped when claim false even though env=true');
});

test('unset ai_auto_tag inherits the env flag', function () {
    $base = fn() => new Claims('u1', ['read', 'write'], ['local'], '', 50, null, 0); // aiAutoTag null

    $_ENV['FLUXFILES_AI_AUTO_TAG'] = '';      // server default OFF
    [$fmOff] = fmWith($base()); $sOff = new StubAiTagger(); $fmOff->setAiTagger($sOff);
    $i = makeJpeg(); $fmOff->upload('local', '', ['name' => 'c.jpg', 'tmp_name' => $i, 'size' => filesize($i), 'type' => 'image/jpeg', 'error' => 0]);
    assertTrue(!$sOff->called, 'skipped when env off + claim unset');

    $_ENV['FLUXFILES_AI_AUTO_TAG'] = 'true';   // server default ON
    [$fmOn] = fmWith($base()); $sOn = new StubAiTagger(); $fmOn->setAiTagger($sOn);
    $i2 = makeJpeg(); $fmOn->upload('local', '', ['name' => 'd.jpg', 'tmp_name' => $i2, 'size' => filesize($i2), 'type' => 'image/jpeg', 'error' => 0]);
    assertTrue($sOn->called, 'runs when env on + claim unset');
});

// ── Media-preview claims (M2) ─────────────────────────────────────────────
test('fromJwtPayload parses media_preview / preview_url_ttl (and defaults)', function () {
    $set = Claims::fromJwtPayload((object) ['media_preview' => false, 'preview_url_ttl' => 7200]);
    assertEqual(false, $set->mediaPreview, 'media_preview parsed');
    assertEqual(7200, $set->previewUrlTtl, 'preview_url_ttl parsed');

    $def = Claims::fromJwtPayload((object) []);
    assertEqual(true, $def->mediaPreview, 'media_preview defaults true');
    assertEqual(0, $def->previewUrlTtl, 'preview_url_ttl defaults 0 (inherit)');
    assertEqual(0, $def->maxPreviewMb, 'max_preview_mb defaults 0 (inherit)');

    // Negative values are clamped to 0 (inherit), not passed through.
    $neg = Claims::fromJwtPayload((object) ['preview_url_ttl' => -5, 'max_preview_mb' => -1]);
    assertEqual(0, $neg->previewUrlTtl, 'negative ttl clamped to 0');
    assertEqual(0, $neg->maxPreviewMb, 'negative max_preview_mb clamped to 0');

    $mp = Claims::fromJwtPayload((object) ['max_preview_mb' => 200]);
    assertEqual(200, $mp->maxPreviewMb, 'max_preview_mb parsed');
});

test('fluxfiles_token forwards media claims via the $media param', function () {
    $_ENV['FLUXFILES_SECRET'] = str_repeat('k', 40);
    $jwt = fluxfiles_token('u1', ['read'], ['local'], '', 10, null, 3600, false, 0, 0,
        null, 0, 0, null, null, [
            'media_preview'    => false,
            'preview_url_ttl'  => 7200,
            'max_preview_mb'   => 250,
            'stream_token_ttl' => 1800,
        ]);
    $c = Claims::fromJwtPayload(JwtCompat::decode($jwt, $_ENV['FLUXFILES_SECRET']));
    assertEqual(false, $c->mediaPreview, 'media_preview forwarded');
    assertEqual(7200, $c->previewUrlTtl, 'preview_url_ttl forwarded');
    assertEqual(250, $c->maxPreviewMb, 'max_preview_mb forwarded');
    assertEqual(1800, $c->streamTokenTtl, 'stream_token_ttl forwarded');

    // Unset $media → defaults (media_preview true, others 0/inherit), no extra keys.
    $p = JwtCompat::decode(fluxfiles_token('u1'), $_ENV['FLUXFILES_SECRET']);
    assertTrue(!isset($p->media_preview) && !isset($p->preview_url_ttl), 'lean when unset');
});

test('fromJwtPayload parses webp claims (and defaults)', function () {
    $set = Claims::fromJwtPayload((object) ['webp_enabled' => false, 'webp_max_width' => 1600, 'webp_default_quality' => 75]);
    assertEqual(false, $set->webpEnabled, 'webp_enabled parsed');
    assertEqual(1600, $set->webpMaxWidth, 'webp_max_width parsed');
    assertEqual(75, $set->webpDefaultQuality, 'webp_default_quality parsed');

    $def = Claims::fromJwtPayload((object) []);
    assertEqual(true, $def->webpEnabled, 'webp_enabled defaults true');
    assertEqual(0, $def->webpMaxWidth, 'webp_max_width defaults 0 (inherit)');
    assertEqual(0, $def->webpDefaultQuality, 'webp_default_quality defaults 0 (inherit)');
});

test('fluxfiles_token forwards webp claims via the $webp param', function () {
    $_ENV['FLUXFILES_SECRET'] = str_repeat('k', 40);
    $jwt = fluxfiles_token('u1', ['read'], ['local'], '', 10, null, 3600, false, 0, 0,
        null, 0, 0, null, null, null, [
            'webp_enabled'         => false,
            'webp_max_width'       => 1600,
            'webp_default_quality' => 75,
        ]);
    $c = Claims::fromJwtPayload(JwtCompat::decode($jwt, $_ENV['FLUXFILES_SECRET']));
    assertEqual(false, $c->webpEnabled, 'webp_enabled forwarded');
    assertEqual(1600, $c->webpMaxWidth, 'webp_max_width forwarded');
    assertEqual(75, $c->webpDefaultQuality, 'webp_default_quality forwarded');
});

test('Claims::isMediaPath detects video/audio extensions only', function () {
    foreach (['a/b/clip.mp4', 'song.MP3', 'x.webm', 'y.mov', 'z.flac', 'w.ogg'] as $p) {
        assertTrue(Claims::isMediaPath($p), "media: $p");
    }
    foreach (['doc.pdf', 'img.jpg', 'data.json', 'noext', 'archive.zip'] as $p) {
        assertTrue(!Claims::isMediaPath($p), "non-media: $p");
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
