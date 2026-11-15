<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- opt-in, bring-your-own-key tagging: the operator picks the provider and supplies the key; it is not tied to one vendor, so the WordPress AI Client does not apply
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON API exceptions: the message travels in the JSON error envelope and is never rendered as HTML
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration read from the environment/server (not user input); core is a WordPress-independent library

/**
 * AI-powered image tagging using a vision model.
 *
 * Three wire protocols cover every provider we support: Anthropic's Messages API,
 * Google's generateContent, and the OpenAI chat-completions shape — which most of the
 * industry has cloned, so OpenRouter/Groq/Mistral/xAI/Ollama and any other
 * OpenAI-compatible gateway are just a different base URL, not different code.
 *
 * Bring your own key: the provider, key and model come from the server environment
 * (FLUXFILES_AI_PROVIDER / _API_KEY / _MODEL, plus _BASE_URL for a self-hosted or
 * unlisted OpenAI-compatible endpoint). Nothing is proxied through a FluxFiles-operated server.
 */
class AiTagger
{
    /** @var string */
    private $provider;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var string */
    private $baseUrl;

    /**
     * Known providers: which wire protocol they speak, their default vision model, and
     * (for the OpenAI-compatible ones) where that API lives.
     *
     * @var array<string, array{api: string, model: string, base?: string}>
     */
    private const PROVIDERS = [
        // Anthropic Messages API. An undated model id: dated snapshots retire
        // (claude-sonnet-4-20250514 was retired on 2026-06-15 and then failed every call).
        'claude'     => ['api' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
        'anthropic'  => ['api' => 'anthropic', 'model' => 'claude-sonnet-5-5'],

        // Google Gemini generateContent. The `-latest` alias, not a pinned version:
        // Google retires point releases for new keys (gemini-2.5-flash already 404s with
        // "no longer available to new users"), which would strand every install that
        // never set FLUXFILES_AI_MODEL.
        'gemini'     => ['api' => 'gemini', 'model' => 'gemini-flash-latest'],
        'google'     => ['api' => 'gemini', 'model' => 'gemini-flash-latest'],

        // OpenAI and the gateways that speak its chat-completions shape
        'openai'     => ['api' => 'openai', 'model' => 'gpt-4o',                    'base' => 'https://api.openai.com/v1'],
        'openrouter' => ['api' => 'openai', 'model' => '~google/gemini-flash-latest', 'base' => 'https://openrouter.ai/api/v1'],
        'groq'       => ['api' => 'openai', 'model' => 'meta-llama/llama-4-scout-17b-16e-instruct', 'base' => 'https://api.groq.com/openai/v1'],
        'mistral'    => ['api' => 'openai', 'model' => 'pixtral-12b-2409',          'base' => 'https://api.mistral.ai/v1'],
        'xai'        => ['api' => 'openai', 'model' => 'grok-2-vision-1212',        'base' => 'https://api.x.ai/v1'],
        'grok'       => ['api' => 'openai', 'model' => 'grok-2-vision-1212',        'base' => 'https://api.x.ai/v1'],
        'ollama'     => ['api' => 'openai', 'model' => 'llama3.2-vision',           'base' => 'http://localhost:11434/v1'],

        // Anything else that speaks OpenAI: set FLUXFILES_AI_BASE_URL + _MODEL yourself.
        'compatible' => ['api' => 'openai', 'model' => ''],
    ];

    private const MAX_IMAGE_WIDTH = 1024;

    /**
     * Decompression-bomb guard, same bound as ImageOptimizer::MAX_SOURCE_PIXELS:
     * refuse to decode a source whose pixel count is absurd (a few-KB file can
     * claim 30000×30000). 30 MP ≈ a 6720×4480 photo — well above any real upload,
     * far below a memory-exhausting bomb. Unlike ImageOptimizer's on-demand /img
     * transforms, ai_auto_tag runs unconditionally on upload — this path had no
     * such check before, so it was reachable by any authenticated uploader,
     * without a license, whenever ai_auto_tag was on.
     */
    private const MAX_SOURCE_PIXELS = 30000000;
    private const MAX_TOKENS = 2048;

    /** Media types every provider accepts; anything else is re-encoded as JPEG. */
    private const SUPPORTED_MEDIA = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** A tiny valid 16×16 PNG: the probe image of testConnection(). */
    private const PROBE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAS0lEQVQoz2OsWHCCgRTAxEAioL0GFqyiTVFGEEbdsnOEbYCrRmNj14CpAk2ECb9qTHE6BytmmGCKM+GRwyrChN88TP0sBN1A97QEAOiGGPafEEd1AAAAAElFTkSuQmCC';

    /** checkModel() reads a model list, which can be large: its own cap, and a short timeout. */
    private const CHECK_MAX_BYTES = 4 * 1024 * 1024;
    private const CHECK_TIMEOUT = 10;
    private const PROBE_TIMEOUT = 30;
    private const POST_TIMEOUT = 60;

    private HttpTransportInterface $transport;

    /** The transport the caller injected, if any (checkModel() builds its own otherwise). */
    private ?HttpTransportInterface $injectedTransport = null;

    /** Lazily built transport of checkModel() (bigger body cap than the tagging transport). */
    private ?HttpTransportInterface $checkTransport = null;

    /** Seconds httpPost() waits; testConnection() lowers it for the duration of a probe. */
    private int $postTimeout = self::POST_TIMEOUT;

    public function __construct(string $provider, string $apiKey, ?string $model = null, ?string $baseUrl = null, ?HttpTransportInterface $transport = null)
    {
        $this->injectedTransport = $transport;
        $this->transport = $transport ?? HttpTransports::curlOrFail(10, 2 * 1024 * 1024);
        $this->provider = strtolower(trim($provider));
        $this->apiKey = $apiKey;

        $spec = self::PROVIDERS[$this->provider] ?? null;

        $this->model = ($model !== null && $model !== '') ? $model : (string) ($spec['model'] ?? '');

        // The base URL falls back to the environment rather than being a required
        // argument, so the Laravel proxy and the WordPress plugin — which construct this
        // with three arguments against whatever core version they're pinned to — can
        // point at a self-hosted endpoint without an adapter release.
        if ($baseUrl === null || $baseUrl === '') {
            $baseUrl = $_ENV['FLUXFILES_AI_BASE_URL'] ?? getenv('FLUXFILES_AI_BASE_URL') ?: '';
        }
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : (string) ($spec['base'] ?? ''), '/');
    }

    /**
     * The tagger an operator configured through FLUXFILES_AI_* (index.php and the CLI share
     * this, so the two never parse the env differently). Null when no provider is set.
     *
     * @param array<string,mixed> $env usually $_ENV
     */
    public static function fromEnv(array $env, ?HttpTransportInterface $transport = null): ?self
    {
        $provider = (string) ($env['FLUXFILES_AI_PROVIDER'] ?? '');
        if ($provider === '') {
            return null;
        }
        return new self(
            $provider,
            (string) ($env['FLUXFILES_AI_API_KEY'] ?? ''),
            isset($env['FLUXFILES_AI_MODEL']) ? (string) $env['FLUXFILES_AI_MODEL'] : null,
            isset($env['FLUXFILES_AI_BASE_URL']) ? (string) $env['FLUXFILES_AI_BASE_URL'] : null,
            $transport
        );
    }

    /** Provider names accepted by `analyze()`, for docs and error messages. */
    public static function supportedProviders(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * Wire protocol of the configured provider (anthropic | gemini | openai), or null for
     * an unknown provider. For the classifier and diagnostics.
     */
    public function api(): ?string
    {
        return self::PROVIDERS[$this->provider]['api'] ?? null;
    }

    /**
     * What is configured, for diagnostics. No network call; never the key or the base URL.
     *
     * @return array{provider: string, model: string, default_model: string}
     */
    public function describe(): array
    {
        return [
            'provider'      => $this->provider,
            'model'         => $this->model,
            'default_model' => (string) (self::PROVIDERS[$this->provider]['model'] ?? ''),
        ];
    }

    /**
     * @return array{tags: string[], title: string, alt_text: string, caption: string}
     */
    public function analyze(string $imageData, string $mimeType): array
    {
        $api = $this->requireApi();

        $imageData = $this->resizeForApi($imageData);
        $base64 = base64_encode($imageData);
        $mediaType = in_array($mimeType, self::SUPPORTED_MEDIA, true) ? $mimeType : 'image/jpeg';

        $envelope = $this->send($api, $base64, $mediaType, $this->buildPrompt(), self::MAX_TOKENS);

        return $this->parseJsonResponse($this->envelopeText($api, $envelope));
    }

    /**
     * Prove that tagging would work: one tiny real vision call (a built-in 16×16 PNG,
     * "Reply with OK.", 16 output tokens) over the same URL, headers and transport as
     * analyze(). Success is a 2xx with a well-formed envelope; text is NOT required,
     * because a thinking model can spend 16 tokens and return none. Never throws.
     *
     * @return array<string,mixed> see {@see result()}
     */
    public function testConnection(): array
    {
        $t0 = microtime(true);
        $prev = $this->postTimeout;
        $this->postTimeout = self::PROBE_TIMEOUT;
        try {
            $api = $this->requireApi();
            $this->send($api, self::PROBE_PNG, 'image/png', 'Reply with OK.', 16);
            return $this->result('vision', $t0);
        } catch (AiException $e) {
            return $this->result('vision', $t0, $e);
        } catch (\Throwable $e) {
            return $this->result('vision', $t0, $this->exception(AiError::PROVIDER_ERROR, [], $e->getMessage()));
        } finally {
            $this->postTimeout = $prev;
        }
    }

    /**
     * Cheap check that never sends an image: look the configured model up at the provider
     * (existence, retirement, vision capability). Free of charge, so it is what a scheduled
     * health check can run. `verified` is false when the endpoint has no model lookup, so
     * the verdict is "no objection", not "confirmed". Never throws.
     *
     * @return array<string,mixed> see {@see result()}
     */
    public function checkModel(): array
    {
        $t0 = microtime(true);
        try {
            $api = $this->requireApi();
            switch ($api) {
                case 'anthropic':
                    $r = $this->checkAnthropicModel();
                    break;
                case 'gemini':
                    $r = $this->checkGeminiModel();
                    break;
                default:
                    $r = $this->provider === 'openrouter' ? $this->checkOpenRouterModel() : $this->checkOpenAIModel();
            }
            return $this->result('model', $t0, null, $r['verified'], $r['warning'] ?? null, $r['retires_at'] ?? null);
        } catch (AiException $e) {
            return $this->result('model', $t0, $e);
        } catch (\Throwable $e) {
            return $this->result('model', $t0, $this->exception(AiError::PROVIDER_ERROR, [], $e->getMessage()));
        }
    }

    /**
     * The result shape shared by testConnection() and checkModel(). `detail` is the
     * redacted provider text; `message`/`hint` are English, clients localize by `code`.
     *
     * @return array<string,mixed>
     */
    private function result(string $check, float $t0, ?AiException $e = null, bool $verified = true, ?string $warning = null, ?string $retiresAt = null): array
    {
        $params = $e !== null ? $e->getErrorParams() : [];
        if ($e !== null && ($e->getErrorCode() === AiError::NOT_CONFIGURED)) {
            $verified = false;
        }
        return [
            'ok'         => $e === null,
            'check'      => $check,
            'provider'   => $this->provider,
            'model'      => $this->model,
            'verified'   => $verified,
            'latency_ms' => (int) round((microtime(true) - $t0) * 1000),
            'code'       => $e !== null ? $e->getErrorCode() : null,
            'params'     => $params,
            'message'    => $e !== null ? $e->getMessage() : null,
            'hint'       => $e !== null
                ? AiError::hint((string) $e->getErrorCode(), $params)
                : null,
            'warning'    => $warning,
            'retires_at' => $retiresAt,
            'detail'     => $e !== null ? $e->getDetail() : null,
        ];
    }

    /** The wire protocol, or ai_not_configured (400) for an unknown provider. */
    private function requireApi(): string
    {
        $api = $this->api();
        if ($api === null) {
            throw $this->exception(
                AiError::NOT_CONFIGURED,
                [],
                "Unsupported AI provider: {$this->provider} (supported: " . implode(', ', self::supportedProviders()) . ')'
            );
        }
        return $api;
    }

    /** An OpenAI-shape endpoint has no built-in base URL / model for `compatible`. */
    private function requireOpenAiConfig(): void
    {
        if ($this->baseUrl === '') {
            throw $this->exception(AiError::NOT_CONFIGURED, ['missing' => 'base_url'], "AI provider '{$this->provider}' needs FLUXFILES_AI_BASE_URL to be set");
        }
        if ($this->model === '') {
            throw $this->exception(AiError::NOT_CONFIGURED, ['missing' => 'model'], "AI provider '{$this->provider}' needs FLUXFILES_AI_MODEL to be set");
        }
    }

    /**
     * Build a classified exception carrying the standard params (provider, model,
     * default_model) plus whatever the case adds. $detail is redacted here.
     *
     * @param array<string,mixed> $extra
     */
    private function exception(string $code, array $extra = [], string $detail = '', int $upstreamStatus = 0): AiException
    {
        return new AiException(
            $code,
            array_merge($this->describe(), $extra),
            AiError::redact($detail, $this->apiKey),
            $upstreamStatus
        );
    }

    /**
     * One vision request over the provider's wire protocol; returns the decoded envelope
     * after checking it has the shape the protocol promises.
     */
    private function send(string $api, string $base64, string $mediaType, string $prompt, int $maxTokens): array
    {
        switch ($api) {
            case 'anthropic':
                return $this->sendAnthropic($base64, $mediaType, $prompt, $maxTokens);
            case 'gemini':
                return $this->sendGemini($base64, $mediaType, $prompt, $maxTokens);
            default:
                return $this->sendOpenAI($base64, $mediaType, $prompt, $maxTokens);
        }
    }

    private function sendAnthropic(string $base64, string $mediaType, string $prompt, int $maxTokens): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $mediaType,
                                'data'       => $base64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->httpPost(
            $this->anthropicBase() . '/messages',
            $this->anthropicHeaders(),
            $body
        );

        return $this->requireEnvelope($response, 'content');
    }

    private function sendGemini(string $base64, string $mediaType, string $prompt, int $maxTokens): array
    {
        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['inline_data' => ['mime_type' => $mediaType, 'data' => $base64]],
                        ['text' => $prompt],
                    ],
                ],
            ],
            // Gemini can be told to emit JSON directly, so there is usually no fence for
            // parseJsonResponse() to strip — it still strips one if a model adds it.
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'responseMimeType' => 'application/json',
            ],
        ];

        // The key goes in a header, never in the query string: URLs leak into proxy and
        // server access logs, and Google supports both.
        $response = $this->httpPost(
            $this->geminiBase() . '/models/' . rawurlencode($this->model) . ':generateContent',
            $this->geminiHeaders(true),
            $body
        );

        return $this->requireEnvelope($response, 'candidates');
    }

    private function sendOpenAI(string $base64, string $mediaType, string $prompt, int $maxTokens): array
    {
        $this->requireOpenAiConfig();

        $body = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mediaType};base64,{$base64}",
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->httpPost($this->baseUrl . '/chat/completions', $this->openAiHeaders(true), $body);

        return $this->requireEnvelope($response, 'choices');
    }

    private function anthropicBase(): string
    {
        return $this->baseUrl !== '' ? $this->baseUrl : 'https://api.anthropic.com/v1';
    }

    private function geminiBase(): string
    {
        return $this->baseUrl !== '' ? $this->baseUrl : 'https://generativelanguage.googleapis.com/v1beta';
    }

    private function anthropicHeaders(): array
    {
        return [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ];
    }

    private function geminiHeaders(bool $json): array
    {
        $h = ['x-goog-api-key: ' . $this->apiKey];
        if ($json) {
            $h[] = 'Content-Type: application/json';
        }
        return $h;
    }

    private function openAiHeaders(bool $json): array
    {
        $headers = $json ? ['Content-Type: application/json'] : [];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        return $headers;
    }

    /** A 2xx answer that lacks the API's envelope key is not this API at all. */
    private function requireEnvelope(array $response, string $key): array
    {
        if (!isset($response[$key]) || !is_array($response[$key])) {
            throw $this->exception(AiError::BAD_RESPONSE, [], "response has no '{$key}'");
        }
        return $response;
    }

    /** The model's text out of a decoded envelope (empty string when it has none). */
    private function envelopeText(string $api, array $response): string
    {
        $text = '';
        if ($api === 'anthropic') {
            foreach ($response['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
            }
            return $text;
        }
        if ($api === 'gemini') {
            foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
            return $text;
        }
        $content = $response['choices'][0]['message']['content'] ?? '';

        return is_string($content) ? $content : '';
    }

    /** @return array{verified: bool, warning?: ?string, retires_at?: ?string} */
    private function checkAnthropicModel(): array
    {
        try {
            $r = $this->httpGet($this->anthropicBase() . '/models/' . rawurlencode($this->model), $this->anthropicHeaders());
        } catch (AiException $e) {
            if ($this->lookupMissingOnProxy($e)) {
                return ['verified' => false];
            }
            throw $e;
        }

        // `lifecycle` / `capabilities` may be missing (older API version): unknown is OK.
        $lc = $r['lifecycle'] ?? null;
        $state = is_array($lc) ? (string) ($lc['status'] ?? $lc['state'] ?? '') : (is_string($lc) ? $lc : '');
        $retires = is_array($lc) ? ($lc['retires_at'] ?? null) : ($r['retires_at'] ?? null);
        $retires = is_string($retires) && $retires !== '' ? $retires : null;

        if ($state === 'retired') {
            throw $this->exception(AiError::MODEL_NOT_FOUND, ['reason' => 'retired'], 'lifecycle: retired');
        }
        if (($r['capabilities']['image_input']['supported'] ?? null) === false) {
            throw $this->exception(AiError::NO_VISION, [], 'capabilities.image_input.supported is false');
        }
        if ($state === 'deprecated') {
            return ['verified' => true, 'warning' => AiError::MODEL_DEPRECATED, 'retires_at' => $retires];
        }
        return ['verified' => true];
    }

    /** A custom base URL (a proxy) may not route the model lookup: unknown, not a failure. */
    private function lookupMissingOnProxy(AiException $e): bool
    {
        return $this->baseUrl !== '' && in_array($e->getUpstreamStatus(), [404, 405], true);
    }

    /** @return array{verified: bool} */
    private function checkGeminiModel(): array
    {
        try {
            $r = $this->httpGet($this->geminiBase() . '/models/' . rawurlencode($this->model), $this->geminiHeaders(false));
        } catch (AiException $e) {
            if ($this->lookupMissingOnProxy($e)) {
                return ['verified' => false];
            }
            throw $e;
        }

        $methods = $r['supportedGenerationMethods'] ?? null;
        if (is_array($methods) && !in_array('generateContent', $methods, true)) {
            throw $this->exception(AiError::NO_VISION, [], 'model does not support generateContent');
        }
        return ['verified' => true];
    }

    /** @return array{verified: bool} */
    private function checkOpenAIModel(): array
    {
        $this->requireOpenAiConfig();

        try {
            $r = $this->httpGet($this->baseUrl . '/models', $this->openAiHeaders(false));
        } catch (AiException $e) {
            if (in_array($e->getUpstreamStatus(), [404, 405], true)) {
                // Self-hosted / unknown gateways may have no model list: nothing to object
                // to, but nothing proven either. A named provider always has /models, so
                // there a 404 means the base URL is wrong.
                if (in_array($this->provider, ['compatible', 'ollama'], true)) {
                    return ['verified' => false];
                }
                throw $this->exception(AiError::PROVIDER_ERROR, ['reason' => 'base_url'], $e->getDetail(), $e->getUpstreamStatus());
            }
            throw $e;
        }

        if (!isset($r['data']) || !is_array($r['data'])) {
            throw $this->exception(AiError::BAD_RESPONSE, [], "response has no 'data' list");
        }
        $wanted = [$this->model];
        if ($this->provider === 'ollama') {
            $wanted[] = $this->model . ':latest';
        }
        foreach ($r['data'] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (!is_string($id) || !in_array($id, $wanted, true)) {
                continue;
            }
            // Mistral flags vision per model; most gateways say nothing, which is not a no.
            if (($entry['capabilities']['vision'] ?? null) === false) {
                throw $this->exception(AiError::NO_VISION, [], 'capabilities.vision is false');
            }
            return ['verified' => true];
        }
        throw $this->exception(AiError::MODEL_NOT_FOUND, [], 'model is not in the provider model list');
    }

    /**
     * OpenRouter's /models is public (it proves nothing about the key), so the key is
     * checked on /key and the model on its per-model endpoints listing.
     *
     * @return array{verified: bool}
     */
    private function checkOpenRouterModel(): array
    {
        $this->requireOpenAiConfig();

        $this->httpGet($this->baseUrl . '/key', $this->openAiHeaders(false));

        $path = implode('/', array_map('rawurlencode', explode('/', $this->model)));
        try {
            $r = $this->httpGet($this->baseUrl . '/models/' . $path . '/endpoints', $this->openAiHeaders(false));
        } catch (AiException $e) {
            if ($e->getUpstreamStatus() !== 404) {
                throw $e;
            }
            // A `~` alias may not resolve on this route even though the model is fine.
            if (strncmp($this->model, '~', 1) === 0) {
                return ['verified' => false];
            }
            throw $this->exception(AiError::MODEL_NOT_FOUND, [], 'no endpoints for this model', 404);
        }

        $modalities = $r['data']['architecture']['input_modalities'] ?? null;
        if (is_array($modalities) && !in_array('image', $modalities, true)) {
            throw $this->exception(AiError::NO_VISION, [], 'input_modalities has no image');
        }
        return ['verified' => true];
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Analyze this image and return a JSON object with the following fields:
- "tags": an array of 5-10 descriptive single-word or short-phrase keywords (lowercase, no special characters)
- "title": a concise descriptive title (max 80 characters)
- "alt_text": an accessibility-focused description of the image (max 200 characters)
- "caption": a 1-2 sentence description of the image

Return ONLY valid JSON, no markdown formatting or code blocks.
PROMPT;
    }

    private function parseJsonResponse(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```\s*$/', '', $text);

        $parsed = json_decode(trim($text), true);

        if (!is_array($parsed)) {
            throw $this->exception(AiError::BAD_RESPONSE, [], 'the model reply is not valid JSON');
        }

        try {
            return [
                'tags'     => array_values(array_filter(
                    array_map('trim', $parsed['tags'] ?? []),
                    function (string $t): bool { return $t !== ''; }
                )),
                'title'    => substr(trim($parsed['title'] ?? ''), 0, 255),
                'alt_text' => substr(trim($parsed['alt_text'] ?? ''), 0, 500),
                'caption'  => substr(trim($parsed['caption'] ?? ''), 0, 1000),
            ];
        } catch (\TypeError $e) {
            // Valid JSON of the wrong shape ("tags": "a, b", "title": 5).
            throw $this->exception(AiError::BAD_RESPONSE, [], 'the model reply has fields of the wrong type');
        }
    }

    private function resizeForApi(string $imageData): string
    {
        try {
            $info = @getimagesizefromstring($imageData);
            if ($info === false) {
                return $imageData; // not a raster image GD can decode → send as-is
            }
            [$srcW, $srcH] = $info;
            if ($srcW <= 0 || $srcH <= 0 || ($srcW * $srcH) > self::MAX_SOURCE_PIXELS) {
                return $imageData; // decompression-bomb guard: skip resize, send original bytes untouched
            }

            $image = imagecreatefromstring($imageData);
            if ($image === false) {
                return $imageData;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= self::MAX_IMAGE_WIDTH) {
                return $imageData;
            }

            $newWidth = self::MAX_IMAGE_WIDTH;
            $newHeight = (int) round($height * ($newWidth / $width));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                return $imageData;
            }

            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            ob_start();
            imagejpeg($resized, null, 85);
            $result = ob_get_clean();


            return $result ?: $imageData;
        } catch (\Throwable $e) {
            return $imageData;
        }
    }

    /** Protected, not private, so tests can substitute a transport instead of the network. */
    protected function httpPost(string $url, array $headers, array $body): array
    {
        $res = $this->transport->request('POST', $url, $headers, (string) json_encode($body), $this->postTimeout);

        return $this->decodeResponse($res);
    }

    /** GET for checkModel(); protected for the same reason as httpPost(). */
    protected function httpGet(string $url, array $headers): array
    {
        $this->checkTransport ??= $this->injectedTransport ?? HttpTransports::curlOrFail(10, self::CHECK_MAX_BYTES);
        $res = $this->checkTransport->request('GET', $url, $headers, null, self::CHECK_TIMEOUT);

        return $this->decodeResponse($res);
    }

    /**
     * A 2xx JSON object comes back decoded; anything else is classified (AiError) and
     * thrown as a coded AiException. The provider's text is redacted before it is logged.
     *
     * @param array{status:int, headers?:array<string,string>, body?:string, error?:string} $res
     */
    private function decodeResponse(array $res): array
    {
        $status = (int) $res['status'];
        $body = (string) ($res['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            $c = AiError::classify((string) $this->api(), $status, $body, $res['headers'] ?? [], (string) ($res['error'] ?? ''), $this->apiKey);
            \FluxFiles\Log::warn("FluxFiles AI API error ({$c['code']}): {$c['detail']}");
            throw $this->exception($c['code'], $c['params'], $c['detail'], $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            \FluxFiles\Log::warn("FluxFiles AI API error (HTTP {$status}): response is not JSON");
            throw $this->exception(AiError::BAD_RESPONSE, [], "HTTP {$status} response is not JSON", $status);
        }

        return $decoded;
    }
}
