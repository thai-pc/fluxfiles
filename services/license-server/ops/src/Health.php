<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Ops;

use FluxFiles\LicenseServer\Client\Config;

require_once __DIR__ . '/../../client/Config.php';

final class Health
{
    public function probe(array $target, int $timeout = 5): array
    {
        $name = (string) ($target['name'] ?? '');
        $url = (string) ($target['url'] ?? '');
        $p = parse_url($url);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name) || !is_array($p) || isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException('invalid health target');
        }
        if (($p['scheme'] ?? '') !== 'https') {
            Config::loopback($url);
        }
        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => max(1, min(5, $timeout)), CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_WRITEFUNCTION => static fn($c, string $data): int => strlen($data)]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        $out = ['target' => $name, 'ok' => $error === 0 && $code === (int) ($target['expect_status'] ?? 200), 'ms' => (int) round((microtime(true) - $start) * 1000), 'detail' => 'HTTP ' . $code];
        if (($p['scheme'] ?? '') === 'https') {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'peer_name' => $p['host'], 'verify_peer' => true, 'verify_peer_name' => true]]);
            $fp = @stream_socket_client('ssl://' . $p['host'] . ':' . ($p['port'] ?? 443), $errno, $errstr, max(1, min(5, $timeout)), STREAM_CLIENT_CONNECT, $ctx);
            if ($fp) {
                $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
                $info = $cert?openssl_x509_parse($cert): false;
                fclose($fp);
                if (is_array($info)) {
                    $out['tls_days'] = (int) floor(((int) $info['validTo_time_t'] - time()) / 86400);
                }
            }
        }
        return $out;
    }
}
