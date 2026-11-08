<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Ops;

use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/../../client/Config.php';
require_once __DIR__ . '/../../client/Mask.php';

final class Telegram
{
    private string $token;
    private string $base;
    public string $tag;
    public string $chat;
    public string $thread;

    public function __construct()
    {
        $file = Config::get('FLUXFILES_TELEGRAM_BOT_TOKEN_FILE');
        $this->token = $file !== ''?trim((string) @file_get_contents($file)): '';
        $base = Config::get('FLUXFILES_TELEGRAM_API_BASE', 'https://api.telegram.org');
        $this->base = $base === 'https://api.telegram.org'?$base: Config::loopback($base);
        $this->chat = Config::get('FLUXFILES_TELEGRAM_CHAT_ID');
        $this->thread = Config::get('FLUXFILES_TELEGRAM_THREAD_ID');
        $tag = mb_substr(Config::get('FLUXFILES_TELEGRAM_TAG', Config::sandbox()?'FluxFiles SANDBOX': 'FluxFiles'), 0, 64);
        if (Config::sandbox() && !str_contains(strtoupper($tag), 'SANDBOX')) {
            $tag.=' SANDBOX';
        }
        $this->tag = mb_substr(Mask::safe($tag, true), 0, 80);
    }

    public function enabled(): bool
    {
        return $this->token !== '' && $this->chat !== '' && (!Config::sandbox() || Config::get('FLUXFILES_TELEGRAM_SANDBOX') === '1');
    }
    public function fingerprint(): ?string
    {
        return $this->token !== ''?substr(hash('sha256', $this->token), 0, 10): null;
    }

    public function request(string $method, array $payload, int $timeout = 10): array
    {
        // This class lives only in the CLI worker; neither server nor UI imports it.
        $ch = curl_init($this->base . '/bot' . $this->token . '/' . $method);
        curl_setopt_array($ch, [CURLOPT_PROXY => '', CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => max(1, min(25, $timeout))]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        $body = is_string($raw)?json_decode($raw, true): null;
        if ($code === 200 && is_array($body) && ($body['ok'] ?? false) === true) {
            return ['ok' => true, 'result' => $body['result'] ?? []];
        }
        // Never retain a provider description or curl error: either can echo the token URL.
        return ['ok' => false, 'error' => 'telegram_http_' . $code, 'retry_after' => $code === 429?max(1, min(86400, (int) ($body['parameters']['retry_after'] ?? 60))): null];
    }

    public function send(string $text, int $timeout = 10): array
    {
        Mask::safe($text, true);
        $payload = ['chat_id' => $this->chat, 'text' => '[' . $this->tag . '] ' . mb_substr($text, 0, 1200), 'link_preview_options' => ['is_disabled' => true]];
        if ($this->thread !== '') {
            $payload['message_thread_id'] = (int) $this->thread;
        }
        return $this->request('sendMessage', $payload, $timeout);
    }

    public function command(array $message, string $username): ?string
    {
        if ((string) ($message['chat']['id'] ?? '') !== $this->chat || ($this->thread !== '' && (string) ($message['message_thread_id'] ?? '') !== $this->thread)) {
            return null;
        }
        $private = ($message['chat']['type'] ?? '') === 'private';
        if (!$private && (($message['chat']['type'] ?? '') !== 'supergroup' || Config::get('FLUXFILES_TELEGRAM_ADMIN_USER_ID') === '' || (string) ($message['from']['id'] ?? '') !== Config::get('FLUXFILES_TELEGRAM_ADMIN_USER_ID'))) {
            return null;
        }
        $text = trim((string) ($message['text'] ?? ''));
        if (!preg_match('~^/(stats|today|help)(?:@([A-Za-z0-9_]+))?$~D', $text, $m)) {
            return null;
        }
        if (!$private && !isset($m[2])) {
            return null;
        }
        if (isset($m[2]) && !hash_equals(strtolower($username), strtolower($m[2]))) {
            return null;
        }
        return $m[1];
    }
}
