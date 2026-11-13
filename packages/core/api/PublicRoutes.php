<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Routing + gate for the PUBLIC (no main JWT) routes of the Share and Intake modules.
 *
 * Every host (standalone index.php, the WordPress and Laravel proxies) asks
 * {@see moduleFor()} whether a path is one of these routes and, if so, hands it to
 * {@see dispatch()}. The dispatcher is the gate, in a fixed order:
 *
 *   1. the signing secret is configured                       (500 server_error)
 *   2. the module's code is installed                         (501 module_not_installed)
 *   3. the licence entitles it                                (402 license_required / license_expired)
 *   4. the module serves the route                            (its own enforcement)
 *
 * The recipient presents a share/intake token, not a main JWT, so there is no claim
 * layer: the token IS the per-request authorisation, and the module verifies it.
 *
 * A module that does not implement {@see PublicModuleInterface} (a release from before
 * the interface existed) cannot serve these routes: core no longer carries the old in-core
 * handlers, so the request answers 501 `module_update_required` (update the module).
 */
final class PublicRoutes
{
    /**
     * module id → route name → path. `page` is the HTML recipient page; the rest are the
     * JSON/bytes routes.
     *
     * @var array<string,array<string,string>>
     */
    public const ROUTES = [
        'share' => [
            'info'   => '/api/fm/share/info',
            'unlock' => '/api/fm/share/unlock',
            'file'   => '/api/fm/share/file',
            'page'   => '/api/fm/share/page',
        ],
        'intake' => [
            'info'   => '/api/fm/intake/info',
            'upload' => '/api/fm/intake/upload',
            'page'   => '/api/fm/intake/page',
        ],
    ];

    /** The module id that owns this request path, or null when it isn't a public module route. */
    public static function moduleFor(string $uri): ?string
    {
        foreach (self::ROUTES as $id => $routes) {
            if (in_array($uri, $routes, true)) {
                return $id;
            }
        }
        return null;
    }

    /** Is this the module's HTML page route (as opposed to a JSON/bytes route)? */
    public static function isPage(string $id, string $uri): bool
    {
        return (self::ROUTES[$id]['page'] ?? null) === $uri;
    }

    /**
     * Gate and serve one public request, then return. Any {@see ApiException} (the gate's
     * or the module's) becomes the standard `{data,error,error_code}` envelope; anything
     * else is a 500 `server_error` with no detail. For the HTML page routes (see
     * {@see isPage()}) the same failures render a minimal HTML error page instead, so a
     * recipient's browser never shows raw JSON; the API routes keep the envelope.
     */
    public static function dispatch(string $id, string $method, string $uri, PublicContext $ctx): void
    {
        // A recipient opening the page in a browser must not be shown a raw JSON envelope.
        $isPage = self::isPage($id, $uri);
        try {
            if (!isset(self::ROUTES[$id]) || !in_array($uri, self::ROUTES[$id], true)) {
                throw new ApiException('Not found', 404, 'not_found');
            }
            if (!$ctx->secretConfigured()) {
                throw new ApiException('FLUXFILES_SECRET is not configured', 500, 'server_error');
            }
            $module = ModuleRegistry::requireServer($id, $ctx->license);
            if (!$module instanceof PublicModuleInterface) {
                throw new ApiException("The '{$id}' module is too old for this FluxFiles core; update it", 501, 'module_update_required', ['module' => $id]);
            }

            if (self::isPage($id, $uri)) {
                if ($method !== 'GET') {
                    throw new ApiException('Not found', 404, 'not_found');
                }
                $html = self::page($id, '/api/fm');
                if ($html === null) {
                    throw new ApiException('Not found', 404, 'not_found');
                }
                header('Content-Type: text/html; charset=utf-8');
                // The page carries the token in its query string: never leak it onward.
                header('Referrer-Policy: no-referrer');
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
                // Same clickjacking policy as /public/index.html.
                $csp = FrameAncestors::policy();
                if ($csp !== null) {
                    header('Content-Security-Policy: ' . $csp);
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw bytes/HTML of a stored file or a module page, sent with its real Content-Type and nosniff; escaping would corrupt it
                echo $html;
                return;
            }

            $module->handlePublic($method, $uri, $ctx);
        } catch (ApiException $e) {
            $isPage ? self::emitErrorPage($e->getHttpCode(), $e->getErrorCode() ?? 'error') : self::emitError($e);
        } catch (\Throwable $e) {
            if ($isPage) {
                self::emitErrorPage(500, 'server_error');
                return;
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => null, 'error' => 'Internal server error', 'error_code' => 'server_error']);
        }
    }

    /**
     * The module's recipient page with the API base injected as
     * `<meta name="fm-api-base">` (no inline script, so a strict CSP host stays happy).
     * Null when the module is not installed or ships no such page. Does NOT gate: callers
     * reach it through {@see dispatch()}, or have already passed the gate themselves.
     *
     * A module that predates {@see PublicModuleInterface} has no `pages()`: null.
     */
    public static function page(string $id, string $apiBase): ?string
    {
        if (!isset(self::ROUTES[$id])) {
            return null;
        }
        $class = ModuleRegistry::classFor($id);
        if ($class === null) {
            return null;
        }
        $file = null;
        if (is_subclass_of($class, PublicModuleInterface::class)) {
            $declared = $class::pages()[$id] ?? null;
            if (is_string($declared) && is_file($declared)) {
                $file = $declared;
            }
        }
        if ($file === null) {
            return null;
        }
        $html = @file_get_contents($file);
        if ($html === false) {
            return null;
        }
        return self::injectApiBase($html, $apiBase);
    }

    /** Insert `<meta name="fm-api-base" content="…">` right after `<head>` (or at the top). */
    public static function injectApiBase(string $html, string $apiBase): string
    {
        $meta = '<meta name="fm-api-base" content="' . htmlspecialchars($apiBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        $count = 0;
        // A callback, not a replacement string: the base is data, and `$0` / `\1` in it must
        // not be read as backreferences.
        $out = preg_replace_callback('/<head(\s[^>]*)?>/i', static fn (array $m): string => $m[0] . "\n" . $meta, $html, 1, $count);
        if (!is_string($out) || $count === 0) {
            return $meta . "\n" . $html;
        }
        return $out;
    }

    /**
     * Minimal, self-contained error page for a page route (no scripts, no external
     * assets, served under a deny-everything CSP). Shows a fixed English line per status class plus the
     * error code; the ApiException message is never echoed (a module could put detail in it).
     */
    private static function emitErrorPage(int $status, string $code): void
    {
        $headline = match (true) {
            $status === 402 => 'This feature is not licensed on this server.',
            $status === 404 => 'This page was not found.',
            $status === 501 => 'This feature is not installed on this server.',
            $status >= 500  => 'Something went wrong on the server.',
            default         => 'This page is not available.',
        };
        $safeCode = htmlspecialchars(preg_replace('/[^a-z0-9_]/i', '', $code) ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // Nothing to load or embed: the tightest policy (no styles, no framing).
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $status is an int, $headline one of the fixed strings above, $safeCode is filtered to [a-z0-9_] and HTML-escaped
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . (int) $status . '</title>'
            . '</head>'
            . '<body><main><h1>' . (int) $status . '</h1><p>' . $headline . '</p><small>' . $safeCode . '</small></main></body></html>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function emitError(ApiException $e): void
    {
        http_response_code($e->getHttpCode());
        header('Content-Type: application/json; charset=utf-8');
        $r = ['data' => null, 'error' => $e->getMessage()];
        if ($e->getErrorCode() !== null) {
            $r['error_code'] = $e->getErrorCode();
        }
        if ($e->getErrorParams()) {
            $r['error_params'] = $e->getErrorParams();
        }
        echo json_encode($r);
    }
}
