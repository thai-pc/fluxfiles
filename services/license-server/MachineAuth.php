<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use FluxFiles\LicenseServer\Client\Config;

require_once __DIR__ . '/client/Config.php';

final class MachineAuth
{
    public static function tokens(): array
    {
        $out = [];
        foreach (['admin' => 'FLUXFILES_LICENSE_ADMIN_TOKEN', 'ui' => 'FLUXFILES_LICENSE_UI_TOKEN', 'ops' => 'FLUXFILES_LICENSE_OPS_TOKEN', 'status' => 'FLUXFILES_UPDATE_STATUS_TOKEN'] as $role => $env) {
            $out[$role] = Config::secret($env);
        }
        $present = array_filter($out, static fn(string $s): bool => $s !== '');
        if (count($present) !== count(array_unique($present))) {
            throw new \RuntimeException('machine tokens must be distinct');
        }
        return $out;
    }

    public static function role(array $server): string
    {
        $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? '');
        foreach (self::tokens() as $role => $token) {
            if ($token !== '' && hash_equals('Bearer ' . $token, $auth)) {
                return $role;
            }
        }
        return '';
    }

    public static function allowed(string $role, string $path): bool
    {
        if ($path === '/update-status') {
            return $role === 'status';
        }
        if (str_starts_with($path, '/admin/ops/')) {
            return $role === 'ops';
        }
        return in_array($role, ['admin', 'ui'], true);
    }

    public static function actor(string $role, array $server): string
    {
        $actor = (string) ($server['HTTP_X_FLUXFILES_ACTOR'] ?? '');
        return $role === 'ui' && preg_match('/^ui:[A-Za-z0-9_.-]{1,64}$/D', $actor) ? $actor: ($role === 'ui'?'ui:unknown': ($role === 'ops'?'ops': 'cli'));
    }

    public static function viewerDenied(string $role,string $method,string $path,array $server): bool
    {
        if ($role!=='ui' || ($server['HTTP_X_FLUXFILES_ROLE']??'')!=='viewer') { return false; }
        if ($method==='POST' && $path==='/admin/audit') { return false; }
        return $method!=='GET' || !str_starts_with($path,'/admin/') || str_starts_with($path,'/admin/ops/') || str_starts_with($path,'/admin/export/');
    }

    public static function poolAllows(string $path): bool
    {
        $role = Config::get('FLUXFILES_LICENSE_ROLE', 'all');
        if ($role === 'all' || $path === '/health') {
            return true;
        }
        if ($role === 'status') {
            return $path === '/update-status';
        }
        if ($role === 'admin-api') {
            return str_starts_with($path, '/admin/') || in_array($path, ['/issue', '/licenses', '/revoke', '/redeliver'], true);
        }
        if ($role === 'public') {
            return in_array($path, ['/claim', '/refresh', '/pro-download', '/webhook/polar', '/webhook/resend'], true);
        }
        return false;
    }
}
