<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * The one place that turns "the AI provider said no" into a stable error code.
 *
 * Real tagging and the connection test share it, so a passing test means tagging will
 * work and a tagging failure carries the same code the test would report. Providers
 * return wildly different bodies for the same problem (Anthropic's `not_found_error`,
 * Gemini's `NOT_FOUND`, OpenAI's `model_not_found`, Groq's `model_decommissioned`, ...);
 * classify() reads all of them.
 *
 * Load-bearing: every upstream failure is HTTP 502, never 401/403/429. The UI treats a
 * 401 as "our own token expired" and starts a token refresh, and 429 is our own limiter's
 * `rate_limited`; the code is what tells the provider cases apart. Only `ai_not_configured`
 * (our own misconfiguration) stays 400.
 */
final class AiError
{
    public const NOT_CONFIGURED = 'ai_not_configured';
    public const AUTH           = 'ai_auth';
    public const MODEL_NOT_FOUND = 'ai_model_not_found';
    public const NO_VISION      = 'ai_no_vision';
    public const QUOTA          = 'ai_quota';
    public const RATE_LIMITED   = 'ai_rate_limited';
    public const UNAVAILABLE    = 'ai_unavailable';
    public const NETWORK        = 'ai_network';
    public const BLOCKED_HOST   = 'ai_blocked_host';
    public const BAD_RESPONSE   = 'ai_bad_response';
    public const PROVIDER_ERROR = 'ai_provider_error';
    /** A warning, not an error: the model still works but is scheduled to retire. */
    public const MODEL_DEPRECATED = 'ai_model_deprecated';

    /** Every code that can be thrown as an error (the ones with an `error.*` i18n key). */
    public const CODES = [
        self::NOT_CONFIGURED, self::AUTH, self::MODEL_NOT_FOUND, self::NO_VISION, self::QUOTA,
        self::RATE_LIMITED, self::UNAVAILABLE, self::NETWORK, self::BLOCKED_HOST,
        self::BAD_RESPONSE, self::PROVIDER_ERROR,
    ];

    private const MESSAGES = [
        self::NOT_CONFIGURED   => 'AI tagging is not configured',
        self::AUTH             => 'The AI provider rejected the API key',
        self::MODEL_NOT_FOUND  => 'The AI model was not found or has been retired',
        self::NO_VISION        => 'This AI model does not accept images',
        self::QUOTA            => 'The AI provider account is out of credit or quota',
        self::RATE_LIMITED     => 'The AI provider rate limit was reached',
        self::UNAVAILABLE      => 'The AI provider is temporarily unavailable',
        self::NETWORK          => 'Could not reach the AI provider',
        self::BLOCKED_HOST     => 'The AI endpoint host is not allowed',
        self::BAD_RESPONSE     => 'The AI endpoint returned an unexpected response',
        self::PROVIDER_ERROR   => 'The AI provider returned an error',
        self::MODEL_DEPRECATED => 'The AI model is deprecated',
    ];

    private const HINTS = [
        self::NOT_CONFIGURED   => 'Set FLUXFILES_AI_PROVIDER (and BASE_URL/MODEL for `compatible`).',
        self::AUTH             => 'Check the API key (and that it may use this model/region).',
        self::MODEL_NOT_FOUND  => 'Clear the model setting to use the provider default ({default_model}), or enter a current vision model.',
        self::NO_VISION        => 'This model does not accept images; choose a vision model.',
        self::QUOTA            => 'Add credits or raise the spending limit at the provider.',
        self::RATE_LIMITED     => 'Provider rate limit; retry later or lower upload volume.',
        self::UNAVAILABLE      => 'Provider outage/overload; retry later.',
        self::NETWORK          => 'Check the base URL, DNS, outbound firewall/proxy.',
        self::BLOCKED_HOST     => 'Private/local host: add it to FLUXFILES_SSRF_ALLOW_HOSTS.',
        self::BAD_RESPONSE     => 'The endpoint is not speaking the expected API; check provider/base URL.',
        self::PROVIDER_ERROR   => 'See detail; check base URL and model.',
        self::MODEL_DEPRECATED => 'Still works; switch before {retires_at}.',
    ];

    private const MAX_DETAIL = 200;

    /** English message for a code (clients localize by code via `error.<code>`). */
    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES[self::PROVIDER_ERROR];
    }

    /**
     * English fix-it hint for a code, with `{default_model}` / `{retires_at}` filled in.
     *
     * @param array<string,mixed> $params
     */
    public static function hint(string $code, array $params = []): string
    {
        $hint = self::HINTS[$code] ?? self::HINTS[self::PROVIDER_ERROR];
        if ($code === self::MODEL_NOT_FOUND && (string) ($params['default_model'] ?? '') === '') {
            // `compatible` has no default model to fall back to.
            return 'Enter a current vision model.';
        }
        return strtr($hint, [
            '{default_model}' => (string) ($params['default_model'] ?? ''),
            '{retires_at}'    => (string) ($params['retires_at'] ?? ''),
        ]);
    }

    /** 400 for our own misconfiguration, 502 for everything the provider did. */
    public static function httpStatus(string $code): int
    {
        return $code === self::NOT_CONFIGURED ? 400 : 502;
    }

    /**
     * Classify a failed provider call.
     *
     * @param string               $api            wire protocol: anthropic | gemini | openai
     * @param int                  $status         HTTP status, 0 when the transport failed
     * @param array<string,string> $headers        response headers, lower-cased names
     * @param string               $transportError the transport's `error` text when status is 0
     * @param string               $apiKey         literal key to scrub from the returned detail
     * @return array{code:string, params:array<string,mixed>, detail:string}
     *         `detail` is redacted and safe to log; it is never put in an API envelope for a
     *         scoped token.
     */
    public static function classify(string $api, int $status, string $body, array $headers = [], string $transportError = '', string $apiKey = ''): array
    {
        $params = [];

        if ($status === 0) {
            if (strncmp($transportError, 'blocked_host:', 13) === 0) {
                $code = self::BLOCKED_HOST;
            } elseif (stripos($transportError, 'too large') !== false) {
                $code = self::BAD_RESPONSE;
            } else {
                $code = self::NETWORK;
            }
            return ['code' => $code, 'params' => $params, 'detail' => self::redact($transportError, $apiKey)];
        }

        $decoded = json_decode($body, true);
        $isJson = is_array($decoded);
        $err = $isJson ? ($decoded['error'] ?? $decoded) : [];
        if (is_string($err)) {
            $err = ['message' => $err];
        }
        $message = is_array($err) ? (string) ($err['message'] ?? '') : '';
        $type = '';
        foreach (['type', 'code', 'status'] as $k) {
            if (is_array($err) && isset($err[$k]) && is_scalar($err[$k])) {
                $type .= ' ' . $err[$k];
            }
        }
        $reasons = '';
        foreach ((is_array($err) ? ($err['details'] ?? []) : []) as $d) {
            if (is_array($d) && isset($d['reason']) && is_scalar($d['reason'])) {
                $reasons .= ' ' . $d['reason'];
            }
        }
        $blob = strtolower(trim($message . ' ' . $type . ' ' . $reasons));

        $detail = self::redact(
            "HTTP {$status}" . (trim($type) !== '' ? ' ' . trim($type) : '') . ($message !== '' ? ': ' . $message : ''),
            $apiKey
        );
        $result = static fn (string $code, array $p = []): array => ['code' => $code, 'params' => $p, 'detail' => $detail];

        if ($status === 402) {
            return $result(self::QUOTA);
        }
        if ($status === 401 || $status === 403) {
            return $result(self::AUTH);
        }
        // Gemini answers a bad key with 400, not 401.
        if (preg_match('/api_key_invalid|api key not valid/', $blob) === 1) {
            return $result(self::AUTH);
        }
        if ($status === 429) {
            if (preg_match('/insufficient_quota|quota|billing|credit/', $blob) === 1) {
                return $result(self::QUOTA);
            }
            $retry = (string) ($headers['retry-after'] ?? '');
            return $result(self::RATE_LIMITED, ctype_digit($retry) ? ['retry_after' => (int) $retry] : []);
        }
        if ($status >= 500) {
            return $result(self::UNAVAILABLE);
        }
        // Anthropic reports an empty balance as a plain 400.
        if (preg_match('/credit balance is too low|insufficient_quota/', $blob) === 1) {
            return $result(self::QUOTA);
        }

        $namesModel = preg_match('/model/', $blob) === 1;
        $gone = preg_match('/not found|not_found|does not exist|not a valid model|decommissioned|retired|no longer available/', $blob) === 1;
        if ($status === 404) {
            // A 404 that does not name a model is a wrong base URL, not a missing model.
            if ($isJson && $namesModel && $gone) {
                return $result(self::MODEL_NOT_FOUND);
            }
            return $result(self::PROVIDER_ERROR);
        }
        if ($status === 400 || $status === 422) {
            if (strpos($blob, 'model_decommissioned') !== false || ($namesModel && $gone)) {
                return $result(self::MODEL_NOT_FOUND);
            }
            if (preg_match('/image|vision|image_url|multimodal/', $blob) === 1
                && preg_match('/not support|unsupported|only supported|invalid content type/', $blob) === 1) {
                return $result(self::NO_VISION);
            }
        }

        return $result(self::PROVIDER_ERROR);
    }

    /**
     * Strip anything key-shaped from provider text before it is logged or returned:
     * providers echo (partly masked) keys, bearer headers and `?key=` URLs in errors.
     * Output is single-line, control-char free and at most 200 chars.
     */
    public static function redact(string $s, string $apiKey = ''): string
    {
        if (strlen($apiKey) >= 4) {   // a real key is never shorter; 1-char test keys would shred the text
            $s = str_replace($apiKey, '***', $s);
        }
        $s = (string) preg_replace([
            '/sk-[A-Za-z0-9_-]{6,}/',
            '/AIza[0-9A-Za-z_-]{10,}/',
            '/gsk_\w{10,}/',
            '/xai-\w{10,}/',
            '/bearer\s+\S+/i',
            '/(api[_-]?key|key|token)=[^&\s]+/i',
            '#(https?://)[^/@\s]+@#',
        ], [
            '***',
            '***',
            '***',
            '***',
            '***',
            '$1=***',
            '$1***@',
        ], $s);
        $s = (string) preg_replace('/[[:cntrl:]]+/u', ' ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if (function_exists('mb_strlen') && mb_strlen($s) > self::MAX_DETAIL) {
            return mb_substr($s, 0, self::MAX_DETAIL) . '…';
        }
        return strlen($s) > self::MAX_DETAIL ? substr($s, 0, self::MAX_DETAIL) . '…' : $s;
    }
}
