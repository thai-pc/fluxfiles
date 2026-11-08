<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

final class I18n
{
    private array $strings;
    private array $fallback;
    public function __construct(public string $lang = 'vi')
    {
        $this->fallback = require __DIR__ . '/../lang/en.php';
        $this->strings = require __DIR__ . '/../lang/' . ($lang === 'en' ? 'en' : 'vi') . '.php';
    }
    public function t(string $key): string
    {
        return $this->strings[$key] ?? ($this->fallback[$key] ?? $key);
    }
}
