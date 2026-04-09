<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Internationalization — load JSON translation files, resolve locale, interpolate variables.
 */
class I18n
{
    private array  $translations = [];
    private array  $fallback     = [];
    private string $locale;
    private string $langDir;

    private const SUPPORTED = [
        'en', 'vi', 'zh', 'ja', 'ko', 'fr', 'de', 'es', 'ar', 'pt',
        'it', 'ru', 'th', 'hi', 'tr', 'nl',
    ];

    public function __construct(string $langDir, ?string $forceLocale = null)
    {
        $this->langDir = rtrim($langDir, '/');
        $this->locale  = $this->resolve($forceLocale);
        $this->load();
    }

    /**
     * Get translated string with variable interpolation.
     *
     * @param string $key  Dot-notation key, e.g. "upload.drop_hint"
     * @param array  $vars Interpolation variables, e.g. ["count" => 5]
     */
    public function t(string $key, array $vars = []): string
    {
        $str = $this->get($key) ?? $this->getFallback($key) ?? $key;
        return $this->interpolate($str, $vars);
    }

    /**
     * Plural form: n==1 uses singular key, n>1 uses plural key.
     */
    public function tp(string $singularKey, string $pluralKey, int $n, array $vars = []): string
    {
        $key = $n === 1 ? $singularKey : $pluralKey;
        return $this->t($key, array_merge($vars, ['count' => $n]));
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function direction(): string
    {
        return $this->get('_meta.direction') ?? 'ltr';
    }

    public function name(): string
    {
        return $this->get('_meta.name') ?? $this->locale;
    }

    /**
     * Export all translations as JSON for injection into Alpine.js.
     */
    public function toJson(): string
    {
        return json_encode($this->translations, JSON_UNESCAPED_UNICODE);
    }

    // -------------------------------------------------------------------------
    // Locale resolution
    // -------------------------------------------------------------------------

    private function resolve(?string $force): string
    {
        // 1. Forced locale (from FM_CONFIG / constructor)
        if ($force !== null && $force !== '' && $this->isSupported($force)) {
            return $this->normalize($force);
        }

        // 2. URL query param ?lang=
        $lang = $_GET['lang'] ?? null;
        if ($lang !== null && $this->isSupported($lang)) {
            return $this->normalize($lang);
        }

        // 3. Default — explicit locale required, no auto-detect from browser
        return 'en';
    }

    // -------------------------------------------------------------------------
    // Loading
    // -------------------------------------------------------------------------

    private function load(): void
    {
        // Always load English as fallback
        $enPath = "{$this->langDir}/en.json";
        if (file_exists($enPath)) {
            $this->fallback = json_decode(file_get_contents($enPath), true) ?? [];
        }

        if ($this->locale === 'en') {
            $this->translations = $this->fallback;
            return;
        }

        $path = "{$this->langDir}/{$this->locale}.json";
        if (file_exists($path)) {
            $this->translations = json_decode(file_get_contents($path), true) ?? [];
        } else {
            $this->translations = $this->fallback;
            $this->locale = 'en';
        }
    }

    // -------------------------------------------------------------------------
    // Key resolution
    // -------------------------------------------------------------------------

    private function get(string $key): ?string
    {
        return $this->resolveKey($this->translations, $key);
    }

    private function getFallback(string $key): ?string
    {
        return $this->resolveKey($this->fallback, $key);
    }

    private function resolveKey(array $arr, string $key): ?string
    {
        $parts = explode('.', $key);
        $current = $arr;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return is_string($current) ? $current : null;
    }

    private function interpolate(string $str, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $str = str_replace('{' . $k . '}', (string) $v, $str);
        }
        return $str;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalize(string $locale): string
    {
        return strtolower(explode('-', $locale)[0]);
    }

    private function isSupported(string $locale): bool
    {
        $code = $this->normalize($locale);
        return in_array($code, self::SUPPORTED, true)
            || file_exists("{$this->langDir}/{$code}.json");
    }
}
