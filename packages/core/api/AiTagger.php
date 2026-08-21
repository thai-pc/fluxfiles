<?php

declare(strict_types=1);

namespace FluxFiles;

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
 * unlisted OpenAI-compatible endpoint). Nothing is proxied through fluxfiles.dev.
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
        // Anthropic Messages API
        'claude'     => ['api' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],
        'anthropic'  => ['api' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'],

        // Google Gemini generateContent. The `-latest` alias, not a pinned version:
        // Google retires point releases for new keys (gemini-2.5-flash already 404s with
        // "no longer available to new users"), which would strand every install that
        // never set FLUXFILES_AI_MODEL.
        'gemini'     => ['api' => 'gemini', 'model' => 'gemini-flash-latest'],
        'google'     => ['api' => 'gemini', 'model' => 'gemini-flash-latest'],

        // OpenAI and the gateways that speak its chat-completions shape
        'openai'     => ['api' => 'openai', 'model' => 'gpt-4o',                    'base' => 'https://api.openai.com/v1'],
        'openrouter' => ['api' => 'openai', 'model' => 'google/gemini-2.5-flash',   'base' => 'https://openrouter.ai/api/v1'],
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

    public function __construct(string $provider, string $apiKey, ?string $model = null, ?string $baseUrl = null)
    {
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

    /** Provider names accepted by `analyze()`, for docs and error messages. */
    public static function supportedProviders(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * @return array{tags: string[], title: string, alt_text: string, caption: string}
     */
    public function analyze(string $imageData, string $mimeType): array
    {
        $spec = self::PROVIDERS[$this->provider] ?? null;
        if ($spec === null) {
            throw new ApiException(
                "Unsupported AI provider: {$this->provider} (supported: " . implode(', ', self::supportedProviders()) . ')',
                400
            );
        }

        $imageData = $this->resizeForApi($imageData);
        $base64 = base64_encode($imageData);
        $mediaType = in_array($mimeType, self::SUPPORTED_MEDIA, true) ? $mimeType : 'image/jpeg';

        switch ($spec['api']) {
            case 'anthropic':
                return $this->analyzeAnthropic($base64, $mediaType);
            case 'gemini':
                return $this->analyzeGemini($base64, $mediaType);
            case 'openai':
                return $this->analyzeOpenAI($base64, $mediaType);
            default:
                throw new ApiException("Unsupported AI provider: {$this->provider}", 400);
        }
    }

    private function analyzeAnthropic(string $base64, string $mediaType): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => self::MAX_TOKENS,
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
                            'text' => $this->buildPrompt(),
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->httpPost(
            ($this->baseUrl !== '' ? $this->baseUrl : 'https://api.anthropic.com/v1') . '/messages',
            [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'Content-Type: application/json',
            ],
            $body
        );

        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return $this->parseJsonResponse($text);
    }

    private function analyzeGemini(string $base64, string $mediaType): array
    {
        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['inline_data' => ['mime_type' => $mediaType, 'data' => $base64]],
                        ['text' => $this->buildPrompt()],
                    ],
                ],
            ],
            // Gemini can be told to emit JSON directly, so there is usually no fence for
            // parseJsonResponse() to strip — it still strips one if a model adds it.
            'generationConfig' => [
                'maxOutputTokens' => self::MAX_TOKENS,
                'responseMimeType' => 'application/json',
            ],
        ];

        $base = $this->baseUrl !== '' ? $this->baseUrl : 'https://generativelanguage.googleapis.com/v1beta';

        // The key goes in a header, never in the query string: URLs leak into proxy and
        // server access logs, and Google supports both.
        $response = $this->httpPost(
            $base . '/models/' . rawurlencode($this->model) . ':generateContent',
            [
                'x-goog-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            $body
        );

        $text = '';
        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $this->parseJsonResponse($text);
    }

    private function analyzeOpenAI(string $base64, string $mediaType): array
    {
        if ($this->baseUrl === '') {
            throw new ApiException(
                "AI provider '{$this->provider}' needs FLUXFILES_AI_BASE_URL to be set",
                400
            );
        }
        if ($this->model === '') {
            throw new ApiException(
                "AI provider '{$this->provider}' needs FLUXFILES_AI_MODEL to be set",
                400
            );
        }

        $body = [
            'model'      => $this->model,
            'max_tokens' => self::MAX_TOKENS,
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
                            'text' => $this->buildPrompt(),
                        ],
                    ],
                ],
            ],
        ];

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $response = $this->httpPost($this->baseUrl . '/chat/completions', $headers, $body);

        $text = $response['choices'][0]['message']['content'] ?? '';

        return $this->parseJsonResponse(is_string($text) ? $text : '');
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
            throw new ApiException('AI returned invalid JSON response', 502);
        }

        return [
            'tags'     => array_values(array_filter(
                array_map('trim', $parsed['tags'] ?? []),
                function (string $t): bool { return $t !== ''; }
            )),
            'title'    => substr(trim($parsed['title'] ?? ''), 0, 255),
            'alt_text' => substr(trim($parsed['alt_text'] ?? ''), 0, 500),
            'caption'  => substr(trim($parsed['caption'] ?? ''), 0, 1000),
        ];
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
                imagedestroy($image);
                return $imageData;
            }

            $newWidth = self::MAX_IMAGE_WIDTH;
            $newHeight = (int) round($height * ($newWidth / $width));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                return $imageData;
            }

            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            ob_start();
            imagejpeg($resized, null, 85);
            $result = ob_get_clean();

            imagedestroy($image);
            imagedestroy($resized);

            return $result ?: $imageData;
        } catch (\Throwable $e) {
            return $imageData;
        }
    }

    /** Protected, not private, so tests can substitute a transport instead of the network. */
    protected function httpPost(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("FluxFiles AI API request failed: {$error}");
            throw new ApiException('AI service temporarily unavailable', 502);
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($response, true);
            $errMsg = $decoded['error']['message'] ?? $decoded['error']['type'] ?? "HTTP {$httpCode}";
            error_log("FluxFiles AI API error ({$httpCode}): {$errMsg}");
            throw new ApiException('AI service returned an error', 502);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ApiException('AI API returned invalid response', 502);
        }

        return $decoded;
    }
}
