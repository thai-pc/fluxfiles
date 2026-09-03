<?php

declare(strict_types=1);

/**
 * The PUBLIC half of Share and Intake — the routes a recipient hits, with no main JWT.
 *
 * Also home to ff_sso_rate_limit() (below): not a Share/Intake route, but the SSO
 * bridge's pre-auth routes are the one other place with no main JWT to rate-limit
 * against, so it rides the same JSON-file limiter here rather than duplicating it.
 *
 * These live in their own file rather than in index.php for one reason: the WordPress
 * plugin has to serve the same routes, and including index.php would run its auth and
 * routing as a side effect. A file with nothing but function definitions can be pulled
 * in from anywhere.
 *
 * The alternative — reimplementing these in the WP proxy — was rejected deliberately.
 * This is ~400 lines of security-critical handling (traversal guards, MIME by extension
 * and never by sniffing, the two-bucket password brute-force limiter, presigned-redirect
 * vs streamed bytes, the download counter) and a second copy is how you get a hole that
 * exists on one platform only. Every host calls these same functions.
 *
 * Hosts that do not lay out storage the way the standalone app does (WordPress keeps
 * its uploads under wp-content) pass their own DiskManager + disk config into the two
 * handlers. Omitting them keeps the standalone behaviour exactly as it was.
 *
 * Moved verbatim from index.php; behaviour is unchanged and is pinned by
 * tests/e2e/test-share-http.php.
 */

use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\RateLimiterFactory;
use FluxFiles\RateLimiterStorageInterface;
use FluxFiles\StorageMetadataHandler;

/**
 * One string request parameter, safe against an array-shaped param.
 *
 * `?token[]=a` makes `$_GET['token']` an ARRAY, and `(string) $array` emits a PHP
 * warning — which, with display_errors on, lands ahead of the JSON envelope and
 * discloses the absolute server path. Anything non-scalar collapses to the default
 * instead, so a hostile query string can only ever produce a normal 4xx.
 *
 * @param array<string,mixed> $src $_GET / $_POST / a decoded JSON body
 */
function ff_str_param(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? null;
    return is_scalar($v) ? (string) $v : $default;
}

/**
 * Public Intake endpoints (no main JWT — authenticated by the portal token itself).
 *   GET  /api/fm/intake/info?token=…       → the landing page's data (label/caps).
 *   POST /api/fm/intake/upload {token,password?} + file → anonymous file drop.
 * Emits the same JSON envelope as the main app and exits.
 */
/**
 * @param DiskManager|null      $injectedDm     a host whose storage is not laid out
 *                                              like the standalone app's (WordPress)
 * @param array<string,mixed>|null $injectedCfg the matching disk config
 */
function handleIntakePublic(string $method, string $uri, ?DiskManager $injectedDm = null, ?array $injectedCfg = null): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
        if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
            throw new ApiException('FLUXFILES_SECRET is not configured', 500, 'server_error');
        }
        // Gate: the module must be installed + licensed (the portal token is the
        // per-request auth — there is no main JWT / claim on a public request).
        if (!\FluxFiles\ModuleRegistry::installed('intake')) {
            throw new ApiException("The 'intake' module is not installed on this server", 501, 'module_not_installed', ['module' => 'intake']);
        }
        if (!\FluxFiles\LicenseManager::fromEnv()->licensed('intake')) {
            throw new ApiException("The 'intake' module requires a valid license", 402, 'license_required', ['module' => 'intake']);
        }
        $module = new \FluxFiles\Intake\IntakeModule();
        $dm = $injectedDm ?? new DiskManager(require __DIR__ . '/../config/disks.php');

        if ($method === 'GET' && $uri === '/api/fm/intake/info') {
            $token = ff_str_param($_GET, 'token');
            ff_intake_rate_limit($token, 'info');
            echo json_encode(['data' => $module->portalInfo($dm, $secret, $token), 'error' => null]);
            return;
        }

        if ($method === 'POST' && $uri === '/api/fm/intake/upload') {
            $token = ff_str_param($_POST, 'token');
            $password = isset($_POST['password']) ? ff_str_param($_POST, 'password') : null;
            // Tighter bucket than info: this is both the password brute-force surface
            // (when the portal has one) and the anonymous-upload flood surface.
            ff_intake_rate_limit($token, 'upload');
            if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new ApiException('No file uploaded', 400, 'no_file');
            }
            $file = [
                'name'     => (string) $_FILES['file']['name'],
                'tmp_name' => (string) $_FILES['file']['tmp_name'],
                'size'     => (int) $_FILES['file']['size'],
                'type'     => (string) ($_FILES['file']['type'] ?? ''),
                'error'    => (int) $_FILES['file']['error'],
            ];
            // Build a FileManager from the portal token's own (write-scoped) claims.
            try {
                $payload = \FluxFiles\JwtCompat::decode($token, $secret);
            } catch (\Throwable $e) {
                throw new ApiException('Invalid or expired portal link', 403, 'intake_invalid');
            }
            $portalClaims = \FluxFiles\Claims::fromJwtPayload($payload);
            $portalFm = new FileManager($dm, $portalClaims, new StorageMetadataHandler($dm));
            $portalFm->setStreamSecret($secret);
            // Virus scan (paid module) — same fail-closed wiring as index.php/Laravel/
            // WordPress. Without this, an anonymous intake upload would never be
            // scanned even when the operator's own token has `allow_virus_scan` on,
            // since this is the one write path with no operator-authenticated request
            // behind it. IntakeModule::createPortal() forwards the claim into the
            // portal JWT precisely so this check can see it here.
            if ($portalClaims->allowVirusScan) {
                $portalFm->setVirusScanner(static function (string $localPath) use ($portalClaims): array {
                    /** @var \FluxFiles\Virus\VirusScanModule $virus */
                    $virus = \FluxFiles\ModuleRegistry::require('virus', \FluxFiles\LicenseManager::fromEnv(), $portalClaims);
                    return $virus->scanPath($localPath);
                });
            }
            $res = $module->receiveUpload($portalFm, $dm, $secret, $token, $file, $password);
            echo json_encode(['data' => $res, 'error' => null]);

            // Notify-on-receipt (paid: Intake + Webhooks): fired AFTER the response is
            // on the wire, same ordering as the main flow's webhook dispatch
            // (index.php, post-response fastcgi_finish_request() then dispatch) — a
            // webhook POST has a multi-second timeout and an anonymous sender has no
            // reason to wait on a third-party endpoint's response time. Manual
            // installed()/licensed() checks, NOT ModuleRegistry::require() — require()
            // throws, which is right for a gate that must block the request (like the
            // virus scanner above) and wrong here: the response is already sent, so an
            // exception at this point can't become an HTTP error anymore. Best-effort:
            // WebhooksModule::dispatch() never throws on its own, the try/catch below
            // is belt-and-suspenders for the same fail-open invariant.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                if (\FluxFiles\ModuleRegistry::installed('webhooks')
                    && \FluxFiles\LicenseManager::fromEnv()->licensed('webhooks')) {
                    $jti = (string) ($payload->jti ?? '');
                    $store = (string) ($payload->store ?? '');
                    // A portal token is minted for exactly one disk (see
                    // IntakeModule::createPortal()), so [0] is safe here — this is
                    // NOT the general multi-disk case a main-app token can carry.
                    $whDisk = $portalClaims->allowedDisks[0] ?? '';
                    $wh = $module->webhookConfigFor($dm, $whDisk, $store, $jti);
                    if ($wh !== null) {
                        $webhooks = new \FluxFiles\Webhooks\WebhooksModule();
                        $whClaims = new \FluxFiles\Claims($wh['owner'], [], [], '', 0, null, 0);
                        $whClaims->webhookUrl = $wh['url'];
                        $whClaims->webhookEvents = $wh['events'];
                        $whClaims->webhookSecret = $wh['secret'];
                        $webhooks->dispatch($whClaims, $secret, 'intake_received', [
                            'disk'         => $wh['disk'],
                            'path'         => $wh['path'],
                            'name'         => is_array($res) ? (string) ($res['name'] ?? '') : '',
                            'portal_label' => $wh['label'],
                            'portal_jti'   => $jti,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                error_log('[fluxfiles] intake notify-on-receipt failed: ' . $e->getMessage());
            }
            return;
        }

        throw new ApiException('Not found', 404, 'not_found');
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        $r = ['data' => null, 'error' => $e->getMessage()];
        if ($e->getErrorCode() !== null) { $r['error_code'] = $e->getErrorCode(); }
        if ($e->getErrorParams()) { $r['error_params'] = $e->getErrorParams(); }
        echo json_encode($r);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['data' => null, 'error' => 'Internal server error', 'error_code' => 'server_error']);
    }
}

/**
 * Public Share endpoints (no main JWT — authenticated by the share token itself).
 *   GET  /api/fm/share/info?token=…            → the landing card (counts a view).
 *   POST /api/fm/share/unlock {token,password} → card + a short-lived grant token.
 *   GET  /api/fm/share/file?token=…[&g=][&dl=] → the bytes (or a presigned 302).
 *
 * Gated on installed + licensed only: a public request carries no main JWT, so
 * there is no claim to check (the share token IS the per-request auth). Every
 * enforcement decision — password, expiry, cap, revocation, the grant — lives in
 * the paid module; core only routes, rate-limits, and moves bytes.
 */
/**
 * @param DiskManager|null      $injectedDm     see handleIntakePublic
 * @param array<string,mixed>|null $injectedCfg the matching disk config
 */
function handleSharePublic(string $method, string $uri, ?DiskManager $injectedDm = null, ?array $injectedCfg = null): void
{
    // Set per branch, not up front: the info/unlock branches emit JSON, while
    // `file` emits bytes (or a 302) and picks its own type. The error paths below
    // set it themselves, so an early throw is still a JSON envelope.
    try {
        $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
        if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
            throw new ApiException('FLUXFILES_SECRET is not configured', 500, 'server_error');
        }
        if (!\FluxFiles\ModuleRegistry::installed('share')) {
            throw new ApiException("The 'share' module is not installed on this server", 501, 'module_not_installed', ['module' => 'share']);
        }
        if (!\FluxFiles\LicenseManager::fromEnv()->licensed('share')) {
            throw new ApiException("The 'share' module requires a valid license", 402, 'license_required', ['module' => 'share']);
        }
        $module = new \FluxFiles\Share\ShareModule();
        $diskConfigs = $injectedCfg ?? require __DIR__ . '/../config/disks.php';
        $dm = $injectedDm ?? new DiskManager($diskConfigs);

        if ($method === 'GET' && $uri === '/api/fm/share/info') {
            $token = ff_str_param($_GET, 'token');
            ff_share_rate_limit($token, 'view');
            $out = $module->shareInfo($dm, $secret, $token);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => ff_share_payload($out, $token, $secret), 'error' => null]);
            return;
        }

        if ($method === 'POST' && $uri === '/api/fm/share/unlock') {
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($body)) { $body = []; }   // a scalar JSON body is not a form
            $token = ff_str_param($body, 'token', ff_str_param($_POST, 'token'));
            $password = ff_str_param($body, 'password', ff_str_param($_POST, 'password'));
            // Tighter bucket than info/file: this is the password brute-force surface.
            ff_share_rate_limit($token, 'unlock');
            $out = $module->unlockShare($dm, $secret, $token, $password);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => ff_share_payload($out, $token, $secret), 'error' => null]);
            return;
        }

        if ($method === 'GET' && $uri === '/api/fm/share/file') {
            $token = ff_str_param($_GET, 'token');
            ff_share_rate_limit($token, 'view');
            // Fixed order: gate → rate limit → enforce + count → THEN bytes. The
            // module verifies the grant, the expiry and the cap and increments the
            // download counter; nothing is served if it throws.
            $res = $module->resolveShare($dm, $secret, $token, null, true, isset($_GET['g']) ? ff_str_param($_GET, 'g') : null);
            ff_share_send_bytes($dm, $diskConfigs, $res, ($_GET['dl'] ?? '') === '1');
            return;
        }

        throw new ApiException('Not found', 404, 'not_found');
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        header('Content-Type: application/json; charset=utf-8');
        $r = ['data' => null, 'error' => $e->getMessage()];
        if ($e->getErrorCode() !== null) { $r['error_code'] = $e->getErrorCode(); }
        if ($e->getErrorParams()) { $r['error_params'] = $e->getErrorParams(); }
        echo json_encode($r);
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => null, 'error' => 'Internal server error', 'error_code' => 'server_error']);
    }
}

/**
 * Turn the module's info/unlock result into the public JSON payload, minting the
 * preview URL core-side (the module decides IF a preview is allowed; core owns the
 * route shapes). Images preview through /api/fm/img — a bounded transform, never
 * the original bytes, and it never touches the download counter. PDFs preview
 * through the share bytes route itself, which the module only offers on an
 * uncapped share (an <iframe> of the real bytes IS a download).
 *
 * @param array{info:array<string,mixed>,preview:array<string,mixed>|null} $out
 * @return array<string,mixed>
 */
function ff_share_payload(array $out, string $token, string $secret): array
{
    $info = is_array($out['info'] ?? null) ? $out['info'] : [];
    $prev = is_array($out['preview'] ?? null) ? $out['preview'] : null;
    if ($prev === null) {
        return $info;
    }

    $url = null;
    $kind = (string) ($prev['kind'] ?? '');
    $ttl = max(1, (int) ($prev['ttl'] ?? \FluxFiles\ShareGrant::DEFAULT_TTL));
    if ($kind === 'image') {
        $imgToken = \FluxFiles\ImageToken::mint(
            (string) ($prev['disk'] ?? ''),
            (string) ($prev['path'] ?? ''),
            (string) ($prev['sub'] ?? 'share'),
            $ttl,
            $secret,
            1600
        );
        $url = '/api/fm/img?token=' . rawurlencode($imgToken) . '&width=1200';
    } elseif ($kind === 'pdf') {
        $url = '/api/fm/share/file?token=' . rawurlencode($token);
        if (!empty($info['grant'])) {
            $url .= '&g=' . rawurlencode((string) $info['grant']);
        }
    }
    if ($url === null) {
        return $info;
    }

    if (isset($info['file']) && is_array($info['file'])) {
        $info['file']['preview_url'] = $url;
    }
    if (isset($info['files'][0]) && is_array($info['files'][0])) {
        $info['files'][0]['preview_url'] = $url;
    }
    return $info;
}

/**
 * Emit the shared file's bytes. Dispatch is on the DISK DRIVER, never on the
 * file's `url`: a public local disk has a static URL that would bypass the
 * password/cap entirely, so local always streams through the app.
 *
 *   s3 (S3 + R2)          → 302 to a short-TTL presigned URL (no app egress)
 *   local (private/public)→ streamed here (Range-capable; never the static URL)
 *   sftp                  → streamed here (no presign, no static URL)
 *
 * @param array<string,mixed> $diskConfigs
 * @param array<string,mixed> $res resolveShare() result (disk/path/name/mime/url_ttl)
 */
function ff_share_send_bytes(DiskManager $dm, array $diskConfigs, array $res, bool $forceDownload): void
{
    $disk = (string) ($res['disk'] ?? '');
    $path = (string) ($res['path'] ?? '');
    // The path comes from the SIGNED record, but reject a traversal defensively.
    if ($path === '' || strpos($path, "\0") !== false
        || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
        throw new ApiException('Invalid share', 403, 'share_invalid');
    }
    $name = basename($path);
    // MIME by extension (never sniffed — an .html that sniffs as text/html must not
    // become active content in the FluxFiles origin).
    $mime = (string) ($res['mime'] ?? '');
    if ($mime === '') {
        $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    }
    // Inline only for the same safe set as handleMediaStream; everything else (and
    // an explicit ?dl=1) is forced to attachment.
    $inline = !$forceDownload && (bool) preg_match('#^(video/|audio/|image/(?!svg))|^application/pdf$#', $mime);
    $disposition = ff_content_disposition($name, $inline);

    $cfg = is_array($diskConfigs[$disk] ?? null) ? $diskConfigs[$disk] : [];
    $driver = (string) ($cfg['driver'] ?? '');

    if ($driver === 's3') {
        // Documented limitation: on S3/R2 the cap counts GRANTS, not downloads — a
        // handed-out presigned URL stays fetchable until it expires. share_url_ttl
        // (default 60s, clamped 10–300) bounds that window.
        $url = $dm->presignGetUrl($disk, $path, max(10, (int) ($res['url_ttl'] ?? 60)), $disposition);
        if ($url === null) {
            throw new ApiException('This file is temporarily unavailable', 502, 'share_unavailable');
        }
        ff_share_headers($disposition);
        header('Location: ' . $url, true, 302);
        return;
    }

    if ($driver === 'local') {
        $root = realpath($cfg['root'] ?? (__DIR__ . '/../storage/uploads'));
        $abs  = realpath(($root ?: '') . '/' . $path);
        // The resolved file must stay inside the disk root (symlink / traversal guard).
        if ($root === false || $abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
            throw new ApiException('This file is no longer available', 404, 'share_gone');
        }
        ff_share_headers($disposition);
        // Production fast path for a gated (private) local disk — the same nginx
        // internal location /api/fm/stream uses. Public local disks stream through
        // PHP (their root isn't the X-Accel location).
        $xaccel = $_ENV['FLUXFILES_XACCEL'] ?? '';
        if ($xaccel !== '' && !empty($cfg['private'])) {
            header('Content-Type: ' . $mime);
            header('X-Accel-Buffering: no');
            header('X-Accel-Redirect: ' . rtrim($xaccel, '/') . '/' . $path);
            return;
        }
        \FluxFiles\RangeStreamer::stream($abs, $mime, $_SERVER['HTTP_RANGE'] ?? null);
        return;
    }

    if ($driver === 'sftp') {
        $stream = null;
        try {
            $fs = $dm->disk($disk);
            if (!$fs->fileExists($path)) {
                throw new ApiException('This file is no longer available', 404, 'share_gone');
            }
            $size = (int) ($res['size'] ?? 0);
            $stream = $fs->readStream($path);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Nothing has been written yet, so a JSON envelope is still correct.
            throw new ApiException('This file is temporarily unavailable', 502, 'share_unavailable');
        }
        ff_share_headers($disposition);
        header('Content-Type: ' . $mime);
        // Content-Length so a truncated transfer is detectable as truncated (no
        // Range support over SFTP here — say so rather than imply it).
        if ($size > 0) { header('Content-Length: ' . $size); }
        header('Accept-Ranges: none');
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) { break; }
                echo $chunk;
                @flush();
            }
        } catch (\Throwable $e) {
            // Mid-stream: the headers and part of the body are already out, so an
            // exception here would append a JSON envelope to partial FILE BYTES (and
            // warn about headers already sent). Stop instead — the short body against
            // the declared Content-Length is the client's signal.
            error_log('FluxFiles share sftp stream failed: ' . $e->getMessage());
        } finally {
            if (is_resource($stream)) { fclose($stream); }
        }
        return;
    }

    throw new ApiException('This file is temporarily unavailable', 502, 'share_unavailable');
}

/** Common response headers for the share bytes route (and its presigned redirect). */
function ff_share_headers(string $disposition): void
{
    header('Content-Disposition: ' . $disposition);
    header('X-Content-Type-Options: nosniff');
    // A shared .html/.svg must not run in the FluxFiles origin, and the token must
    // not leak to a sub-resource or an outbound link.
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
}

/** RFC-5987 Content-Disposition from a display filename (never the storage key). */
function ff_content_disposition(string $name, bool $inline): string
{
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'file';
    $ascii = str_replace(['"', '\\'], '_', $ascii);
    return ($inline ? 'inline' : 'attachment')
        . '; filename="' . $ascii . '"'
        . "; filename*=UTF-8''" . rawurlencode($name);
}

/**
 * Per-share rate limit for the public share routes, using the existing JSON-file
 * limiter: `view` (info + file) and the tighter `unlock` (password attempts, keyed
 * by share + client IP). The share id is read from the UNVERIFIED payload — it's
 * only a bucket key, and tampering it invalidates the signature anyway, so it
 * can't be used to escape the bucket with a working token.
 */
function ff_share_rate_limit(string $token, string $kind): void
{
    $jti = ff_share_token_jti($token);
    $limiter = static function (int $limit): RateLimiterStorageInterface {
        return RateLimiterFactory::make($limit, $limit, 60);
    };

    if ($kind === 'unlock') {
        // TWO buckets, because either one alone is escapable or self-DoSing:
        //  · per share + client IP — stops one guesser, but REMOTE_ADDR is free to
        //    rotate (proxy pool, a single IPv6 /64), so it cannot be the only limit;
        //  · per share, no attacker-controlled component — the real ceiling. It must
        //    stay well above a shared-office NAT (every recipient behind one IP shares
        //    the first bucket, so a handful of people mistyping a password must not
        //    lock the link), hence a roomier default of 30/min for a whole share.
        // Both throw the already-translated `rate_limited` (429).
        $limiter(max(1, (int) ($_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT'] ?? 5)))
            ->check('share_unlock:' . $jti . ':' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'read');
        $limiter(max(1, (int) ($_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL'] ?? 30)))
            ->check('share_unlock_all:' . $jti, 'read');
        return;
    }

    $limiter(max(1, (int) ($_ENV['FLUXFILES_SHARE_RATE_LIMIT'] ?? 60)))->check('share:' . $jti, 'read');
}

/**
 * Per-portal rate limit for the public Intake routes, mirroring ff_share_rate_limit()
 * above (same JSON-file limiter, same 60s window, same unverified-payload jti as the
 * bucket key — tampering it invalidates the signature anyway). `info` is a single
 * roomy bucket (the landing page poll); `upload` gets the same two-bucket shape as
 * share's `unlock` (per portal + client IP, and a no-IP-component portal-wide
 * ceiling) because it is simultaneously the portal's password brute-force surface
 * (when one is set) and its anonymous-upload flood surface, and REMOTE_ADDR alone is
 * never a safe-only limit — it rotates for free behind a proxy pool or IPv6 /64.
 */
function ff_intake_rate_limit(string $token, string $kind): void
{
    $jti = ff_share_token_jti($token);
    $limiter = static function (int $limit): RateLimiterStorageInterface {
        return RateLimiterFactory::make($limit, $limit, 60);
    };

    if ($kind === 'upload') {
        $limiter(max(1, (int) ($_ENV['FLUXFILES_INTAKE_UPLOAD_LIMIT'] ?? 10)))
            ->check('intake_upload:' . $jti . ':' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'read');
        $limiter(max(1, (int) ($_ENV['FLUXFILES_INTAKE_UPLOAD_TOTAL'] ?? 60)))
            ->check('intake_upload_all:' . $jti, 'read');
        return;
    }

    $limiter(max(1, (int) ($_ENV['FLUXFILES_INTAKE_RATE_LIMIT'] ?? 60)))->check('intake:' . $jti, 'read');
}

/**
 * Per-client-IP rate limit for the SSO bridge's three pre-auth routes
 * (`login`/`callback`/`exchange`), mirroring ff_share_rate_limit()'s JSON-file
 * limiter. Unlike share/intake, there is no token/id to bucket by until AFTER
 * `callback` verifies `state` — so `REMOTE_ADDR` is the only key available,
 * same rotation caveat as the IP half of `share_unlock`/`intake_upload`
 * (not a safe-ONLY limit on its own, but there is no better key pre-auth).
 * `callback` gets the tightest default: it triggers a real outbound cURL to
 * the IdP's token endpoint per hit, the most expensive of the three.
 */
function ff_sso_rate_limit(string $kind): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $limiter = static function (int $limit): RateLimiterStorageInterface {
        return RateLimiterFactory::make($limit, $limit, 60);
    };

    $limits = [
        'login' => (int) ($_ENV['FLUXFILES_SSO_LOGIN_LIMIT'] ?? 20),
        'callback' => (int) ($_ENV['FLUXFILES_SSO_CALLBACK_LIMIT'] ?? 10),
        'exchange' => (int) ($_ENV['FLUXFILES_SSO_EXCHANGE_LIMIT'] ?? 30),
    ];
    $limit = max(1, $limits[$kind] ?? 20);
    $limiter($limit)->check('sso_' . $kind . ':' . $ip, 'read');
}

/** The share id from a token's unverified payload; a hash of the token otherwise. */
function ff_share_token_jti(string $token): string
{
    $parts = explode('.', $token);
    if (count($parts) === 3) {
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $jti = is_array($payload) ? ($payload['jti'] ?? '') : '';
        if (is_string($jti) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $jti)) {
            return $jti;
        }
    }
    return substr(hash('sha256', $token), 0, 24);
}
