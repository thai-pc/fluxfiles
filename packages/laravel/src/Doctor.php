<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

/**
 * Host-app health checks shared by `GET {route_prefix}/disk/doctor` and
 * `php artisan fluxfiles:doctor`. Pure functions over plain arrays/paths so the
 * logic has one home and no Laravel dependency. Never touches the network —
 * the optional loopback probe lives in the artisan command only (a single-
 * threaded `php artisan serve` would deadlock on an HTTP self-request).
 *
 * Each check has the same shape BucketDoctor emits:
 * {id, status: ok|info|warn|fail, message, fix}.
 */
final class Doctor
{
    /**
     * URL path (e.g. "/uploads" or "/storage/fluxfiles/uploads") under which a
     * local disk root is reachable from the web, or null when it is not inside
     * the web root. Covers `public/` itself and the `public/storage` symlink
     * that `artisan storage:link` points at `storage/app/public`.
     */
    public static function webPath(string $root, ?string $publicPath): ?string
    {
        if ($publicPath === null || $publicPath === '') {
            return null;
        }
        $real = self::realish($root);
        $pub  = realpath($publicPath);
        if ($real === null || $pub === false) {
            return null;
        }

        // Each entry: [real directory that is web-served, URL path of that directory].
        $served = [[$pub, '']];
        $link = rtrim($publicPath, '/\\') . '/storage';
        if (is_link($link) && ($target = realpath($link)) !== false) {
            $served[] = [$target, '/storage'];
        }

        foreach ($served as [$dir, $url]) {
            if ($real === $dir) {
                return $url === '' ? '/' : $url;
            }
            if (str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
                return $url . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($dir) + 1));
            }
        }
        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $disks  config('fluxfiles.disks')
     * @return list<array{disk:string,root:string,url_path:string}>
     */
    public static function exposedDisks(array $disks, ?string $publicPath, ?string $only = null): array
    {
        $out = [];
        foreach ($disks as $name => $cfg) {
            if (($only !== null && $name !== $only) || ($cfg['driver'] ?? 'local') !== 'local') {
                continue;
            }
            $root = (string) ($cfg['root'] ?? '');
            $path = $root === '' ? null : self::webPath($root, $publicPath);
            if ($path !== null) {
                $out[] = ['disk' => (string) $name, 'root' => $root, 'url_path' => $path];
            }
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $disks
     * @param bool $showRoot include the absolute root in messages (CLI only; the
     *                       HTTP endpoint is reachable by tenant tokens)
     * @return list<array{id:string,status:string,message:string,fix:?string}>
     */
    public static function exposureChecks(array $disks, ?string $publicPath, ?string $only = null, bool $showRoot = false): array
    {
        // A single non-local disk (S3/R2/SFTP) has no web root to be inside: say nothing.
        if ($only !== null && (($disks[$only]['driver'] ?? 'local') !== 'local')) {
            return [];
        }
        $exposed = self::exposedDisks($disks, $publicPath, $only);
        if ($exposed === []) {
            return [['id' => 'web_exposure', 'status' => 'ok',
                'message' => 'No local disk root is inside the web root.', 'fix' => null]];
        }
        $checks = [];
        foreach ($exposed as $e) {
            $where = rtrim($e['url_path'], '/');
            $checks[] = [
                'id'      => 'web_exposure:' . $e['disk'],
                'status'  => 'warn',
                'message' => "Local disk '{$e['disk']}'" . ($showRoot ? " (root {$e['root']})" : '')
                    . " is inside the web root (served at {$e['url_path']}), so {$where}/_fluxfiles/ is "
                    . 'probably downloadable: the audit log (user ids, IPs), the file index of every tenant, '
                    . 'metadata sidecars and trashed files.',
                'fix'     => "Deny it at the web server. nginx (in the server block that serves the app, before any "
                    . "static location): location ~* /_fluxfiles/ { deny all; } -- Apache (public/.htaccess): "
                    . "RewriteEngine On + RewriteRule (^|/)_fluxfiles(/|\$) - [F,NC]. Or move the disk root "
                    . "outside public/ (e.g. storage_path('app/fluxfiles') with FLUXFILES_LOCAL_PRIVATE=true).",
            ];
        }
        return $checks;
    }

    /**
     * Compare the app's top-level config keys with the package's own config.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $package
     * @return array{missing:list<string>,unknown:list<string>}
     */
    public static function configDrift(array $app, array $package): array
    {
        return [
            'missing' => array_values(array_diff(array_keys($package), array_keys($app))),
            'unknown' => array_values(array_diff(array_keys($app), array_keys($package))),
        ];
    }

    /**
     * @param array<string,mixed> $app
     * @param array<string,mixed> $package
     * @return list<array{id:string,status:string,message:string,fix:?string}>
     */
    public static function driftChecks(array $app, array $package): array
    {
        $d = self::configDrift($app, $package);
        $checks = [];
        if ($d['missing'] !== []) {
            $checks[] = ['id' => 'config_missing_keys', 'status' => 'warn',
                'message' => 'config/fluxfiles.php lacks keys this version defines: ' . implode(', ', $d['missing'])
                    . '. Their package defaults apply, but a stale published file hides new options.',
                'fix' => 'Copy these keys from vendor/fluxfiles/laravel/config/fluxfiles.php, or re-publish: '
                    . 'php artisan vendor:publish --tag=fluxfiles-config --force (keep your edits).'];
        }
        if ($d['unknown'] !== []) {
            $checks[] = ['id' => 'config_unknown_keys', 'status' => 'info',
                'message' => 'config/fluxfiles.php has keys this version does not define (renamed or removed?): '
                    . implode(', ', $d['unknown']) . '.',
                'fix' => null];
        }
        if ($checks === []) {
            $checks[] = ['id' => 'config_drift', 'status' => 'ok',
                'message' => 'config/fluxfiles.php has every key the package defines.', 'fix' => null];
        }
        return $checks;
    }

    /** @param list<array{status:string}> $checks */
    public static function summarize(array $checks): string
    {
        $s = array_column($checks, 'status');
        return in_array('fail', $s, true) ? 'fail' : (in_array('warn', $s, true) ? 'warn' : 'ok');
    }

    /**
     * Loopback probe (CLI only): HEAD {base}{urlPath}/_fluxfiles/index.json.
     *
     * @return 'served'|'blocked'|'unreachable'|string  'served' on 200, 'blocked' on 403/404
     */
    public static function probe(string $baseUrl, string $urlPath, int $timeout = 3): string
    {
        $url = rtrim($baseUrl, '/') . rtrim($urlPath, '/') . '/_fluxfiles/index.json';
        $ctx = stream_context_create(['http' => [
            'method' => 'HEAD', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $headers = @get_headers($url, false, $ctx);
        if (!is_array($headers) || !isset($headers[0]) || !preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
            return 'unreachable';
        }
        $code = (int) $m[1];
        if ($code === 200) {
            return 'served';
        }
        return in_array($code, [401, 403, 404], true) ? 'blocked' : 'http ' . $code;
    }

    /** realpath of $path, or of its nearest existing ancestor plus the missing tail. */
    private static function realish(string $path): ?string
    {
        $tail = [];
        $p = rtrim($path, '/\\');
        while ($p !== '' && $p !== dirname($p) && ($r = realpath($p)) === false) {
            array_unshift($tail, basename($p));
            $p = dirname($p);
        }
        $r = $p === '' ? false : realpath($p);
        return $r === false ? null : rtrim($r . ($tail ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $tail) : ''), '/');
    }
}
