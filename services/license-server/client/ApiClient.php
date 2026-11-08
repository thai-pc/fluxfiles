<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Client;

require_once __DIR__ . '/Config.php';

final class ApiClient
{
    private string $base;

    public function __construct(private string $token, private string $actor = '', ?string $base = null, private int $timeout = 30)
    {
        $this->base = $base === null ? Config::api(): Config::loopback($base);
    }

    public function request(string $method, string $path, array $data = []): array
    {
        if (!str_starts_with($path, '/') || str_contains($path, '://') || strpbrk($path, "\r\n") !== false) {
            throw new \InvalidArgumentException('invalid API path');
        }
        $ch = curl_init($this->base . $path . ($method === 'GET' && $data !== [] ? '?' . http_build_query($data): ''));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json', 'X-FluxFiles-Actor: ' . $this->actor], CURLOPT_POSTFIELDS => $method === 'GET' ? null: json_encode($data)]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status === 0) {
            throw new \RuntimeException('api_unreachable');
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new \RuntimeException('api_unreachable');
        }
        if ($status >= 400) {
            throw new \RuntimeException((string) ($body['error'] ?? 'internal'));
        }
        return $body;
    }
}
