<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

/**
 * The AI tagger built from `config/fluxfiles.php` (`ai_*`), shared by the proxy
 * controller (`/ai-tag`, `/ai/test`, the doctor `ai` block) and
 * `php artisan fluxfiles:doctor --ai`, so all of them test what tagging really uses.
 *
 * A container-bound `FluxFiles\HttpTransportInterface` replaces the default cURL
 * transport (tests; an app that must route the call through its own HTTP client).
 * No SSRF guard: like core, the base URL is operator config, not user input.
 */
final class AiTaggerFactory
{
    /** The configured tagger, or null when no provider is set. */
    public static function make(): ?\FluxFiles\AiTagger
    {
        $provider = config('fluxfiles.ai_provider', env('FLUXFILES_AI_PROVIDER', ''));
        if (empty($provider)) {
            return null;
        }
        $transport = null;
        if (function_exists('app') && app()->bound(\FluxFiles\HttpTransportInterface::class)) {
            $transport = app()->make(\FluxFiles\HttpTransportInterface::class);
        }
        return new \FluxFiles\AiTagger(
            $provider,
            config('fluxfiles.ai_api_key', env('FLUXFILES_AI_API_KEY', '')),
            config('fluxfiles.ai_model', env('FLUXFILES_AI_MODEL')) ?: null,
            config('fluxfiles.ai_base_url', env('FLUXFILES_AI_BASE_URL')) ?: null,
            $transport
        );
    }
}
