<?php

/**
 * AI auto-tag — AiTagger response parsing + FileManager manual `aiTag()` and
 * auto-tag-on-upload, using a stub tagger (no network). Covers TEST-PLAN
 * section 10 (AI auto-tag).
 *
 * Usage:
 *   php tests/integration/test-aitagger.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\AiTagger;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

/** Stub tagger — returns canned data, no HTTP. */
class StubAiTagger extends AiTagger
{
    public array $result = ['tags' => ['cat', 'animal'], 'title' => 'A Cat', 'alt_text' => 'a cat', 'caption' => 'a cute cat'];
    public int $calls = 0;
    public function analyze(string $imageData, string $mimeType): array { $this->calls++; return $this->result; }
}

function imgFile(int $w = 200, int $h = 150): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 60, 120, 180));
    $p = sys_get_temp_dir() . '/fxai-' . uniqid() . '.png';
    imagepng($im, $p); imagedestroy($im);
    return $p;
}
function fileArr(string $tmp, string $name): array { return ['name' => $name, 'size' => filesize($tmp), 'tmp_name' => $tmp]; }

function makeFM(?AiTagger $tagger = null): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-ai-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $fm = new FileManager($dm, new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false), $meta);
    if ($tagger) { $fm->setAiTagger($tagger); }
    return [$fm, $meta];
}

/** Call the private AiTagger::parseJsonResponse via reflection. */
function parse(string $text): array
{
    $t = new AiTagger('claude', 'dummy-key');
    $ref = new ReflectionMethod($t, 'parseJsonResponse');
    $ref->setAccessible(true);
    return $ref->invoke($t, $text);
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles AI Tagger Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

echo "{$yellow}► Response parsing{$reset}\n";

test('parses plain JSON', function () {
    $r = parse('{"tags":["a","b"],"title":"T","alt_text":"A","caption":"C"}');
    assertEqual(['a', 'b'], $r['tags'], 'tags');
    assertEqual('T', $r['title'], 'title');
});

test('strips markdown ```json fences', function () {
    $r = parse("```json\n{\"tags\":[\"x\"],\"title\":\"Y\"}\n```");
    assertEqual(['x'], $r['tags'], 'tags from fenced');
    assertEqual('Y', $r['title'], 'title from fenced');
});

test('truncates over-long fields', function () {
    $long = str_repeat('a', 400);
    $r = parse(json_encode(['tags' => [], 'title' => $long, 'alt_text' => $long, 'caption' => $long]));
    assertEqual(255, strlen($r['title']), 'title ≤255');
    assertEqual(400, strlen($r['caption']), 'caption ≤1000 (400 fits)');
});

test('filters empty tags', function () {
    $r = parse('{"tags":["a","","  ","b"],"title":"T"}');
    assertEqual(['a', 'b'], $r['tags'], 'empty/whitespace tags removed');
});

test('invalid JSON → 502', function () {
    try { parse('not json at all'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual(502, $e->getHttpCode(), 'expected 502'); }
});

test('analyze() with unsupported provider → 400', function () {
    $t = new AiTagger('grok', 'k');
    $bytes = file_get_contents(imgFile());   // real PNG so resizeForApi() stays quiet
    try { $t->analyze($bytes, 'image/png'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual(400, $e->getHttpCode(), 'expected 400'); }
});

echo "\n{$yellow}► FileManager.aiTag (manual){$reset}\n";

test('aiTag without configured tagger → 400 ai_not_configured', function () {
    [$fm] = makeFM(null);
    $fm->upload('local', '', fileArr(imgFile(), 'p.png'), true);
    try { $fm->aiTag('local', 'p.png'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual('ai_not_configured', $e->getErrorCode(), 'expected ai_not_configured'); }
});

test('aiTag on a non-image → 400 ai_images_only', function () {
    [$fm] = makeFM(new StubAiTagger('claude', 'k'));
    $tmp = sys_get_temp_dir() . '/fxai-' . uniqid() . '.txt'; file_put_contents($tmp, 'x');
    $fm->upload('local', '', fileArr($tmp, 'doc.txt'), true);
    try { $fm->aiTag('local', 'doc.txt'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual('ai_images_only', $e->getErrorCode(), 'expected ai_images_only'); }
});

test('aiTag on an image saves tags + title/alt/caption', function () {
    [$fm, $meta] = makeFM(new StubAiTagger('claude', 'k'));
    $fm->upload('local', '', fileArr(imgFile(), 'cat.png'), true);
    $r = $fm->aiTag('local', 'cat.png');
    assertEqual(['cat', 'animal'], $r['tags'], 'returns tags');
    $stored = $meta->get('local', 'cat.png');
    assertEqual('A Cat', $stored['title'] ?? '', 'title stored');
    assertEqual('cat, animal', $stored['tags'] ?? '', 'tags joined + stored');
});

test('aiTag does not overwrite an existing title', function () {
    [$fm, $meta] = makeFM(new StubAiTagger('claude', 'k'));
    $fm->upload('local', '', fileArr(imgFile(), 'c.png'), true);
    $meta->save('local', 'c.png', ['title' => 'My Own Title']);
    $fm->aiTag('local', 'c.png');
    assertEqual('My Own Title', $meta->get('local', 'c.png')['title'] ?? '', 'existing title preserved');
});

echo "\n{$yellow}► Auto-tag on upload{$reset}\n";

test('FLUXFILES_AI_AUTO_TAG=true auto-tags an uploaded image', function () {
    $prev = $_ENV['FLUXFILES_AI_AUTO_TAG'] ?? null;
    $_ENV['FLUXFILES_AI_AUTO_TAG'] = 'true';
    try {
        [$fm, $meta] = makeFM(new StubAiTagger('claude', 'k'));
        $r = $fm->upload('local', '', fileArr(imgFile(), 'auto.png'), true);
        assertTrue(isset($r['ai_tags']), 'upload result carries ai_tags');
        assertEqual('A Cat', $meta->get('local', 'auto.png')['title'] ?? '', 'auto title stored');
    } finally {
        if ($prev === null) { unset($_ENV['FLUXFILES_AI_AUTO_TAG']); } else { $_ENV['FLUXFILES_AI_AUTO_TAG'] = $prev; }
    }
});

test('auto-tag disabled by default (no env) → no ai_tags', function () {
    unset($_ENV['FLUXFILES_AI_AUTO_TAG']);
    [$fm] = makeFM(new StubAiTagger('claude', 'k'));
    $r = $fm->upload('local', '', fileArr(imgFile(), 'noauto.png'), true);
    assertTrue(!isset($r['ai_tags']), 'no auto-tag when env unset');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
