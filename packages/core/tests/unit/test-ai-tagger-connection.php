<?php

/**
 * AiTagger::testConnection() / checkModel() / describe() and the coded errors of
 * analyze(), over a fake transport that records requests (no network). Also covers
 * SsrfCheckedTransport.
 *
 * Usage: php tests/unit/test-ai-tagger-connection.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\AiException;
use FluxFiles\AiTagger;
use FluxFiles\ApiException;
use FluxFiles\HttpTransportInterface;
use FluxFiles\Log;
use FluxFiles\SsrfCheckedTransport;
use FluxFiles\SsrfGuard;

// Provider failures are logged on purpose; keep the test output readable.
Log::setSink(static function (string $m): void {});

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** Replays queued answers (or one answer for everything) and records every request. */
final class FakeTransport implements HttpTransportInterface
{
    /** @var array<int,array{method:string,url:string,headers:array,body:?string,timeout:int}> */
    public array $requests = [];
    /** @var array<int,array> */
    private array $queue;
    private array $default;

    public function __construct(array $default = [], array $queue = [])
    {
        $this->default = $default;
        $this->queue = $queue;
    }

    public static function json(int $status, array $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => (string) json_encode($body)];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');
        return array_shift($this->queue) ?? $this->default;
    }

    public function last(): array { return $this->requests[count($this->requests) - 1]; }
}

function png(): string
{
    $im = imagecreatetruecolor(32, 32);
    ob_start(); imagepng($im); return (string) ob_get_clean();
}

$OKJSON = '{"tags":["a"],"title":"T","alt_text":"A","caption":"C"}';
/** A 200 answer in the right envelope for each wire protocol. */
$okEnvelope = static fn (string $api, string $text): array => FakeTransport::json(200, match ($api) {
    'anthropic' => ['content' => [['type' => 'text', 'text' => $text]]],
    'gemini'    => ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]],
    default     => ['choices' => [['message' => ['content' => $text]]]],
});

// provider => [constructor args, wire api]
$providers = [
    'claude'     => [['claude', 'sk-ant-SECRETKEY123456', null, null], 'anthropic'],
    'gemini'     => [['gemini', 'AIzaSECRETKEY123456789', null, null], 'gemini'],
    'openai'     => [['openai', 'sk-SECRETKEY123456', null, null], 'openai'],
    'openrouter' => [['openrouter', 'sk-or-SECRETKEY123456', null, null], 'openai'],
    'groq'       => [['groq', 'gsk_SECRETKEY123456', null, null], 'openai'],
    'ollama'     => [['ollama', '', null, null], 'openai'],
    'compatible' => [['compatible', 'SECRETKEYvalue', 'my-model', 'https://gw.example/v1'], 'openai'],
];
function tagger(array $args, HttpTransportInterface $t): AiTagger
{
    return new AiTagger($args[0], $args[1], $args[2], $args[3], $t);
}

echo "\n{$cyan}══ AiTagger connection test ══{$reset}\n";

echo "{$yellow}► analyze(): every provider × every error class{$reset}\n";

// [name, response, expected code]
$errorClasses = [
    ['auth',        ['status' => 401, 'headers' => [], 'body' => '{"error":{"message":"bad key","type":"authentication_error"}}'], 'ai_auth'],
    ['model gone',  ['status' => 404, 'headers' => [], 'body' => '{"error":{"message":"model: x not found","type":"not_found_error","status":"NOT_FOUND"}}'], 'ai_model_not_found'],
    ['no vision',   ['status' => 400, 'headers' => [], 'body' => '{"error":{"message":"image input is not supported by this model"}}'], 'ai_no_vision'],
    ['quota',       ['status' => 402, 'headers' => [], 'body' => '{"error":{"message":"out of credits"}}'], 'ai_quota'],
    ['rate limit',  ['status' => 429, 'headers' => ['retry-after' => '7'], 'body' => '{"error":{"message":"slow down"}}'], 'ai_rate_limited'],
    ['unavailable', ['status' => 529, 'headers' => [], 'body' => '{"error":{"message":"overloaded"}}'], 'ai_unavailable'],
    ['network',     ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Could not resolve host'], 'ai_network'],
    ['blocked',     ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'blocked_host: This URL host is not allowed'], 'ai_blocked_host'],
    ['not JSON 2xx',['status' => 200, 'headers' => [], 'body' => '<html>hi</html>'], 'ai_bad_response'],
    ['no envelope', ['status' => 200, 'headers' => [], 'body' => '{"unexpected":true}'], 'ai_bad_response'],
    ['other',       ['status' => 400, 'headers' => [], 'body' => '{"error":{"message":"max_tokens too big"}}'], 'ai_provider_error'],
];
foreach ($providers as $name => [$args, $api]) {
    foreach ($errorClasses as [$label, $response, $code]) {
        test("{$name}: {$label} → {$code} (502, never 401/403/429)", function () use ($args, $response, $code, $name) {
            $t = tagger($args, new FakeTransport($response));
            try {
                $t->analyze(png(), 'image/png');
                throw new \RuntimeException('should throw');
            } catch (AiException $e) {
                assertEqual($code, $e->getErrorCode());
                assertEqual(502, $e->getHttpCode());
                $p = $e->getErrorParams();
                assertEqual($name, $p['provider']);
                assertTrue(array_key_exists('model', $p) && array_key_exists('default_model', $p), 'model + default_model params');
                if ($code === 'ai_rate_limited') {
                    assertEqual(7, $p['retry_after']);
                }
                assertTrue(strpos($e->getDetail(), (string) ($args[1] !== '' ? $args[1] : 'no-key-set')) === false, 'detail has no key');
            }
        });
    }
}

test('model text that is not JSON → ai_bad_response', function () use ($providers, $okEnvelope) {
    $t = tagger($providers['claude'][0], new FakeTransport($okEnvelope('anthropic', 'sorry, I cannot')));
    try { $t->analyze(png(), 'image/png'); throw new \RuntimeException('should throw'); }
    catch (AiException $e) { assertEqual('ai_bad_response', $e->getErrorCode()); assertEqual(502, $e->getHttpCode()); }
});

test('unknown provider and `compatible` without base URL/model → ai_not_configured (400)', function () {
    $prev = $_ENV['FLUXFILES_AI_BASE_URL'] ?? null;
    unset($_ENV['FLUXFILES_AI_BASE_URL']);
    try {
        foreach ([
            [['nope', 'k', null, null], null],
            [['compatible', 'k', 'm', null], 'base_url'],
            [['compatible', 'k', null, 'https://gw.example/v1'], 'model'],
        ] as [$args, $missing]) {
            $fake = new FakeTransport();
            try { tagger($args, $fake)->analyze(png(), 'image/png'); throw new \RuntimeException('should throw'); }
            catch (AiException $e) {
                assertEqual('ai_not_configured', $e->getErrorCode());
                assertEqual(400, $e->getHttpCode());
                assertEqual($missing, $e->getErrorParams()['missing'] ?? null);
            }
            assertEqual(0, count($fake->requests), 'no request is sent');
        }
    } finally {
        if ($prev !== null) { $_ENV['FLUXFILES_AI_BASE_URL'] = $prev; }
    }
});

test('Log output is redacted (the key never reaches the log)', function () use ($providers) {
    $lines = [];
    Log::setSink(static function (string $m) use (&$lines): void { $lines[] = $m; });
    try {
        $key = 'sk-ant-SECRETKEY123456';
        $t = tagger($providers['claude'][0], new FakeTransport(['status' => 401, 'headers' => [], 'body' => json_encode(['error' => ['message' => "invalid key {$key} (Bearer {$key})"]])]));
        try { $t->analyze(png(), 'image/png'); } catch (AiException $e) {}
        assertTrue($lines !== [], 'something was logged');
        foreach ($lines as $l) {
            assertTrue(strpos($l, 'SECRETKEY') === false, "key leaked into log: {$l}");
        }
    } finally {
        Log::setSink(static function (string $m): void {});
    }
});

echo "\n{$yellow}► testConnection(){$reset}\n";

foreach ($providers as $name => [$args, $api]) {
    test("{$name}: testConnection hits the analyze URL/headers with max 16 tokens and the probe PNG", function () use ($args, $api, $okEnvelope, $OKJSON) {
        $fake = new FakeTransport($okEnvelope($api, $OKJSON));
        $t = tagger($args, $fake);
        $t->analyze(png(), 'image/png');
        $analyze = $fake->last();
        $r = $t->testConnection();
        $probe = $fake->last();
        assertTrue($r['ok'] === true && $r['code'] === null && $r['check'] === 'vision', json_encode($r));
        assertEqual($analyze['url'], $probe['url'], 'same URL');
        assertEqual($analyze['headers'], $probe['headers'], 'same headers');
        assertEqual('POST', $probe['method']);
        assertEqual(30, $probe['timeout'], 'probe timeout');
        assertEqual(60, $analyze['timeout'], 'analyze timeout unchanged');
        $body = json_decode((string) $probe['body'], true);
        $tokens = $body['max_tokens'] ?? $body['generationConfig']['maxOutputTokens'];
        assertEqual(16, $tokens);
        assertTrue(strpos((string) $probe['body'], 'iVBORw0KGgo') !== false, 'probe PNG is sent');
        $t->analyze(png(), 'image/png');
        assertEqual(60, $fake->last()['timeout'], 'analyze timeout restored');
    });
}

test('testConnection accepts an empty reply (a thinking model can spend 16 tokens)', function () use ($providers, $okEnvelope) {
    foreach ($providers as $name => [$args, $api]) {
        $r = tagger($args, new FakeTransport($okEnvelope($api, '')))->testConnection();
        assertTrue($r['ok'] === true, "{$name}: " . json_encode($r));
    }
});

test('testConnection never throws and carries code, hint, detail, params', function () use ($providers) {
    $t = tagger($providers['claude'][0], new FakeTransport(['status' => 404, 'headers' => [], 'body' => '{"type":"error","error":{"type":"not_found_error","message":"model: claude-sonnet-4-20250514"}}']));
    $r = $t->testConnection();
    assertEqual(false, $r['ok']);
    assertEqual('ai_model_not_found', $r['code']);
    assertEqual('claude-sonnet-5-5', $r['params']['default_model']);
    assertEqual('claude', $r['provider']);
    assertTrue(strpos($r['hint'], 'claude-sonnet-5-5') !== false, 'hint names the default model');
    assertTrue(strpos($r['detail'], '404') !== false, 'detail');
    assertTrue(is_int($r['latency_ms']), 'latency');
    assertTrue(array_key_exists('warning', $r) && array_key_exists('retires_at', $r) && array_key_exists('verified', $r));
});

test('testConnection reports an unconfigured provider instead of throwing', function () {
    $r = (new AiTagger('nope', 'k', null, null, new FakeTransport()))->testConnection();
    assertEqual('ai_not_configured', $r['code']);
});

test('no response ever contains the API key', function () use ($providers) {
    foreach ($providers as $name => [$args]) {
        if ($args[1] === '') { continue; }
        $t = tagger($args, new FakeTransport(['status' => 401, 'headers' => [], 'body' => json_encode(['error' => ['message' => "bad {$args[1]}"]])]));
        foreach ([$t->testConnection(), $t->checkModel(), $t->describe()] as $out) {
            assertTrue(strpos((string) json_encode($out), $args[1]) === false, "{$name} leaked the key");
        }
    }
});

test('describe(): provider, model, default_model; no network, no key, no base URL', function () {
    $fake = new FakeTransport();
    $t = new AiTagger('claude', 'k', 'my-model', 'https://proxy.example/v1', $fake);
    assertEqual(['provider' => 'claude', 'model' => 'my-model', 'default_model' => 'claude-sonnet-5-5'], $t->describe());
    assertEqual('anthropic', $t->api());
    assertEqual(0, count($fake->requests));
    assertEqual('', (new AiTagger('compatible', 'k', 'm', 'https://gw.example/v1', $fake))->describe()['default_model']);
});

echo "\n{$yellow}► checkModel(){$reset}\n";

test('anthropic: GET /models/{model} with x-api-key, ok when lifecycle is missing', function () {
    $fake = new FakeTransport(FakeTransport::json(200, ['id' => 'claude-sonnet-5-5', 'type' => 'model']));
    $r = (new AiTagger('claude', 'sk-ant-KEY', null, null, $fake))->checkModel();
    assertTrue($r['ok'] === true && $r['check'] === 'model' && $r['verified'] === true, json_encode($r));
    $q = $fake->last();
    assertEqual('GET', $q['method']);
    assertEqual('https://api.anthropic.com/v1/models/claude-sonnet-5-5', $q['url']);
    assertTrue(in_array('x-api-key: sk-ant-KEY', $q['headers'], true) && in_array('anthropic-version: 2023-06-01', $q['headers'], true));
    assertEqual(10, $q['timeout']);
    assertEqual(null, $q['body']);
});

test('anthropic: 404, lifecycle retired (200), deprecated, image_input unsupported', function () {
    $mk = static fn (array $res): array => (new AiTagger('claude', 'k', null, null, new FakeTransport($res)))->checkModel();
    $r = $mk(['status' => 404, 'headers' => [], 'body' => '{"type":"error","error":{"type":"not_found_error","message":"model: x"}}']);
    assertEqual('ai_model_not_found', $r['code']);
    $r = $mk(FakeTransport::json(200, ['id' => 'x', 'lifecycle' => 'retired']));
    assertEqual('ai_model_not_found', $r['code'], 'retired answers 200');
    assertEqual('retired', $r['params']['reason']);
    $r = $mk(FakeTransport::json(200, ['id' => 'x', 'lifecycle' => ['status' => 'retired']]));
    assertEqual('retired', $r['params']['reason'], 'object form');
    $r = $mk(FakeTransport::json(200, ['id' => 'x', 'lifecycle' => ['status' => 'deprecated', 'retires_at' => '2027-03-01']]));
    assertTrue($r['ok'] === true, 'deprecated still works');
    assertEqual('ai_model_deprecated', $r['warning']);
    assertEqual('2027-03-01', $r['retires_at']);
    $r = $mk(FakeTransport::json(200, ['id' => 'x', 'capabilities' => ['image_input' => ['supported' => false]]]));
    assertEqual('ai_no_vision', $r['code']);
    $r = $mk(['status' => 401, 'headers' => [], 'body' => '{"error":{"type":"authentication_error","message":"x"}}']);
    assertEqual('ai_auth', $r['code']);
});

test('gemini: key in x-goog-api-key, never in the URL; generateContent required', function () {
    $fake = new FakeTransport(FakeTransport::json(200, ['name' => 'models/gemini-flash-latest', 'supportedGenerationMethods' => ['generateContent', 'countTokens']]));
    $r = (new AiTagger('gemini', 'AIzaGEMKEY', null, null, $fake))->checkModel();
    assertTrue($r['ok'] === true, json_encode($r));
    $q = $fake->last();
    assertEqual('https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest', $q['url']);
    assertTrue(strpos($q['url'], 'GEMKEY') === false, 'key not in URL');
    assertTrue(in_array('x-goog-api-key: AIzaGEMKEY', $q['headers'], true));
    $r = (new AiTagger('gemini', 'k', null, null, new FakeTransport(FakeTransport::json(200, ['supportedGenerationMethods' => ['embedContent']]))))->checkModel();
    assertEqual('ai_no_vision', $r['code']);
    $r = (new AiTagger('gemini', 'k', null, null, new FakeTransport(['status' => 404, 'headers' => [], 'body' => '{"error":{"code":404,"message":"models/x is not found for API version v1beta","status":"NOT_FOUND"}}'])))->checkModel();
    assertEqual('ai_model_not_found', $r['code']);
});

test('openai shape: GET {base}/models, Authorization only with a key, id match', function () {
    $list = FakeTransport::json(200, ['data' => [['id' => 'gpt-4o'], ['id' => 'gpt-4o-mini']]]);
    $fake = new FakeTransport($list);
    $r = (new AiTagger('openai', 'sk-KEY', null, null, $fake))->checkModel();
    assertTrue($r['ok'] === true && $r['verified'] === true, json_encode($r));
    assertEqual('https://api.openai.com/v1/models', $fake->last()['url']);
    assertTrue(in_array('Authorization: Bearer sk-KEY', $fake->last()['headers'], true));
    $keyless = new FakeTransport(FakeTransport::json(200, ['data' => [['id' => 'llama3.2-vision:latest']]]));
    $r = (new AiTagger('ollama', '', null, null, $keyless))->checkModel();
    assertTrue($r['ok'] === true, 'ollama matches model:latest: ' . json_encode($r));
    foreach ($keyless->last()['headers'] as $h) {
        assertTrue(stripos($h, 'authorization:') !== 0, 'no Authorization without a key');
    }
    $r = (new AiTagger('openai', 'k', 'gpt-9', null, new FakeTransport($list)))->checkModel();
    assertEqual('ai_model_not_found', $r['code'], 'list without the id');
    $r = (new AiTagger('groq', 'k', null, null, new FakeTransport(FakeTransport::json(200, ['data' => [['id' => 'meta-llama/llama-4-scout-17b-16e-instruct', 'capabilities' => ['vision' => false]]]]))))->checkModel();
    assertEqual('ai_no_vision', $r['code'], 'capabilities.vision false');
    $r = (new AiTagger('openai', 'k', null, null, new FakeTransport(FakeTransport::json(200, ['models' => []]))))->checkModel();
    assertEqual('ai_bad_response', $r['code'], 'no data list');
});

test('openai shape: 404/405 on /models → ok but verified:false', function () {
    foreach ([404, 405] as $status) {
        $r = (new AiTagger('compatible', 'k', 'm', 'https://gw.example/v1', new FakeTransport(['status' => $status, 'headers' => [], 'body' => 'nope'])))->checkModel();
        assertTrue($r['ok'] === true && $r['verified'] === false, "{$status}: " . json_encode($r));
    }
});

test('openrouter: /key is checked first, then the model endpoints; ~alias 404 is unverified', function () {
    $fake = new FakeTransport([], [
        FakeTransport::json(200, ['data' => ['label' => 'k']]),
        FakeTransport::json(200, ['data' => ['architecture' => ['input_modalities' => ['text', 'image']]]]),
    ]);
    $r = (new AiTagger('openrouter', 'sk-or-KEY', 'google/gemini-2.0-flash', null, $fake))->checkModel();
    assertTrue($r['ok'] === true, json_encode($r));
    assertEqual('https://openrouter.ai/api/v1/key', $fake->requests[0]['url']);
    assertEqual('https://openrouter.ai/api/v1/models/google/gemini-2.0-flash/endpoints', $fake->requests[1]['url']);
    assertTrue(in_array('Authorization: Bearer sk-or-KEY', $fake->requests[0]['headers'], true));

    $r = (new AiTagger('openrouter', 'k', null, null, new FakeTransport(['status' => 401, 'headers' => [], 'body' => '{"error":{"code":401,"message":"No auth credentials found"}}'])))->checkModel();
    assertEqual('ai_auth', $r['code'], '/key 401');

    $r = (new AiTagger('openrouter', 'k', 'foo/bar', null, new FakeTransport([], [FakeTransport::json(200, []), ['status' => 404, 'headers' => [], 'body' => '{"error":{"message":"No endpoints found for foo/bar","code":404}}']])))->checkModel();
    assertEqual('ai_model_not_found', $r['code']);

    $r = (new AiTagger('openrouter', 'k', null, null, new FakeTransport([], [FakeTransport::json(200, []), ['status' => 404, 'headers' => [], 'body' => '{"error":{"message":"No endpoints found","code":404}}']])))->checkModel();
    assertTrue($r['ok'] === true && $r['verified'] === false, '~alias default that 404s: ' . json_encode($r));

    $r = (new AiTagger('openrouter', 'k', 'a/b', null, new FakeTransport([], [FakeTransport::json(200, []), FakeTransport::json(200, ['data' => ['architecture' => ['input_modalities' => ['text']]]])])))->checkModel();
    assertEqual('ai_no_vision', $r['code']);
});

test('checkModel never sends an image and never puts a key in a URL', function () use ($providers) {
    foreach ($providers as $name => [$args]) {
        $fake = new FakeTransport(FakeTransport::json(200, ['data' => [['id' => 'x']]]));
        tagger($args, $fake)->checkModel();
        foreach ($fake->requests as $q) {
            assertEqual('GET', $q['method'], $name);
            assertEqual(null, $q['body'], "{$name} body");
            assertTrue($args[1] === '' || strpos($q['url'], $args[1]) === false, "{$name} key in URL");
        }
    }
});

test('checkModel: transport failures and oversized lists are classified', function () {
    $r = (new AiTagger('openai', 'k', null, null, new FakeTransport(['status' => 0, 'headers' => [], 'body' => '', 'error' => 'timed out'])))->checkModel();
    assertEqual('ai_network', $r['code']);
    $r = (new AiTagger('openai', 'k', null, null, new FakeTransport(['status' => 0, 'headers' => [], 'body' => '', 'error' => 'response too large (limit 4194304 bytes)'])))->checkModel();
    assertEqual('ai_bad_response', $r['code']);
});

echo "\n{$yellow}► SsrfCheckedTransport{$reset}\n";

test('a private/loopback target is refused as ai_blocked_host without calling the inner transport', function () {
    $inner = new FakeTransport(FakeTransport::json(200, ['choices' => []]));
    $guarded = new SsrfCheckedTransport($inner);
    foreach (['http://127.0.0.1:11434/v1/chat/completions', 'http://10.0.0.5/v1', 'http://169.254.169.254/latest', 'http://localhost/v1', 'ftp://example.com/x'] as $url) {
        $res = $guarded->request('POST', $url, [], '{}', 5);
        assertEqual(0, $res['status'], $url);
        assertTrue(strncmp($res['error'], 'blocked_host:', 13) === 0, "{$url}: " . $res['error']);
    }
    assertEqual(0, count($inner->requests), 'inner never called');
});

test('an allowlisted host passes through', function () {
    $prev = SsrfGuard::$allowHosts;
    SsrfGuard::setAllowHosts('localhost:11434,ollama.internal');
    try {
        $inner = new FakeTransport(FakeTransport::json(200, ['ok' => true]));
        $guarded = new SsrfCheckedTransport($inner);
        $res = $guarded->request('GET', 'http://localhost:11434/v1/models', ['A: b'], null, 5);
        assertEqual(200, $res['status']);
        assertEqual('http://localhost:11434/v1/models', $inner->last()['url']);
        assertEqual(['A: b'], $inner->last()['headers']);
        $res = $guarded->request('GET', 'http://ollama.internal/v1/models');
        assertEqual(200, $res['status']);
        $res = $guarded->request('GET', 'http://localhost:9999/v1/models');
        assertEqual(0, $res['status'], 'a different port is not allowlisted');
    } finally {
        SsrfGuard::$allowHosts = $prev;
    }
});

test('through AiTagger the refusal surfaces as ai_blocked_host', function () {
    $inner = new FakeTransport(FakeTransport::json(200, []));
    $t = new AiTagger('compatible', 'k', 'm', 'http://192.168.1.10:8080/v1', new SsrfCheckedTransport($inner));
    $r = $t->testConnection();
    assertEqual('ai_blocked_host', $r['code']);
    assertEqual(0, count($inner->requests));
    $r = $t->checkModel();
    assertEqual('ai_blocked_host', $r['code']);
    try { $t->analyze(png(), 'image/png'); throw new \RuntimeException('should throw'); }
    catch (AiException $e) { assertEqual('ai_blocked_host', $e->getErrorCode()); assertEqual(502, $e->getHttpCode()); }
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
