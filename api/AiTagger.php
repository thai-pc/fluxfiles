<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * AI-powered image tagging using Claude or OpenAI vision APIs.
 */
class AiTagger
{
    /** @var string */
    private $provider;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    private const CLAUDE_DEFAULT_MODEL = 'claude-sonnet-4-20250514';
    private const OPENAI_DEFAULT_MODEL = 'gpt-4o';

    private const MAX_IMAGE_WIDTH = 1024;

    public function __construct(string $provider, string $apiKey, ?string $model = null)
    {
        $this->provider = strtolower($provider);
        $this->apiKey = $apiKey;
        $this->model = $model ?? ($this->provider === 'claude'
            ? self::CLAUDE_DEFAULT_MODEL
            : self::OPENAI_DEFAULT_MODEL);
    }

    /**
     * @return array{tags: string[], title: string, alt_text: string, caption: string}
     */
    public function analyze(string $imageData, string $mimeType): array
    {
        $imageData = $this->resizeForApi($imageData);
        $base64 = base64_encode($imageData);

        switch ($this->provider) {
            case 'claude':
                return $this->analyzeClaude($base64, $mimeType);
            case 'openai':
                return $this->analyzeOpenAI($base64, $mimeType);
            default:
                throw new ApiException("Unsupported AI provider: {$this->provider}", 400);
        }
    }

    private function analyzeClaude(string $base64, string $mimeType): array
    {
        $mediaType = in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $mimeType
            : 'image/jpeg';

        $body = [
            'model'      => $this->model,
            'max_tokens' => 1024,
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
            'https://api.anthropic.com/v1/messages',
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

    private function analyzeOpenAI(string $base64, string $mimeType): array
    {
        $mediaType = in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $mimeType
            : 'image/jpeg';

        $body = [
            'model'      => $this->model,
            'max_tokens' => 1024,
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

        $response = $this->httpPost(
            'https://api.openai.com/v1/chat/completions',
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            $body
        );

        $text = $response['choices'][0]['message']['content'] ?? '';

        return $this->parseJsonResponse($text);
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

    private function httpPost(string $url, array $headers, array $body): array
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
            throw new ApiException("AI API request failed: {$error}", 502);
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($response, true);
            $errMsg = $decoded['error']['message'] ?? $decoded['error']['type'] ?? "HTTP {$httpCode}";
            throw new ApiException("AI API error: {$errMsg}", 502);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ApiException('AI API returned invalid response', 502);
        }

        return $decoded;
    }
}
