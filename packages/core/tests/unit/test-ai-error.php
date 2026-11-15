<?php

/**
 * AiError: the shared classifier of AI-provider failures. Table-driven over the wire
 * protocols with realistic canned bodies. No network.
 *
 * Usage: php tests/unit/test-ai-error.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\AiError;
use FluxFiles\AiException;
use FluxFiles\ApiException;

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

echo "\n{$cyan}══ AiError ══{$reset}\n";

// [label, api, status, body, headers, expected code]
$anthropicErr = static fn (string $type, string $msg): string => json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $msg]]);
$openaiErr = static fn (string $msg, string $code, string $type = 'invalid_request_error'): string => json_encode(['error' => ['message' => $msg, 'type' => $type, 'code' => $code]]);
$geminiErr = static fn (int $code, string $msg, string $status, array $details = []): string => json_encode(['error' => ['code' => $code, 'message' => $msg, 'status' => $status, 'details' => $details]]);

$cases = [
    // auth
    ['anthropic 401',          'anthropic', 401, $anthropicErr('authentication_error', 'invalid x-api-key'), [], 'ai_auth'],
    ['anthropic 403',          'anthropic', 403, $anthropicErr('permission_error', 'not allowed'), [], 'ai_auth'],
    ['openai 401',             'openai', 401, $openaiErr('Incorrect API key provided: sk-proj-abc***xyz', 'invalid_api_key'), [], 'ai_auth'],
    ['openrouter /key 401',    'openai', 401, json_encode(['error' => ['code' => 401, 'message' => 'No auth credentials found']]), [], 'ai_auth'],
    ['gemini 400 API_KEY_INVALID', 'gemini', 400, $geminiErr(400, 'API key not valid. Please pass a valid API key.', 'INVALID_ARGUMENT', [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']]), [], 'ai_auth'],
    ['gemini 403',             'gemini', 403, $geminiErr(403, 'Permission denied', 'PERMISSION_DENIED'), [], 'ai_auth'],
    // model not found
    ['anthropic 404 model',    'anthropic', 404, $anthropicErr('not_found_error', 'model: claude-sonnet-4-20250514'), [], 'ai_model_not_found'],
    ['gemini 404 model',       'gemini', 404, $geminiErr(404, 'models/gemini-x is not found for API version v1beta, or is not supported for generateContent.', 'NOT_FOUND'), [], 'ai_model_not_found'],
    ['openai 404 model',       'openai', 404, $openaiErr('The model `gpt-9` does not exist or you do not have access to it.', 'model_not_found'), [], 'ai_model_not_found'],
    ['groq 400 decommissioned','openai', 400, $openaiErr('The model `llama-3.2-90b-vision-preview` has been decommissioned and is no longer supported.', 'model_decommissioned'), [], 'ai_model_not_found'],
    ['ollama 404 pull',        'openai', 404, json_encode(['error' => ['message' => 'model "llava" not found, try pulling it first', 'type' => 'api_error']]), [], 'ai_model_not_found'],
    ['openrouter 400 invalid model', 'openai', 400, json_encode(['error' => ['message' => 'foo/bar is not a valid model ID', 'code' => 400]]), [], 'ai_model_not_found'],
    // no vision
    ['openai 400 no image',    'openai', 400, $openaiErr('Invalid content type. image_url is only supported by certain models.', 'invalid_value'), [], 'ai_no_vision'],
    ['groq 400 no vision',     'openai', 400, $openaiErr('This model does not support image input', 'invalid_request'), [], 'ai_no_vision'],
    ['gemini 400 no multimodal','gemini', 400, $geminiErr(400, 'Multimodal image input is not supported by models/gemini-x', 'INVALID_ARGUMENT'), [], 'ai_no_vision'],
    // quota
    ['402',                    'openai', 402, json_encode(['error' => ['message' => 'Insufficient credits', 'code' => 402]]), [], 'ai_quota'],
    ['openai 429 insufficient_quota', 'openai', 429, $openaiErr('You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota', 'insufficient_quota'), [], 'ai_quota'],
    ['gemini 429 quota',       'gemini', 429, $geminiErr(429, 'You exceeded your current quota, please check your plan and billing details.', 'RESOURCE_EXHAUSTED'), [], 'ai_quota'],
    ['anthropic 400 credit',   'anthropic', 400, $anthropicErr('invalid_request_error', 'Your credit balance is too low to access the Anthropic API.'), [], 'ai_quota'],
    // rate limited
    ['openai 429 plain',       'openai', 429, $openaiErr('Rate limit reached for gpt-4o on requests per min (RPM): Limit 3, Used 3.', 'rate_limit_exceeded', 'requests'), ['retry-after' => '12'], 'ai_rate_limited'],
    ['anthropic 429',          'anthropic', 429, $anthropicErr('rate_limit_error', 'Number of request tokens has exceeded your per-minute rate limit'), [], 'ai_rate_limited'],
    // unavailable
    ['anthropic 529',          'anthropic', 529, $anthropicErr('overloaded_error', 'Overloaded'), [], 'ai_unavailable'],
    ['openai 500',             'openai', 500, $openaiErr('The server had an error', 'server_error', 'server_error'), [], 'ai_unavailable'],
    ['gemini 503',             'gemini', 503, $geminiErr(503, 'The model is overloaded.', 'UNAVAILABLE'), [], 'ai_unavailable'],
    // provider error (catch-all)
    ['404 HTML is not a missing model', 'openai', 404, '<html><body><h1>404 Not Found</h1> model page</body></html>', [], 'ai_provider_error'],
    ['404 JSON without a model','openai', 404, json_encode(['error' => 'Not Found']), [], 'ai_provider_error'],
    ['openai 400 other',       'openai', 400, $openaiErr('max_tokens is too large', 'invalid_value'), [], 'ai_provider_error'],
    ['413',                    'anthropic', 413, $anthropicErr('request_too_large', 'Request exceeds the maximum allowed number of bytes.'), [], 'ai_provider_error'],
    ['302 redirect not followed','openai', 302, '', ['location' => 'https://x/'], 'ai_provider_error'],
];

foreach ($cases as [$label, $api, $status, $body, $headers, $code]) {
    test("{$label} → {$code}", function () use ($api, $status, $body, $headers, $code) {
        $c = AiError::classify($api, $status, $body, $headers);
        assertEqual($code, $c['code']);
        assertTrue(strlen($c['detail']) <= 201, 'detail is bounded');
    });
}

test('429 carries retry_after from the header', function () {
    $c = AiError::classify('openai', 429, '{}', ['retry-after' => '12']);
    assertEqual(['retry_after' => 12], $c['params']);
    assertEqual([], AiError::classify('openai', 429, '{}', [])['params'], 'no header, no param');
});

test('transport failures: network, SSRF refusal, oversized answer', function () {
    assertEqual('ai_network', AiError::classify('openai', 0, '', [], 'Could not resolve host: x')['code']);
    assertEqual('ai_network', AiError::classify('openai', 0, '', [], 'dns: Could not resolve the URL host')['code']);
    assertEqual('ai_blocked_host', AiError::classify('openai', 0, '', [], 'blocked_host: URL resolves to a private or reserved address')['code']);
    assertEqual('ai_bad_response', AiError::classify('gemini', 0, '', [], 'response too large (limit 4194304 bytes)')['code']);
});

test('detail never contains the API key (literal or key-shaped)', function () {
    $key = 'my-very-secret-key-123';
    $c = AiError::classify('openai', 401, json_encode(['error' => ['message' => "bad key {$key} sk-proj-abcdef123456 Bearer abc.def"]]), [], '', $key);
    assertTrue(strpos($c['detail'], $key) === false, 'literal key');
    assertTrue(strpos($c['detail'], 'abcdef123456') === false, 'sk- key');
    assertTrue(stripos($c['detail'], 'abc.def') === false, 'bearer token');
});

echo "\n{$yellow}► redact(){$reset}\n";

test('redact: literal key, each key pattern, userinfo', function () {
    $r = AiError::redact('k=SECRETVALUE1 and SECRETVALUE1', 'SECRETVALUE1');
    assertTrue(strpos($r, 'SECRETVALUE1') === false, 'literal key');
    foreach ([
        'sk-ant-api03-AbCdEf_123456', 'sk-or-v1-abcdef123456', 'AIzaSyA1b2C3d4E5f6G7h8',
        'gsk_abcdefghij123456', 'xai-abcdefghij123456', 'Bearer abcdef.123', 'Authorization: bearer zzz999',
        'https://u:pw@host.example/x', 'GET /v1?key=abc123&x=1', 'api_key=abc123', 'token=abc123',
    ] as $secret) {
        $out = AiError::redact("failed: {$secret} end");
        foreach (['abcdef', 'AbCdEf', 'zzz999', 'pw@', 'abc123', 'A1b2C3d4'] as $frag) {
            if (strpos($secret, $frag) !== false) {
                assertTrue(strpos($out, $frag) === false, "'{$secret}' left '{$frag}' in '{$out}'");
            }
        }
    }
});

test('redact: control chars removed, whitespace collapsed, cut at 200 chars', function () {
    assertEqual('a b c', AiError::redact("a\x00\x07 b\n\n\t c"));
    $long = AiError::redact(str_repeat('word ', 100));
    assertEqual(201, mb_strlen($long), '200 + ellipsis');
    assertTrue(mb_substr($long, -1) === '…', 'ellipsis');
    assertEqual('short', AiError::redact('short'));
});

test('redact: a key that straddles the 200-char cut is removed before cutting', function () {
    $key = 'ZZZZYYYYXXXXWWWW';
    $out = AiError::redact(str_repeat('a', 195) . $key, $key);
    assertTrue(strpos($out, 'ZZZZ') === false, 'no key fragment');
});

echo "\n{$yellow}► messages, hints, AiException{$reset}\n";

test('every error code has a message and a hint', function () {
    foreach (AiError::CODES as $code) {
        assertTrue(AiError::message($code) !== '', "message {$code}");
        assertTrue(AiError::hint($code, ['default_model' => 'm']) !== '', "hint {$code}");
    }
});

test('model_not_found hint names the default model; compatible has none', function () {
    assertTrue(strpos(AiError::hint('ai_model_not_found', ['default_model' => 'claude-sonnet-5-5']), '(claude-sonnet-5-5)') !== false);
    assertTrue(strpos(AiError::hint('ai_model_not_found', ['default_model' => '']), '()') === false);
    assertTrue(strpos(AiError::hint('ai_model_deprecated', ['retires_at' => '2027-01-01']), '2027-01-01') !== false);
});

test('provider failures are 502, never 401/403/429; only not_configured is 400', function () {
    foreach (AiError::CODES as $code) {
        $e = new AiException($code, ['provider' => 'x']);
        assertTrue($e instanceof ApiException);
        assertEqual($code === 'ai_not_configured' ? 400 : 502, $e->getHttpCode(), $code);
        assertEqual($code, $e->getErrorCode());
    }
});

test('AiException keeps the detail out of the envelope params', function () {
    $e = new AiException('ai_auth', ['provider' => 'claude'], 'HTTP 401 secret-ish provider text');
    assertEqual(['provider' => 'claude'], $e->getErrorParams());
    assertEqual('HTTP 401 secret-ish provider text', $e->getDetail());
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
