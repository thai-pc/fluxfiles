<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

/**
 * The AI tagger built from `config/fluxfiles.php` (`ai_*`), shared by the proxy
 * controller (`/ai-tag`, `/ai/test`, the doctor `ai` block) and
 * `php artisan fluxfiles:doctor --ai`, so all of them test what tagging really uses.
 *
 * A `FluxFiles\HttpTransportInterface` bound in the container under the dedicated key
 * `fluxfiles.ai_transport` replaces the default cURL transport for the AI call only
 * (tests; an app that must route it through its own HTTP client). The generic interface
 * is deliberately not looked up: core also uses it for mail, OIDC and SSRF transports.
 * No SSRF guard: like core, the base URL is operator config, not user input.
 */
final class AiTaggerFactory
{
    /** Container key of the optional AI-only transport. */
    public const TRANSPORT_KEY = 'fluxfiles.ai_transport';

    /**
     * The base URL the tagger will call: config, then env, then the process env (the same
     * fallback AiTagger applies itself). One place, so the doctor scrubs what is really used.
     */
    public static function baseUrl(): string
    {
        return trim((string) (config('fluxfiles.ai_base_url', env('FLUXFILES_AI_BASE_URL'))
            ?: ($_ENV['FLUXFILES_AI_BASE_URL'] ?? getenv('FLUXFILES_AI_BASE_URL') ?: '')));
    }

    /** The configured tagger, or null when no provider is set. */
    public static function make(): ?\FluxFiles\AiTagger
    {
        $provider = config('fluxfiles.ai_provider', env('FLUXFILES_AI_PROVIDER', ''));
        if (empty($provider)) {
            return null;
        }
        $transport = null;
        if (function_exists('app') && app()->bound(self::TRANSPORT_KEY)) {
            $transport = app()->make(self::TRANSPORT_KEY);
        }
        return new \FluxFiles\AiTagger(
            $provider,
            config('fluxfiles.ai_api_key', env('FLUXFILES_AI_API_KEY', '')),
            config('fluxfiles.ai_model', env('FLUXFILES_AI_MODEL')) ?: null,
            self::baseUrl() ?: null,
            $transport
        );
    }
}
