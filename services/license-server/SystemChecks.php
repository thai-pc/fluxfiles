<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use FluxFiles\LicenseServer\Client\Config;

/** Credentials are never sent; probes only the configured site's root with HEAD. */
final class SystemChecks
{
    public static function sites(): array
    {
        $rows = [];
        foreach (
            ['landing' => 'FLUXFILES_LANDING_ORIGIN', 'update' => 'FLUXFILES_PRO_UPDATE_URL']
            as $name => $env
        ) {
            $url = rtrim(Config::get($env), '/');
            $row = [
                'target' => $name,
                'configured' => $url !== '',
                'ok' => null,
                'http_status' => null,
            ];
            if ($url !== '') {
                $p = parse_url($url);
                if (
                    !is_array($p) ||
                    isset($p['user']) ||
                    isset($p['pass']) ||
                    isset($p['query']) ||
                    isset($p['fragment']) ||
                    !in_array($p['path'] ?? '', ['', '/'], true)
                ) {
                    $row['ok'] = false;
                } else {
                    if (($p['scheme'] ?? '') !== 'https') {
                        try {
                            Config::loopback($url);
                        } catch (\InvalidArgumentException $e) {
                            $row['ok'] = false;
                        }
                    }
                    if ($row['ok'] !== false) {
                        $ch = curl_init($url . '/');
                        curl_setopt_array($ch, [
                            CURLOPT_NOBODY => true,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_CONNECTTIMEOUT => 1,
                            CURLOPT_TIMEOUT => 2,
                        ]);
                        $out = curl_exec($ch);
                        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        unset($ch);
                        $row['http_status'] = $code;
                        $row['ok'] = $out !== false && $code >= 200 && $code < 400;
                    }
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
