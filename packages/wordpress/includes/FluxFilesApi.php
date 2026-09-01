<?php

defined('ABSPATH') || exit;

use FluxFiles\ApiException;
use FluxFiles\AuditLogStorage;
use FluxFiles\BucketDoctor;
use FluxFiles\ChunkUploader;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiterFileStorage;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;

/**
 * REST API controller — registers all FluxFiles endpoints under /wp-json/fluxfiles/v1/.
 */
class FluxFilesApi
{
    private DiskManager $diskManager;
    private MetadataRepositoryInterface $metaRepo;

    public function __construct()
    {
        $storagePath = FluxFilesPlugin::storagePath();

        if (!is_dir($storagePath)) {
            wp_mkdir_p($storagePath);
        }

        $this->diskManager = new DiskManager(FluxFilesPlugin::diskConfigs());
        $this->metaRepo = new StorageMetadataHandler($this->diskManager);
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    /**
     * When a request to our namespace carries a valid Bearer JWT, suppress
     * WP REST's default cookie/nonce auth check so the request reaches our
     * permission_callback instead of being rejected with rest_cookie_invalid_nonce.
     */
    public static function bypassCookieAuthForBearer($result)
    {
        // Only intervene on our routes.
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        if (strpos($uri, '/fluxfiles/v1/') === false && strpos($query, 'fluxfiles/v1') === false) {
            return $result;
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+/i', $auth)) {
            return $result;
        }
        // A Bearer is present — let permission_callback decide.
        return null;
    }

    public static function registerRoutes(): void
    {
        $api = new self();
        $ns  = 'fluxfiles/v1';

        // Stop WP's cookie/nonce auth from short-circuiting when we already have
        // a Bearer JWT. Hooked at priority 999 so it runs AFTER WP's default
        // `rest_cookie_check_errors` (priority 100) and can clear its WP_Error.
        add_filter('rest_authentication_errors', [self::class, 'bypassCookieAuthForBearer'], 999);

        // The browser SDK builds URLs as `${endpoint}/api/fm/<route>` — keep that
        // prefix here so the same fm.js works against either the standalone PHP
        // server or this WP REST proxy without conditionals.
        $p = '/api/fm';
        $readArgs  = ['methods' => 'GET', 'permission_callback' => [self::class, 'checkAuth']];
        $writeArgs = ['methods' => 'POST', 'permission_callback' => [self::class, 'checkAuth']];

        // Core file operations
        register_rest_route($ns, $p . '/list', array_merge($readArgs, [
            'callback' => [$api, 'handleList'],
        ]));
        register_rest_route($ns, $p . '/upload', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleUpload'],
        ]);
        register_rest_route($ns, $p . '/import-url', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleImportUrl'],
        ]);
        register_rest_route($ns, $p . '/delete', [
            'methods'             => 'DELETE',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleDelete'],
        ]);
        register_rest_route($ns, $p . '/rename', array_merge($writeArgs, [
            'callback' => [$api, 'handleRename'],
        ]));
        register_rest_route($ns, $p . '/move', array_merge($writeArgs, [
            'callback' => [$api, 'handleMove'],
        ]));
        register_rest_route($ns, $p . '/copy', array_merge($writeArgs, [
            'callback' => [$api, 'handleCopy'],
        ]));
        register_rest_route($ns, $p . '/mkdir', array_merge($writeArgs, [
            'callback' => [$api, 'handleMkdir'],
        ]));
        register_rest_route($ns, $p . '/cross-copy', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrossCopy'],
        ]));
        register_rest_route($ns, $p . '/cross-move', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrossMove'],
        ]));
        register_rest_route($ns, $p . '/crop', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrop'],
        ]));
        register_rest_route($ns, $p . '/watermark', array_merge($writeArgs, [
            'callback' => [$api, 'handleWatermark'],
        ]));
        register_rest_route($ns, $p . '/watermark/remove', array_merge($writeArgs, [
            'callback' => [$api, 'handleWatermarkRemove'],
        ]));
        register_rest_route($ns, $p . '/ai-tag', array_merge($writeArgs, [
            'callback' => [$api, 'handleAiTag'],
        ]));
        register_rest_route($ns, $p . '/presign', array_merge($writeArgs, [
            'callback' => [$api, 'handlePresign'],
        ]));

        // File meta
        register_rest_route($ns, $p . '/meta', array_merge($readArgs, [
            'callback' => [$api, 'handleMeta'],
        ]));

        // Metadata CRUD
        register_rest_route($ns, $p . '/metadata', [
            [
                'methods'             => 'GET',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleGetMetadata'],
            ],
            [
                'methods'             => 'PUT',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleSaveMetadata'],
            ],
            [
                'methods'             => 'DELETE',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleDeleteMetadata'],
            ],
        ]);

        // Config / code editor (works on any disk)
        register_rest_route($ns, $p . '/content', [
            [
                'methods'             => 'GET',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleGetContent'],
            ],
            [
                'methods'             => 'PUT',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handlePutContent'],
            ],
        ]);

        // Extract a zip in place (works on any disk; returns JSON)
        register_rest_route($ns, $p . '/extract', array_merge($writeArgs, [
            'callback' => [$api, 'handleExtract'],
        ]));

        // Trash (soft-delete) — gated by the 'delete' permission inside FileManager
        register_rest_route($ns, $p . '/trash', array_merge($writeArgs, [
            'callback' => [$api, 'handleTrash'],
        ]));
        register_rest_route($ns, $p . '/trash/restore', array_merge($writeArgs, [
            'callback' => [$api, 'handleTrashRestore'],
        ]));
        register_rest_route($ns, $p . '/trash/list', array_merge($readArgs, [
            'callback' => [$api, 'handleTrashList'],
        ]));
        register_rest_route($ns, $p . '/trash/purge', array_merge($writeArgs, [
            'callback' => [$api, 'handleTrashPurge'],
        ]));
        register_rest_route($ns, $p . '/trash/empty', array_merge($writeArgs, [
            'callback' => [$api, 'handleTrashEmpty'],
        ]));

        // Bucket Doctor — diagnose a disk backend (writes/deletes a probe object,
        // so it requires the 'write' permission on a disk the token may access).
        register_rest_route($ns, $p . '/disk/doctor', array_merge($readArgs, [
            'callback' => [$api, 'handleDiskDoctor'],
        ]));

        // Search, quota, audit
        register_rest_route($ns, $p . '/search', array_merge($readArgs, [
            'callback' => [$api, 'handleSearch'],
        ]));
        register_rest_route($ns, $p . '/search-folders', array_merge($readArgs, [
            'callback' => [$api, 'handleSearchFolders'],
        ]));
        register_rest_route($ns, $p . '/quota', array_merge($readArgs, [
            'callback' => [$api, 'handleQuota'],
        ]));
        register_rest_route($ns, $p . '/usage', array_merge($readArgs, [
            'callback' => [$api, 'handleUsage'],
        ]));
        register_rest_route($ns, $p . '/license', array_merge($readArgs, [
            'callback' => [$api, 'handleLicense'],
        ]));
        register_rest_route($ns, $p . '/optimize', array_merge($writeArgs, [
            'callback' => [$api, 'handleOptimize'],
        ]));
        register_rest_route($ns, $p . '/audit', array_merge($readArgs, [
            'callback' => [$api, 'handleAudit'],
        ]));

        // Audit export/purge (paid module)
        register_rest_route($ns, $p . '/audit/export', array_merge($readArgs, [
            'callback' => [$api, 'handleAuditExport'],
        ]));
        register_rest_route($ns, $p . '/audit/purge', array_merge($writeArgs, [
            'callback' => [$api, 'handleAuditPurge'],
        ]));

        // Webhooks (paid module) — send a test ping to the configured endpoint
        register_rest_route($ns, $p . '/webhooks/test', array_merge($writeArgs, [
            'callback' => [$api, 'handleWebhooksTest'],
        ]));

        // File versioning (paid module) — list prior versions of a file / restore one
        register_rest_route($ns, $p . '/versions', array_merge($readArgs, [
            'callback' => [$api, 'handleVersions'],
        ]));
        register_rest_route($ns, $p . '/versions/restore', array_merge($writeArgs, [
            'callback' => [$api, 'handleVersionsRestore'],
        ]));

        // AI Vision / OCR / Backup Bridge / C2PA (paid modules)
        register_rest_route($ns, $p . '/ai-vision', array_merge($writeArgs, [
            'callback' => [$api, 'handleAiVision'],
        ]));
        register_rest_route($ns, $p . '/ocr', array_merge($writeArgs, [
            'callback' => [$api, 'handleOcr'],
        ]));
        register_rest_route($ns, $p . '/backup', array_merge($writeArgs, [
            'callback' => [$api, 'handleBackup'],
        ]));
        register_rest_route($ns, $p . '/c2pa', array_merge($writeArgs, [
            'callback' => [$api, 'handleC2pa'],
        ]));
        register_rest_route($ns, $p . '/c2pa/sign', array_merge($writeArgs, [
            'callback' => [$api, 'handleC2paSign'],
        ]));

        // SSH terminal (command-runner, SFTP disks only; free/core, not a paid module)
        register_rest_route($ns, $p . '/terminal', array_merge($writeArgs, [
            'callback' => [$api, 'handleTerminal'],
        ]));

        // ── Share + Intake ──────────────────────────────────────────────────
        // Operator side: create/list/revoke, behind the normal JWT + the paid-module
        // gate. The public recipient routes are registered separately below and are
        // deliberately NOT behind checkAuth.
        register_rest_route($ns, $p . '/share', array_merge($writeArgs, [
            'callback' => [$api, 'handleShareCreate'],
        ]));
        register_rest_route($ns, $p . '/share/list', array_merge($readArgs, [
            'callback' => [$api, 'handleShareList'],
        ]));
        register_rest_route($ns, $p . '/share/revoke', array_merge($writeArgs, [
            'callback' => [$api, 'handleShareRevoke'],
        ]));
        register_rest_route($ns, $p . '/share/analytics', array_merge($readArgs, [
            'callback' => [$api, 'handleShareAnalytics'],
        ]));
        register_rest_route($ns, $p . '/intake', array_merge($writeArgs, [
            'callback' => [$api, 'handleIntakeCreate'],
        ]));
        register_rest_route($ns, $p . '/intake/list', array_merge($readArgs, [
            'callback' => [$api, 'handleIntakeList'],
        ]));
        register_rest_route($ns, $p . '/intake/revoke', array_merge($writeArgs, [
            'callback' => [$api, 'handleIntakeRevoke'],
        ]));
        register_rest_route($ns, $p . '/intake/analytics', array_merge($readArgs, [
            'callback' => [$api, 'handleIntakeAnalytics'],
        ]));

        // ── PUBLIC recipient routes ─────────────────────────────────────────
        //
        // `__return_true` is correct here and must stay: these are the routes a
        // RECIPIENT hits, someone with no WordPress account and no JWT. They are
        // authenticated by the share/portal token in the query string — the same
        // posture as /img and /stream — and every check (signature, expiry, password,
        // download cap, revocation) happens inside the shared core handler.
        //
        // What that means in practice: an unauthenticated caller reaches core code, so
        // the gate is the token, not this callback. Do not "tighten" this to checkAuth
        // — that would make every share link 401 for the people it is for.
        $public = ['permission_callback' => '__return_true'];
        register_rest_route($ns, $p . '/share/info', array_merge($public, [
            'methods' => 'GET', 'callback' => [$api, 'handleSharePublicRoute'],
        ]));
        register_rest_route($ns, $p . '/share/unlock', array_merge($public, [
            'methods' => 'POST', 'callback' => [$api, 'handleSharePublicRoute'],
        ]));
        register_rest_route($ns, $p . '/share/file', array_merge($public, [
            'methods' => 'GET', 'callback' => [$api, 'handleSharePublicRoute'],
        ]));
        register_rest_route($ns, $p . '/intake/info', array_merge($public, [
            'methods' => 'GET', 'callback' => [$api, 'handleIntakePublicRoute'],
        ]));
        register_rest_route($ns, $p . '/intake/upload', array_merge($public, [
            'methods' => 'POST', 'callback' => [$api, 'handleIntakePublicRoute'],
        ]));

        // Gated media stream/img — reached with a per-file signed token in the
        // query string (an <img>/<video> element can't send an Authorization
        // header), same public posture as the share/intake routes above. See
        // FluxFilesApi::handleStream()/handleImg() (ported from index.php's
        // handleMediaStream()/handleImageTransform()).
        register_rest_route($ns, $p . '/stream', array_merge($public, [
            'methods' => 'GET', 'callback' => [$api, 'handleStream'],
        ]));
        register_rest_route($ns, $p . '/img', array_merge($public, [
            'methods' => 'GET', 'callback' => [$api, 'handleImg'],
        ]));

        // Token refresh — mints a fresh JWT for the logged-in WP user (cookie +
        // REST nonce auth, NOT the JWT). The embedded UI's onTokenRefresh hook
        // calls this after the iframe's JWT expires, so a still-logged-in user
        // recovers without a full page reload. It must NOT use `checkAuth`:
        // that rejects an expired Bearer outright, and the whole point here is
        // that the JWT is gone — auth is the WP login session instead.
        register_rest_route($ns, $p . '/token', [
            'methods'             => 'GET',
            'callback'            => [$api, 'handleToken'],
            'permission_callback' => [self::class, 'checkLoggedIn'],
        ]);

        // Attachment bridge: register a picked FluxFiles file as a WP attachment
        // (served from your bucket) so it works in the Media Library / posts / blocks.
        // WP-native auth (must be able to upload media), not the JWT.
        register_rest_route($ns, $p . '/attach', [
            'methods'             => 'POST',
            'callback'            => [$api, 'handleAttach'],
            'permission_callback' => [self::class, 'checkCanUpload'],
        ]);

        // Chunk upload
        register_rest_route($ns, $p . '/chunk/init', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkInit'],
        ]));
        register_rest_route($ns, $p . '/chunk/presign', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkPresign'],
        ]));
        register_rest_route($ns, $p . '/chunk/complete', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkComplete'],
        ]));
        register_rest_route($ns, $p . '/chunk/abort', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkAbort'],
        ]));

        // ---------------------------------------------------------------------
        // UI / asset / locale proxy — public routes so the iframe can load HTML
        // and assets without going through the WP login flow. Auth still applies
        // to every /api/fm/ data route above.
        // ---------------------------------------------------------------------
        $publicArgs = ['methods' => 'GET', 'permission_callback' => '__return_true'];

        register_rest_route($ns, '/public/index.html', array_merge($publicArgs, [
            'callback' => [$api, 'serveUiHtml'],
        ]));
        register_rest_route($ns, '/assets/(?P<file>fm\.(?:js|css))', array_merge($publicArgs, [
            'callback' => [$api, 'serveUiAsset'],
        ]));
        register_rest_route($ns, $p . '/lang', array_merge($publicArgs, [
            'callback' => [$api, 'serveLangList'],
        ]));
        register_rest_route($ns, $p . '/lang/(?P<locale>[a-z]{2,5})', array_merge($publicArgs, [
            'callback' => [$api, 'serveLangMessages'],
        ]));
    }

    /**
     * Permission callback — accept either:
     *   1. A valid Bearer JWT (signed with FLUXFILES_SECRET), used by fm.js
     *      from inside the iframe.
     *   2. A logged-in WP user with a valid REST nonce (X-WP-Nonce header),
     *      used when the wrapping page calls our routes through wp.apiFetch.
     */
    public static function checkAuth(\WP_REST_Request $request): bool
    {
        // get_header() lowercases names, but some servers strip the
        // Authorization header before PHP sees it (Apache + suexec). Fall back
        // to common server vars that mod_rewrite preserves.
        $auth = $request->get_header('authorization')
            ?: ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
            ?: ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $secret = get_option('fluxfiles_secret', '');
            if ($secret === '') {
                error_log('FluxFiles: rejecting Bearer token — fluxfiles_secret option is empty');
                return false;
            }
            try {
                JwtMiddleware::handle($m[1], $secret);
                return true;
            } catch (\Throwable $e) {
                error_log('FluxFiles: Bearer rejected — ' . $e->getMessage());
                return false;
            }
        }
        return is_user_logged_in();
    }

    /**
     * Permission callback for the token-refresh route: the logged-in WP session
     * only (cookie + REST nonce). Deliberately does NOT accept a Bearer JWT —
     * the refresh route exists precisely because the JWT has expired.
     */
    public static function checkLoggedIn(): bool
    {
        return is_user_logged_in();
    }

    /** Attachment creation requires the WP media-upload capability. */
    public static function checkCanUpload(): bool
    {
        return is_user_logged_in() && current_user_can('upload_files');
    }

    /**
     * Register a picked FluxFiles file as a WP attachment (idempotent) and return its
     * id + rewritten URL. Body: {url, key?, disk?, name?, mime?, width?, height?}.
     */
    public function handleAttach(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params() ?: $request->get_params();
        if (empty($body['url'])) {
            return $this->error('A file url is required', 400);
        }
        try {
            $res = FluxFilesAttachments::findOrCreate([
                'url'      => (string) $body['url'],
                'key'      => (string) ($body['key'] ?? ($body['path'] ?? '')),
                'disk'     => (string) ($body['disk'] ?? 'local'),
                'name'     => (string) ($body['name'] ?? ($body['basename'] ?? '')),
                'basename' => (string) ($body['basename'] ?? ($body['name'] ?? '')),
                'mime'     => (string) ($body['mime'] ?? ''),
                'width'    => (int) ($body['width'] ?? 0),
                'height'   => (int) ($body['height'] ?? 0),
                'alt'      => (string) ($body['alt'] ?? ''),
                'caption'  => (string) ($body['caption'] ?? ''),
                'variants' => is_array($body['variants'] ?? null) ? $body['variants'] : null,
            ]);
            return $this->ok($res);
        } catch (\Throwable $e) {
            return $this->error('Attach failed: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Mint a FRESH JWT for the logged-in WP user. Called by the embedded UI's
     * onTokenRefresh hook (via the shortcode/media-button wiring) so a session-
     * valid user recovers from an expired token without a page reload. If the WP
     * session is also gone the permission_callback 401s first, and the UI shows
     * its auth screen.
     */
    public function handleToken(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            return $this->ok(['token' => FluxFilesPlugin::tokenForCurrentUser()]);
        } catch (\Throwable $e) {
            return $this->error('Unable to refresh token', 401);
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Resolve JWT claims for the current request. Prefer the Bearer token sent
     * by the iframe (so request scope / permissions match what was minted at
     * shortcode render time) and fall back to a freshly-minted token tied to
     * the logged-in WP user when no Bearer is present (REST consumers using
     * cookie+nonce).
     */
    private function claims(): \FluxFiles\Claims
    {
        $secret = get_option('fluxfiles_secret', '');
        $auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return JwtMiddleware::handle($m[1], $secret);
        }
        return JwtMiddleware::handle(FluxFilesPlugin::tokenForCurrentUser(), $secret);
    }

    private function fileManager(\FluxFiles\Claims $claims): FileManager
    {
        foreach ($claims->byobDisks as $byobName => $byobConfig) {
            $this->diskManager->registerByobDisk($byobName, $byobConfig);
        }
        $fm = new FileManager($this->diskManager, $claims, $this->metaRepo);
        $fm->setQuotaManager(new QuotaManager($this->diskManager));

        // Turns on isGatedLocal()/gatedLocalUrl()/imgBaseUrl() inside list() —
        // unconditional, same as index.php. Without this, list() never emits
        // img_base/gatedLocal URLs even though stream/img are now routed.
        $fm->setStreamSecret((string) get_option('fluxfiles_secret', ''));

        // WordPress's REST API only ever resolves under /wp-json/{namespace}/… — never
        // at a bare root path the way standalone's/Laravel's /api/fm/* really is at the
        // iframe's own origin. Without this, list() would mint bare `/api/fm/stream`
        // URLs that 404 in the browser (they'd need the wp-json/fluxfiles/v1 prefix
        // handleStream()/handleImg() are actually registered under — see registerRoutes()'s
        // $ns/$p literals, which this must match).
        $fm->setApiBasePath(rest_url('fluxfiles/v1') . '/api/fm');

        // On-upload auto-optimize (FREE/core). Wire the optimizer hook when the token
        // asks for it (`auto_optimize`) — mirrors index.php's wiring. The module does
        // the work; FileManager records the savings + renames to .webp.
        if ($claims->autoOptimize ?? false) {
            $optimizeModule = new \FluxFiles\OptimizeModule();
            $fm->setUploadOptimizer(static function (string $bytes, int $quality) use ($optimizeModule) {
                return $optimizeModule->optimizeBytes($bytes, $quality);
            });
        }

        // On-upload AI auto-tag. Wired only when the token asks for it (`ai_auto_tag`)
        // AND an AI provider is configured server-side — without one this stays a
        // no-op. FileManager::upload() re-checks $this->aiTagger !== null itself
        // before calling analyze(), the same gate the manual ai-tag action uses.
        if ($claims->aiAutoTag) {
            $aiProvider = get_option('fluxfiles_ai_provider', '');
            if (!empty($aiProvider)) {
                $fm->setAiTagger(new \FluxFiles\AiTagger(
                    $aiProvider,
                    get_option('fluxfiles_ai_api_key', ''),
                    get_option('fluxfiles_ai_model', '') ?: null,
                    get_option('fluxfiles_ai_base_url', '') ?: null
                ));
            }
        }

        // File versioning (paid module). Wire the version keeper ONLY when the token
        // asks (`allow_versioning`) AND the module is installed + licensed — so the
        // free core keeps no versions. FileManager calls it before overwriting an
        // existing file. Mirrors index.php's wiring.
        if (($claims->allowVersioning ?? false)
            && \FluxFiles\ModuleRegistry::installed('versioning')
            && FluxFilesPlugin::license()->licensed('versioning')) {
            $versioning = new \FluxFiles\Versioning\VersioningModule();
            $diskManager = $this->diskManager;
            $fm->setVersionKeeper(static function (string $d, string $key, $fs) use ($versioning, $claims, $diskManager) {
                $versioning->keep($fs, $key, $claims, $diskManager, $d);
            });
            // Erase version history on permanent delete, so `/delete` can't be undone
            // via a restore of a version saved before it — see VersioningModule::purge().
            $fm->setVersionPurger(static function (string $d, string $key, $fs) use ($versioning, $diskManager) {
                $versioning->purge($fs, $key, $diskManager, $d);
            });
        }

        // Virus scan (paid module) — this REST proxy handles /upload itself, so without
        // the wiring a tenant with `allow_virus_scan` would get unscanned files here
        // while the same token is scanned in standalone. The module gate resolves INSIDE
        // the callback so reads are unaffected and a missing/unlicensed module surfaces
        // as 501/402/403 on the upload rather than silently skipping the scan.
        if (($claims->allowVirusScan ?? false)) {
            $fm->setVirusScanner(static function (string $localPath) use ($claims): array {
                /** @var \FluxFiles\Virus\VirusScanModule $virus */
                $virus = \FluxFiles\ModuleRegistry::require('virus', FluxFilesPlugin::license(), $claims);
                return $virus->scanPath($localPath);
            });
        }
        return $fm;
    }

    private function rateLimit(\FluxFiles\Claims $claims, bool $isWrite): void
    {
        $storagePath = FluxFilesPlugin::storagePath();
        // Per-tenant `rate_read`/`rate_write` claims override the server defaults.
        // `?? 0` tolerates a core older than 0.2.8 (property absent) — degrade to the
        // RateLimiterFileStorage default rather than warn/fatal on a version mismatch.
        $readLimit  = ($claims->rateRead ?? 0) > 0 ? $claims->rateRead : 60;
        $writeLimit = ($claims->rateWrite ?? 0) > 0 ? $claims->rateWrite : 10;
        $rateLimiter = new RateLimiterFileStorage($storagePath . '/rate_limit.json', $readLimit, $writeLimit);
        $rateLimiter->check($claims->userId, $isWrite ? 'write' : 'read');
    }

    private function logAudit(\FluxFiles\Claims $claims, string $action, string $disk, string $key, ?string $detail = null): void
    {
        $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);
        $audit->log($claims->userId, $action, $disk, $key, null, null, $detail);
    }

    /**
     * Fire a webhook for a file-changing action (paid module). Gated the same
     * 3-layer way index.php's inline wiring does it (installed + licensed +
     * `allow_webhooks` + a configured `webhook_url`) — best-effort, never throws
     * into the request path (WebhooksModule::dispatch swallows its own failures).
     *
     * @param array<string,mixed> $context
     */
    private function dispatchWebhook(\FluxFiles\Claims $claims, string $event, array $context): void
    {
        if (!$claims->allowWebhooks || $claims->webhookUrl === ''
            || !\FluxFiles\ModuleRegistry::installed('webhooks')
            || !FluxFilesPlugin::license()->licensed('webhooks')) {
            return;
        }
        $secret = (string) get_option('fluxfiles_secret', '');
        (new \FluxFiles\Webhooks\WebhooksModule())->dispatch($claims, $secret, $event, $context);
    }

    /**
     * @param mixed $data
     */
    private function ok($data): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $data, 'error' => null], 200);
    }

    private function error(string $message, int $status = 400, ?string $code = null, array $params = []): \WP_REST_Response
    {
        // Forward the core's error_code + error_params so the embedded UI can show a
        // LOCALISED message (it maps `error.<code>` via i18n). Without these the UI
        // falls back to the raw English message — the whole point of this passthrough.
        $resp = ['data' => null, 'error' => $message];
        if ($code !== null) {
            $resp['error_code'] = $code;
        }
        if ($params !== []) {
            $resp['error_params'] = $params;
        }
        return new \WP_REST_Response($resp, $status);
    }

    /**
     * Get JSON body from request.
     */
    private function body(\WP_REST_Request $request): array
    {
        return $request->get_json_params() ?: [];
    }

    // -------------------------------------------------------------------------
    // Route handlers
    // -------------------------------------------------------------------------

    public function handleList(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->list(
                $request->get_param('disk') ?? 'local',
                $request->get_param('path') ?? '',
                max(0, (int) ($request->get_param('limit') ?? 0)),
                (string) ($request->get_param('cursor') ?? '')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleUpload(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $files = $request->get_file_params();
            if (empty($files['file'])) {
                throw new ApiException('No file uploaded', 400);
            }

            $result = $fm->upload(
                $request->get_param('disk') ?? 'local',
                $request->get_param('path') ?? '',
                $files['file'],
                (bool) ($request->get_param('force_upload') ?? false)
            );

            $this->logAudit(
                $claims,
                'upload',
                $request->get_param('disk') ?? 'local',
                $request->get_param('path') ?? ''
            );
            $this->dispatchWebhook($claims, 'upload', [
                'disk' => $request->get_param('disk') ?? 'local',
                'path' => $request->get_param('path') ?? '',
                'name' => (string) ($result['name'] ?? basename((string) ($request->get_param('path') ?? ''))),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleDelete(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

            if (!$disk || !$path) {
                throw new ApiException('Missing required field: disk or path', 400);
            }

            $result = $fm->delete($disk, $path);
            $this->logAudit($claims, 'delete', $disk, $path);
            $this->dispatchWebhook($claims, 'delete', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleRename(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;
            $name = $body['name'] ?? null;

            if (!$disk || !$path || !$name) {
                throw new ApiException('Missing required field: disk, path or name', 400);
            }

            $result = $fm->rename($disk, $path, $name);
            $this->logAudit($claims, 'rename', $disk, $path);
            $this->dispatchWebhook($claims, 'rename', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleMove(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $from = $body['from'] ?? null;
            $to   = $body['to'] ?? null;

            if (!$disk || !$from || !$to) {
                throw new ApiException('Missing required field: disk, from, or to', 400);
            }

            $result = $fm->move($disk, $from, $to);
            $this->logAudit($claims, 'move', $disk, $from);
            $this->dispatchWebhook($claims, 'move', ['disk' => $disk, 'path' => $from, 'name' => basename($from)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleCopy(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $from = $body['from'] ?? null;
            $to   = $body['to'] ?? null;

            if (!$disk || !$from || !$to) {
                throw new ApiException('Missing required field: disk, from, or to', 400);
            }

            $result = $fm->copy($disk, $from, $to);
            $this->logAudit($claims, 'copy', $disk, $from);
            $this->dispatchWebhook($claims, 'copy', ['disk' => $disk, 'path' => $from, 'name' => basename($from)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleMkdir(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

            if (!$disk || !$path) {
                throw new ApiException('Missing required field: disk or path', 400);
            }

            $result = $fm->mkdir($disk, $path);
            $this->logAudit($claims, 'mkdir', $disk, $path);
            $this->dispatchWebhook($claims, 'mkdir', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleImportUrl(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $url = (string) ($body['url'] ?? '');
            if ($url === '') {
                throw new ApiException('Missing required field: url', 400, 'missing_param');
            }

            $disk = (string) ($body['disk'] ?? 'local');
            $result = (new UrlImporter($claims, $fm))->import($disk, $url, [
                'path'      => (string) ($body['path'] ?? ''),
                'filename'  => isset($body['filename']) ? (string) $body['filename'] : null,
                'overwrite' => filter_var($body['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
            $this->logAudit($claims, 'url_import', $disk, (string) ($result['key'] ?? ''));
            $this->dispatchWebhook($claims, 'url_import', [
                'disk' => $disk,
                'path' => (string) ($result['key'] ?? ''),
                'name' => basename((string) ($result['key'] ?? '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleCrossCopy(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body    = $this->body($request);
            $srcDisk = $body['src_disk'] ?? null;
            $srcPath = $body['src_path'] ?? null;
            $dstDisk = $body['dst_disk'] ?? null;
            $dstPath = $body['dst_path'] ?? null;

            if (!$srcDisk || !$srcPath || !$dstDisk || !$dstPath) {
                throw new ApiException('Missing required fields: src_disk, src_path, dst_disk, dst_path', 400);
            }

            $result = $fm->crossCopy($srcDisk, $srcPath, $dstDisk, $dstPath);
            $this->logAudit($claims, 'cross_copy', $srcDisk, $srcPath);
            $this->dispatchWebhook($claims, 'cross_copy', ['disk' => $srcDisk, 'path' => $srcPath, 'name' => basename($srcPath)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleCrossMove(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body    = $this->body($request);
            $srcDisk = $body['src_disk'] ?? null;
            $srcPath = $body['src_path'] ?? null;
            $dstDisk = $body['dst_disk'] ?? null;
            $dstPath = $body['dst_path'] ?? null;

            if (!$srcDisk || !$srcPath || !$dstDisk || !$dstPath) {
                throw new ApiException('Missing required fields: src_disk, src_path, dst_disk, dst_path', 400);
            }

            $result = $fm->crossMove($srcDisk, $srcPath, $dstDisk, $dstPath);
            $this->logAudit($claims, 'cross_move', $srcDisk, $srcPath);
            $this->dispatchWebhook($claims, 'cross_move', ['disk' => $srcDisk, 'path' => $srcPath, 'name' => basename($srcPath)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleCrop(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body   = $this->body($request);
            $disk   = $body['disk'] ?? null;
            $path   = $body['path'] ?? null;
            $x      = $body['x'] ?? null;
            $y      = $body['y'] ?? null;
            $width  = $body['width'] ?? null;
            $height = $body['height'] ?? null;

            if (!$disk || !$path || $x === null || $y === null || !$width || !$height) {
                throw new ApiException('Missing required fields: disk, path, x, y, width, height', 400);
            }

            $result = $fm->cropImage(
                $disk,
                $path,
                (int) $x,
                (int) $y,
                (int) $width,
                (int) $height,
                $body['save_path'] ?? null
            );
            $this->logAudit($claims, 'crop', $disk, $path);
            $this->dispatchWebhook($claims, 'crop', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleWatermark(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $path = (string) ($body['path'] ?? '');
            if ($path === '') {
                throw new ApiException('Missing path', 400);
            }
            $wm = [
                'type'      => ($body['type'] ?? 'logo') === 'text' ? 'text' : 'logo',
                'text'      => (string) ($body['text'] ?? ''),
                'x'         => (float) ($body['x'] ?? 0.7),
                'y'         => (float) ($body['y'] ?? 0.85),
                'scale'     => (float) ($body['scale'] ?? 0.25),
                'opacity'   => (float) ($body['opacity'] ?? 0.6),
                'font_size' => (int) ($body['font_size'] ?? 24),
                'color'     => (string) ($body['color'] ?? '#ffffff'),
            ];
            if (!empty($body['logo_data'])) {
                $b64 = preg_replace('#^data:[^,]+,#', '', (string) $body['logo_data']);
                $bin = base64_decode((string) $b64, true);
                if ($bin === false || $bin === '') {
                    throw new ApiException('Invalid logo data', 400);
                }
                $wm['logo_data'] = $bin;
            }
            $dest = isset($body['dest']) && $body['dest'] !== '' ? (string) $body['dest'] : null;
            $result = $fm->applyWatermark((string) ($body['disk'] ?? 'local'), $path, $wm, $dest);
            $this->logAudit($claims, 'watermark', (string) ($body['disk'] ?? 'local'), $path);
            $this->dispatchWebhook($claims, 'watermark', ['disk' => (string) ($body['disk'] ?? 'local'), 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleWatermarkRemove(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $path = (string) ($body['path'] ?? '');
            if ($path === '') {
                throw new ApiException('Missing path', 400);
            }
            $disk = (string) ($body['disk'] ?? 'local');
            $result = $fm->removeWatermark($disk, $path);
            $this->logAudit($claims, 'watermark_remove', $disk, $path);
            $this->dispatchWebhook($claims, 'watermark_remove', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleAiTag(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $aiProvider = get_option('fluxfiles_ai_provider', '');
            if (empty($aiProvider)) {
                throw new ApiException('AI tagging is not configured', 400);
            }

            $aiTagger = new \FluxFiles\AiTagger(
                $aiProvider,
                get_option('fluxfiles_ai_api_key', ''),
                get_option('fluxfiles_ai_model', '') ?: null,
                // 4th arg — cores older than this plugin's floor ignore it (PHP drops
                // extra arguments to a userland function), so the base-URL override is
                // additive and needs no constraint bump.
                get_option('fluxfiles_ai_base_url', '') ?: null
            );
            $fm->setAiTagger($aiTagger);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields: disk, path', 400);
            }

            $result = $fm->aiTag($disk, $path);
            $this->logAudit($claims, 'ai_tag', $disk, $path);
            $this->dispatchWebhook($claims, 'ai_tag', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handlePresign(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $body   = $this->body($request);
            $disk   = $body['disk'] ?? null;
            $path   = $body['path'] ?? null;
            $method = $body['method'] ?? null;
            $ttl    = $body['ttl'] ?? null;

            if (!$disk || !$path || !$method || !$ttl) {
                throw new ApiException('Missing required fields', 400);
            }

            return $this->ok($fm->presign($disk, $path, $method, $ttl));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleMeta(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $disk = $request->get_param('disk') ?? 'local';
            $path = $request->get_param('path');

            if (!$path) {
                throw new ApiException('Missing path parameter', 400);
            }

            return $this->ok($fm->fileMeta($disk, $path));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleGetMetadata(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk = $request->get_param('disk');
            $key  = $request->get_param('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key parameter', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }

            return $this->ok($this->metaRepo->get($disk, $key));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleSaveMetadata(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $key  = $body['key'] ?? null;

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }

            $data = [
                'title'    => $body['title'] ?? null,
                'alt_text' => $body['alt_text'] ?? null,
                'caption'  => $body['caption'] ?? null,
                'tags'     => $body['tags'] ?? null,
            ];

            $this->metaRepo->save($disk, $key, $data);
            $this->metaRepo->syncToS3Tags($disk, $key, $data, $this->diskManager);
            $this->logAudit($claims, 'metadata_update', $disk, $key);
            $this->dispatchWebhook($claims, 'metadata_update', ['disk' => $disk, 'path' => $key, 'name' => basename($key)]);

            return $this->ok(['saved' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleDeleteMetadata(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $key  = $body['key'] ?? null;

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }

            $this->metaRepo->delete($disk, $key);

            return $this->ok(['deleted' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Search

    public function handleSearch(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk  = $request->get_param('disk') ?? 'local';
            $query = $request->get_param('q');

            if (!$query) {
                throw new ApiException('Missing search query', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403);
            }

            return $this->ok($this->metaRepo->search(
                $disk,
                $query,
                (int) ($request->get_param('limit') ?? 50),
                $claims->pathPrefix,
                $claims->showHidden
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleSearchFolders(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk  = $request->get_param('disk') ?? 'local';
            $query = $request->get_param('q');

            if (!$query) {
                throw new ApiException('Missing search query', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403);
            }

            return $this->ok($this->metaRepo->searchFolders(
                $disk,
                $query,
                (int) ($request->get_param('limit') ?? 50),
                $claims->pathPrefix,
                $claims->showHidden
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Quota

    // Config / code editor — disk/perm/scope/size/binary checks live inside
    // FileManager::getContent / putContent (single source of truth).

    public function handleGetContent(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->getContent(
                $request->get_param('disk') ?? 'local',
                (string) ($request->get_param('path') ?? '')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handlePutContent(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $result = $fm->putContent(
                (string) ($body['disk'] ?? 'local'),
                (string) ($body['path'] ?? ''),
                (string) ($body['content'] ?? '')
            );
            $this->logAudit($claims, 'content_edit', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'content_edit', [
                'disk' => (string) ($body['disk'] ?? 'local'),
                'path' => (string) ($body['path'] ?? ''),
                'name' => basename((string) ($body['path'] ?? '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Extract a zip in place — slip/bomb/quota/dangerous-ext guards live inside
    // FileManager::extractZip.

    public function handleExtract(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $result = $fm->extractZip(
                (string) ($body['disk'] ?? 'local'),
                (string) ($body['path'] ?? ''),
                isset($body['dest']) ? (string) $body['dest'] : null
            );
            $this->logAudit($claims, 'extract', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'extract', [
                'disk' => (string) ($body['disk'] ?? 'local'),
                'path' => (string) ($body['path'] ?? ''),
                'name' => basename((string) ($body['path'] ?? '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Trash (soft-delete) — gated by the 'delete' permission inside FileManager

    public function handleTrash(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;
            if (!$disk || $path === null) {
                throw new ApiException('Missing required field: disk or path', 400, 'missing_param');
            }

            $result = $fm->trash((string) $disk, (string) $path);
            $this->logAudit($claims, 'trash', (string) $disk, (string) $path);
            $this->dispatchWebhook($claims, 'trash', ['disk' => (string) $disk, 'path' => (string) $path, 'name' => basename((string) $path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleTrashRestore(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $trashId = $body['trash_id'] ?? null;
            if (!$disk || !$trashId) {
                throw new ApiException('Missing required field: disk/trash_id', 400, 'missing_param');
            }

            $result = $fm->restore((string) $disk, (string) $trashId, $body['path'] ?? null);
            $this->logAudit($claims, 'restore', (string) $disk, (string) $trashId);
            $this->dispatchWebhook($claims, 'restore', ['disk' => (string) $disk, 'path' => (string) $trashId, 'name' => basename((string) $trashId)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleTrashList(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->listTrash((string) ($request->get_param('disk') ?? 'local')));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleTrashPurge(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $trashId = $body['trash_id'] ?? null;
            if (!$disk || !$trashId) {
                throw new ApiException('Missing required field: disk/trash_id', 400, 'missing_param');
            }

            $result = $fm->purgeTrash((string) $disk, (string) $trashId);
            $this->logAudit($claims, 'purge', (string) $disk, (string) $trashId);
            $this->dispatchWebhook($claims, 'purge', ['disk' => (string) $disk, 'path' => (string) $trashId, 'name' => basename((string) $trashId)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleTrashEmpty(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            if (!$disk) {
                throw new ApiException('Missing required field: disk', 400, 'missing_param');
            }

            $result = $fm->emptyTrash((string) $disk);
            $this->logAudit($claims, 'empty_trash', (string) $disk, '');
            $this->dispatchWebhook($claims, 'empty_trash', ['disk' => (string) $disk, 'path' => '', 'name' => '']);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Bucket Doctor — diagnose a disk backend (writes/deletes a probe object,
    // so it requires the 'write' permission on a disk the token may access).

    public function handleDiskDoctor(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            // Build the FileManager so any BYOB disks in the token are registered
            // on the DiskManager before BucketDoctor probes them.
            $this->fileManager($claims);

            $disk = (string) ($request->get_param('disk') ?: 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $origin = $request->get_header('origin') ?: $request->get_param('origin');

            return $this->ok((new BucketDoctor($this->diskManager))->diagnose($disk, $origin ?: null));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Storage usage dashboard: quota + per-type/-folder breakdown (one
     * listContents pass via getUsageBreakdown). Proxy mode recomputes each call
     * (no cache layer here); the standalone core endpoint adds the file cache.
     */
    public function handleUsage(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk = (string) ($request->get_param('disk') ?? 'local');
            $quotaManager = new QuotaManager($this->diskManager);
            $top = $claims->usageTopFoldersCount > 0 ? $claims->usageTopFoldersCount : 10;
            $depth = $claims->usageFolderDepth > 0 ? $claims->usageFolderDepth : 1;

            $breakdown = $quotaManager->getUsageBreakdown($disk, $claims->pathPrefix, $top, $depth);
            $resp = $quotaManager->usageResponse(
                $breakdown,
                $claims->maxStorageMb,
                $claims->usageWarningThreshold,
                $claims->usageCriticalThreshold
            );
            $resp['cache_age_seconds'] = 0;

            return $this->ok($resp);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleLicense(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $this->rateLimit($this->claims(), false);

            return $this->ok(FluxFilesPlugin::license()->info());
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Optimization (paid module) — 3-layer gate inside ModuleRegistry (installed
     * 501 + licensed 402 + allow_optimize 403). Free hosts → 501.
     */
    // ── Share + Intake ──────────────────────────────────────────────────────

    /**
     * Operator routes. The paid module does the work; this only supplies WordPress's
     * FileManager and the recipient link base, and it never touches the returned
     * token — that is shown once and never stored, exactly as in standalone.
     */
    private function shareIntake(\WP_REST_Request $request, string $module, string $op): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, !in_array($op, ['list', 'analytics'], true));
            $mod = \FluxFiles\ModuleRegistry::require($module, FluxFilesPlugin::license(), $claims);
            $secret = (string) get_option('fluxfiles_secret', '');
            $disk = (string) ($request->get_param('disk') ?: 'local');

            if ($op === 'list') {
                return $this->ok($module === 'share'
                    ? $mod->listShares($this->diskManager, $claims, $disk)
                    : $mod->listPortals($this->diskManager, $claims, $disk));
            }
            if ($op === 'analytics') {
                $event = $request->get_param('event');
                return $this->ok($mod->analytics(
                    $this->diskManager,
                    $claims,
                    $disk,
                    (string) ($request->get_param('jti') ?: ''),
                    max(1, min(500, (int) ($request->get_param('limit') ?: 100))),
                    max(0, (int) ($request->get_param('offset') ?: 0)),
                    $event !== null && $event !== '' ? (string) $event : null
                ));
            }
            $body = $this->body($request);
            if ($op === 'revoke') {
                $jti = (string) ($body['jti'] ?? '');
                return $this->ok($module === 'share'
                    ? $mod->revokeShare($this->diskManager, $claims, $disk, $jti)
                    : $mod->revokePortal($this->diskManager, $claims, $disk, $jti));
            }

            $fm = $this->fileManager($claims);
            $res = $module === 'share'
                ? $mod->createShare($fm, $this->diskManager, $claims, $secret, $body)
                : $mod->createPortal($fm, $this->diskManager, $claims, $secret, $body);

            // The recipient URL. The module builds it when the token carries a base
            // URL; otherwise point at the page this plugin serves — a WordPress site
            // has no /public/share.html at its root the way standalone does.
            if (empty($res['url']) && !empty($res['token'])) {
                $res['url'] = FluxFilesPlugin::publicLinkUrl(
                    $module === 'share' ? 'share.html' : 'intake.html',
                    (string) $res['token']
                );
            }
            return $this->ok($res);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleShareCreate(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'share', 'create'); }
    public function handleShareList(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'share', 'list'); }
    public function handleShareRevoke(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'share', 'revoke'); }
    public function handleShareAnalytics(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'share', 'analytics'); }
    public function handleIntakeCreate(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'intake', 'create'); }
    public function handleIntakeList(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'intake', 'list'); }
    public function handleIntakeRevoke(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'intake', 'revoke'); }
    public function handleIntakeAnalytics(\WP_REST_Request $r): \WP_REST_Response { return $this->shareIntake($r, 'intake', 'analytics'); }

    /**
     * The PUBLIC recipient routes, delegated to the SAME core handlers standalone uses.
     *
     * Nothing about tokens, expiry, passwords, download caps or byte-sending is
     * reimplemented here — a second copy of that is how a hole appears on one platform
     * only. This supplies WordPress's DiskManager (its uploads live under wp-content,
     * not under the core directory, so the default would resolve against a directory
     * holding none of the files) and then gets out of the way.
     *
     * The core handler writes its own response and exits, which is why this returns
     * nothing meaningful: WP's REST envelope must not wrap a 302 redirect or a byte
     * stream. `$_GET`/`$_POST` are already populated by PHP for these requests.
     */
    private function publicLink(string $which, \WP_REST_Request $request): void
    {
        $apiDir = FluxFilesPlugin::corePath('api');
        if ($apiDir === null || !is_file($apiDir . '/PublicLinks.php')) {
            // Bundled core missing — say so rather than fataling on a require.
            status_header(501);
            wp_send_json(['data' => null, 'error' => 'The FluxFiles core is not available',
                'error_code' => 'core_missing'], 501);
        }
        require_once $apiDir . '/PublicLinks.php';
        $method = $request->get_method();
        $uri = '/api/fm/' . $which . '/' . basename((string) $request->get_route());

        // The core handler reads the signing secret and the storage path from $_ENV.
        $_ENV['FLUXFILES_SECRET'] = (string) get_option('fluxfiles_secret', '');
        $_ENV['FLUXFILES_STORAGE_PATH'] = FluxFilesPlugin::storagePath();

        if ($which === 'share') {
            handleSharePublic($method, $uri, $this->diskManager, FluxFilesPlugin::diskConfigs());
        } else {
            handleIntakePublic($method, $uri, $this->diskManager, FluxFilesPlugin::diskConfigs());
        }
        exit;   // the core handler has already sent status, headers and body
    }

    public function handleSharePublicRoute(\WP_REST_Request $r): void { $this->publicLink('share', $r); }
    public function handleIntakePublicRoute(\WP_REST_Request $r): void { $this->publicLink('intake', $r); }

    public function handleOptimize(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('optimize', FluxFilesPlugin::license(), $claims);
            $body = $this->body($request);
            $result = $module->run($fm, $this->diskManager, new \FluxFiles\ImageOptimizer(), $claims, $body);
            $this->logAudit($claims, 'optimize', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'optimize', [
                'disk' => (string) ($body['disk'] ?? 'local'),
                'path' => (string) ($body['path'] ?? ''),
                'name' => basename((string) ($body['path'] ?? '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Webhooks (paid module) — send a test ping to the configured endpoint so
     * operators can verify it. Same 3-layer gate as ModuleRegistry::require().
     */
    public function handleWebhooksTest(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            $module = \FluxFiles\ModuleRegistry::require('webhooks', FluxFilesPlugin::license(), $claims);
            $result = $module->test($claims, (string) get_option('fluxfiles_secret', ''));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * File versioning (paid module) — list prior versions of a file / restore one.
     * Same 3-layer gate as ModuleRegistry::require(). The version-keeping/purging
     * hooks that make listVersions()/restore() meaningful are wired in
     * fileManager() above, not here.
     */
    public function handleVersions(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $module = \FluxFiles\ModuleRegistry::require('versioning', FluxFilesPlugin::license(), $claims);
            $fm = $this->fileManager($claims);
            $result = $module->listVersions(
                $fm,
                $this->diskManager,
                $claims,
                (string) ($request->get_param('disk') ?: 'local'),
                (string) ($request->get_param('path') ?: '')
            );

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleVersionsRestore(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            $module = \FluxFiles\ModuleRegistry::require('versioning', FluxFilesPlugin::license(), $claims);
            $body = $this->body($request);
            $fm = $this->fileManager($claims);
            $result = $module->restore(
                $fm,
                $this->diskManager,
                $claims,
                (string) ($body['disk'] ?? 'local'),
                (string) ($body['path'] ?? ''),
                (string) ($body['version_id'] ?? '')
            );

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * AI Vision (paid module) — bg-remove/upscale/smart-crop. Same 3-layer gate
     * as handleOptimize() above; also needs a fresh ImageOptimizer, for the same reason.
     */
    public function handleAiVision(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('ai', FluxFilesPlugin::license(), $claims);
            $body = $this->body($request);
            $result = $module->run($fm, $this->diskManager, new \FluxFiles\ImageOptimizer(), $claims, $body);
            $this->logAudit($claims, 'ai_vision', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'ai_vision', [
                'disk' => (string) ($body['disk'] ?? 'local'),
                'path' => (string) ($body['path'] ?? ''),
                'name' => basename((string) ($body['path'] ?? '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * OCR (paid module) — text extraction; result is returned, never persisted.
     */
    public function handleOcr(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('ocr', FluxFilesPlugin::license(), $claims);
            $result = $module->run($fm, $this->diskManager, $claims, $this->body($request));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Backup Bridge (paid module) — one-way subtree sync between disks.
     */
    public function handleBackup(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('backup', FluxFilesPlugin::license(), $claims);
            $body = $this->body($request);
            $result = $module->run($fm, $this->diskManager, $claims, $body);
            $this->logAudit($claims, 'backup', (string) ($body['from_disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'backup', [
                'disk' => (string) ($body['from_disk'] ?? 'local'),
                'path' => (string) ($body['path'] ?? ''),
                'name' => basename((string) ($body['path'] ?? '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * C2PA content provenance (paid module) — verify a file's manifest (read-only).
     */
    public function handleC2pa(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('c2pa', FluxFilesPlugin::license(), $claims);
            $result = $module->verify($fm, $this->diskManager, $claims, $this->body($request));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * C2PA content provenance (paid module) — sign a file, producing a manifest.
     */
    public function handleC2paSign(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('c2pa', FluxFilesPlugin::license(), $claims);
            $body = $this->body($request);
            $result = $module->sign($fm, $this->diskManager, $claims, $body);
            $this->logAudit($claims, 'c2pa_sign', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'c2pa_sign', [
                'disk' => (string) ($body['disk'] ?? 'local'),
                'path' => (string) ($body['path'] ?? ''),
                'name' => basename((string) ($body['path'] ?? '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * SSH terminal (command-runner) — SFTP disks only. Free/core, not a
     * ModuleRegistry module. Mirrors index.php's /api/fm/terminal gate order
     * exactly: server kill-switch, allow_terminal claim, write perm, disk ACL,
     * driver check, dangerous-command double-confirm, then run.
     */
    public function handleTerminal(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            if ((getenv('FLUXFILES_TERMINAL_DISABLED') ?: '') === 'true') {
                throw new ApiException('The terminal is disabled on this server', 403, 'terminal_disabled');
            }
            $claims = $this->claims();
            if (!$claims->allowTerminal) {
                throw new ApiException('Terminal access is not allowed', 403, 'terminal_forbidden');
            }
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
            }
            $body = $this->body($request);
            $disk = (string) ($body['disk'] ?? '');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (($this->diskManager->config($disk)['driver'] ?? '') !== 'sftp') {
                throw new ApiException('The terminal only works on an SFTP disk', 400, 'terminal_unsupported');
            }
            $cmd = trim((string) ($body['cmd'] ?? ''));
            if ($cmd === '') {
                throw new ApiException('Missing command', 400, 'missing_param');
            }
            $confirmOff = (getenv('FLUXFILES_TERMINAL_CONFIRM') ?: '') === 'false';
            if (!$confirmOff && empty($body['confirm']) && \FluxFiles\SshTerminal::isDangerous($cmd)) {
                throw new ApiException('This command looks dangerous — confirm to run it', 409, 'terminal_confirm_required');
            }
            [$conn, $root] = $this->diskManager->sftpConnection($disk);
            $cwd = \FluxFiles\SshTerminal::resolveCwd((string) ($body['cwd'] ?? ''), $root);
            $timeout = (int) (getenv('FLUXFILES_TERMINAL_TIMEOUT') ?: 30);
            $result = \FluxFiles\SshTerminal::run($conn, $cmd, $cwd, $timeout);
            if (empty($result['shell_ok'])) {
                throw new ApiException('This host does not allow a shell (SFTP-only)', 400, 'terminal_no_shell');
            }
            $this->logAudit($claims, 'terminal', $disk, '', $cmd);
            $this->dispatchWebhook($claims, 'terminal', ['disk' => $disk, 'path' => '', 'name' => '']);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // ── Gated media stream / on-demand image transform (free/core) ────────────
    //
    // Both are ported from core's index.php handleMediaStream()/handleImageTransform()
    // almost verbatim, using this plugin's own DiskManager/disk config instead of a
    // fresh one built from core's config/disks.php file. Registered PUBLIC (see
    // registerRoutes()'s `__return_true` block) — the <img>/<video> element carries
    // its own short-lived, single-file StreamToken/ImageToken in the query string,
    // not a WordPress session or the main JWT.
    //
    // Watermark-overlay resolution (ff_resolve_watermark() in index.php) is
    // intentionally NOT ported: ImageToken::mint() only embeds a `wm` scope when
    // `watermark_enabled` is truthy, and this plugin never forwards that claim
    // (see docs/FEATURES.md — overlay preview stays a core-standalone/embed-only
    // feature), so a WordPress-minted ImageToken never carries one.

    private function strParam(\WP_REST_Request $request, string $key, string $default = ''): string
    {
        $v = $request->get_param($key);
        return is_scalar($v) ? (string) $v : $default;
    }

    /** Snap a requested quality to the nearest allowed step (bounds cache variants). */
    private function snapQuality($raw): int
    {
        $allowedSteps = [60, 75, 80, 90];
        $q = (int) $raw;
        if ($q <= 0) {
            return 80;
        }
        $best = 80;
        $bestDiff = PHP_INT_MAX;
        foreach ($allowedSteps as $allowed) {
            $d = abs($allowed - $q);
            if ($d < $bestDiff) {
                $bestDiff = $d;
                $best = $allowed;
            }
        }
        return $best;
    }

    /** Emit image bytes with safe headers; $immutable adds a long-lived cache policy. */
    private function serveBytes(string $data, string $mime, bool $immutable = false): void
    {
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header('Vary: Accept');
        header($immutable
            ? 'Cache-Control: public, max-age=31536000, immutable'
            : 'Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($data));
        echo $data;
    }

    /**
     * Serve one file on a gated (private) local disk, or any SFTP disk (no static
     * URL exists for either), authenticated by a per-file stream token. Honours
     * HTTP Range so a <video>/<audio> can seek. Emits raw bytes, not JSON.
     */
    public function handleStream(\WP_REST_Request $request): void
    {
        $secret = (string) get_option('fluxfiles_secret', '');
        if ($secret === '') {
            status_header(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'FLUXFILES_SECRET is not configured';
            exit;
        }
        try {
            $scope = \FluxFiles\StreamToken::verify($this->strParam($request, 'token'), $secret);
        } catch (ApiException $e) {
            status_header($e->getHttpCode());
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        $disk = $scope['disk'];
        $path = $scope['path'];

        if ($path === '' || strpos($path, "\0") !== false
            || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid path';
            exit;
        }

        $config = $this->diskManager->config($disk);
        $driver = $config['driver'] ?? '';
        $isGatedLocal = $driver === 'local' && !empty($config['private']);
        $isSftp = $driver === 'sftp';
        if (!$isGatedLocal && !$isSftp) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Streaming not available for this disk';
            exit;
        }

        $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
        $inlineOk = (bool) preg_match('#^(video/|audio/|image/(?!svg))|^application/pdf$#', $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode(basename($path)) . '"');

        if ($isGatedLocal) {
            $root = realpath((string) ($config['root'] ?? ''));
            $abs = $root !== false ? realpath($root . '/' . $path) : false;
            if ($root === false || $abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
                status_header(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Not found';
                exit;
            }
            // Production fast path: hand the bytes to nginx (native Range, no PHP copy).
            $xaccel = (string) (getenv('FLUXFILES_XACCEL') ?: '');
            if ($xaccel !== '') {
                header('Content-Type: ' . $mime);
                header('X-Accel-Buffering: no');
                header('X-Accel-Redirect: ' . rtrim($xaccel, '/') . '/' . $path);
                exit;
            }
            \FluxFiles\RangeStreamer::stream($abs, $mime, $request->get_header('range'));
            exit;
        }

        // SFTP: read through Flysystem and stream the bytes — no byte-range support
        // (SFTP can't do it natively), the whole file is sent.
        try {
            $fs = $this->diskManager->disk($disk);
            if (!$fs->fileExists($path)) {
                status_header(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Not found';
                exit;
            }
            header('Content-Type: ' . $mime);
            $stream = $fs->readStream($path);
            while (!feof($stream)) {
                echo fread($stream, 8192);
                @flush();
            }
            if (is_resource($stream)) { fclose($stream); }
        } catch (\Throwable $e) {
            status_header(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Stream failed';
        }
        exit;
    }

    /**
     * Serve an on-demand WebP/AVIF transform of one image, cached in the file's
     * _variants/ directory. Authenticated by an image token (query string).
     */
    public function handleImg(\WP_REST_Request $request): void
    {
        $secret = (string) get_option('fluxfiles_secret', '');
        if ($secret === '') {
            status_header(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'FLUXFILES_SECRET is not configured';
            exit;
        }
        try {
            $scope = \FluxFiles\ImageToken::verify($this->strParam($request, 'token'), $secret);
        } catch (ApiException $e) {
            status_header($e->getHttpCode());
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        $disk = $scope['disk'];
        $path = $scope['path'];
        if ($path === '' || strpos($path, "\0") !== false
            || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid path';
            exit;
        }

        $optimizer = new \FluxFiles\ImageOptimizer();
        if (!$optimizer->isImage($path)) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not an image';
            exit;
        }

        $dpr = (float) ($request->get_param('dpr') ?: 1);
        $dpr = $dpr >= 2.5 ? 3.0 : ($dpr >= 1.5 ? 2.0 : 1.0);

        $maxWidth = $scope['maxWidth'] > 0 ? $scope['maxWidth'] : 2000;
        $reqWidth = (int) round(((int) ($request->get_param('width') ?: 0)) * $dpr);
        $width = $reqWidth > 0 ? min($maxWidth, max(100, (int) round($reqWidth / 100) * 100)) : 0;
        $reqHeight = (int) round(((int) ($request->get_param('height') ?: 0)) * $dpr);
        $height = $reqHeight > 0 ? min($maxWidth, max(100, (int) round($reqHeight / 100) * 100)) : 0;
        $fit = ($request->get_param('fit') === 'cover') ? 'cover' : 'contain';
        $defaultQuality = $scope['defaultQuality'] > 0 ? $scope['defaultQuality'] : 80;
        $quality = $this->snapQuality($request->get_param('quality') ?: $defaultQuality);

        $reqFormat = strtolower($this->strParam($request, 'format', 'auto'));
        $avifOk = $optimizer->avifSupported();
        $accept = (string) ($request->get_header('accept') ?: '');
        if ($reqFormat === 'avif') {
            $format = $avifOk ? 'avif' : 'webp';
        } elseif ($reqFormat === 'webp') {
            $format = 'webp';
        } elseif ($avifOk && strpos($accept, 'image/avif') !== false) {
            $format = 'avif';
        } elseif (strpos($accept, 'image/webp') !== false) {
            $format = 'webp';
        } else {
            $format = ''; // negotiation: client accepts neither modern format
        }

        try {
            $fs = $this->diskManager->disk($disk);
        } catch (\Throwable $e) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Disk not available';
            exit;
        }

        if (!$fs->fileExists($path)) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }

        $origMime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';

        // No watermark scope ever reaches this plugin — see the class-note above.
        if ($format === '') {
            $this->serveBytes((string) $fs->read($path), $origMime);
            exit;
        }

        $ver = (string) (@$fs->lastModified($path) ?: '0');
        $cacheKey = \FluxFiles\ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, '', $format, $height, $fit);
        $outMime = 'image/' . $format;

        if ($fs->fileExists($cacheKey)) {
            // S3/R2: redirect to a presigned URL of the cached image (no app egress).
            if (($this->diskManager->config($disk)['driver'] ?? '') === 's3') {
                $redirect = $this->diskManager->presignGetUrl($disk, $cacheKey, 3600);
                if ($redirect !== null) {
                    header('Cache-Control: private, max-age=600');
                    header('Vary: Accept');
                    status_header(302);
                    header('Location: ' . $redirect);
                    exit;
                }
            }
            $this->serveBytes((string) $fs->read($cacheKey), $outMime, true);
            exit;
        }

        $out = $optimizer->transform((string) $fs->read($path), $width, $quality, null, $format, $height, $fit);
        if ($out === null) {
            // Animated GIF / SVG / non-raster / bomb — serve the original untouched.
            $this->serveBytes((string) $fs->read($path), $origMime);
            exit;
        }

        // Honor the format transform() actually produced (falls back to WebP if an
        // AVIF encode isn't available at runtime) so cache key + Content-Type agree.
        $outFormat = $out['format'] ?? $format;
        if ($outFormat !== $format) {
            $cacheKey = \FluxFiles\ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, '', $outFormat, $height, $fit);
            $outMime = 'image/' . $outFormat;
        }
        try { $fs->write($cacheKey, $out['data']); } catch (\Throwable $e) { /* best-effort cache */ }
        $this->serveBytes($out['data'], $outMime, true);
        exit;
    }

    public function handleQuota(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $quotaManager = new QuotaManager($this->diskManager);

            return $this->ok($quotaManager->getQuotaInfo(
                $request->get_param('disk') ?? 'local',
                $claims->pathPrefix,
                $claims->maxStorageMb
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Audit

    public function handleAudit(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);

            return $this->ok($audit->list(
                (int) ($request->get_param('limit') ?? 100),
                (int) ($request->get_param('offset') ?? 0),
                $claims->userId
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Audit export (paid module) — a full, unpaginated download of the tenant's
     * audit history (live + archived), as NDJSON or CSV. Bypasses the ok()/error()
     * JSON envelope: the module sends its own headers + body directly via native
     * header()/echo, then we exit — same posture as publicLink() above.
     */
    public function handleAuditExport(\WP_REST_Request $request): void
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $module = \FluxFiles\ModuleRegistry::require('audit-export', FluxFilesPlugin::license(), $claims);
            $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);
            $module->export($audit, $claims, [
                'action' => $request->get_param('action'),
                'from'   => $request->get_param('from'),
                'to'     => $request->get_param('to'),
                'path'   => $request->get_param('path'),
                'actor'  => $request->get_param('actor'),
            ], (string) ($request->get_param('format') ?: 'ndjson'));
        } catch (ApiException $e) {
            status_header($e->getHttpCode());
            wp_send_json(['data' => null, 'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(), 'error_params' => $e->getErrorParams()], $e->getHttpCode());
        }
        exit;
    }

    /**
     * Audit purge (paid module) — destructive, so admin-only: the audit log is
     * stored per-DISK, not per-tenant, so a path-scoped token could otherwise
     * purge lines belonging to other tenants sharing the same disk.
     */
    public function handleAuditPurge(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            if (trim($claims->pathPrefix, '/') !== '') {
                throw new ApiException('Audit purge requires an unscoped (admin) token', 403, 'forbidden');
            }

            $body = $this->body($request);
            $disk = (string) ($body['disk'] ?? 'local');
            // pathPrefix and allowedDisks are independent claims — an unscoped
            // token can still be limited to specific disks, so the tenant-prefix
            // check above does NOT imply disk access.
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }

            $before = isset($body['before']) && $body['before'] !== ''
                ? (int) $body['before']
                : ($claims->auditRetentionDays > 0 ? time() - ($claims->auditRetentionDays * 86400) : 0);
            if ($before <= 0) {
                throw new ApiException('An explicit `before` cutoff (or a token audit_retention_days) is required', 400, 'audit_purge_no_cutoff');
            }

            $module = \FluxFiles\ModuleRegistry::require('audit-export', FluxFilesPlugin::license(), $claims);

            return $this->ok($module->purge($this->metaRepo, $claims, $disk, $before));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Chunk upload

    public function handleChunkInit(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            // S3 multipart sends bytes browser→S3 on presigned URLs, so they never
            // reach this server and CANNOT be scanned. A tenant that asked for virus
            // scanning must not have an unscannable side door — refuse the whole
            // chunk route family rather than let it quietly bypass the scanner.
            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }

            $scopedPath = $claims->scopePath($path);
            $chunker = new ChunkUploader($this->diskManager);
            $result = $chunker->initiate($disk, $scopedPath);
            $this->logAudit($claims, 'chunk_upload', $disk, $scopedPath);
            $this->dispatchWebhook($claims, 'chunk_upload', ['disk' => $disk, 'path' => $scopedPath, 'name' => basename($scopedPath)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleChunkPresign(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body       = $this->body($request);
            $disk       = $body['disk'] ?? null;
            $key        = $body['key'] ?? null;
            $uploadId   = $body['upload_id'] ?? null;
            $partNumber = $body['part_number'] ?? null;

            if (!$disk || !$key || !$uploadId || !$partNumber) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->presignPart($disk, $key, $uploadId, (int) $partNumber));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleChunkComplete(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body     = $this->body($request);
            $disk     = $body['disk'] ?? null;
            $key      = $body['key'] ?? null;
            $uploadId = $body['upload_id'] ?? null;
            $parts    = $body['parts'] ?? null;

            if (!$disk || !$key || !$uploadId || !$parts) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }
            $fm->validateScopedPath($key);
            // Unlike the direct upload() path, S3 multipart has no collision policy at
            // all — completing against an existing key overwrites it unconditionally.
            // Honour owner_only the same way upload()/rename()/move() do before letting
            // the multipart complete replace bytes that already exist at this key.
            if ($this->diskManager->disk($disk)->fileExists($key)) {
                $fm->assertCanModifyScopedPath($disk, $key);
            }

            $chunker = new ChunkUploader($this->diskManager);

            $result = $chunker->complete($disk, $key, $uploadId, $parts);
            $this->metaRepo->save($disk, $key, [
                'uploaded_by' => $claims->userId,
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleChunkAbort(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body     = $this->body($request);
            $disk     = $body['disk'] ?? null;
            $key      = $body['key'] ?? null;
            $uploadId = $body['upload_id'] ?? null;

            if (!$disk || !$key || !$uploadId) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->abort($disk, $key, $uploadId));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // -------------------------------------------------------------------------
    // UI / asset / locale serving (public — needed by the iframe)
    // -------------------------------------------------------------------------

    public function serveUiHtml(\WP_REST_Request $request)
    {
        $publicDir = FluxFilesPlugin::corePath('public');
        $langDir   = FluxFilesPlugin::corePath('lang');
        if ($publicDir === null || $langDir === null) {
            return $this->error('UI assets not bundled with this plugin install', 500);
        }
        $html = @file_get_contents($publicDir . '/index.html');
        if ($html === false) {
            return $this->error('public/index.html not found', 500);
        }

        $i18n = new \FluxFiles\I18n($langDir, $request->get_param('lang') ?: null);
        $locale = $i18n->locale();
        $dir = $i18n->direction();
        $injection = 'window.__FM_LOCALE__ = { locale: ' . json_encode($locale)
            . ', dir: ' . json_encode($dir)
            . ', messages: ' . $i18n->toJson() . ' };';
        $html = str_replace(
            "window.__FM_LOCALE__ = window.__FM_LOCALE__ || { locale: 'en', dir: 'ltr', messages: {} };",
            $injection,
            $html
        );
        $html = str_replace(
            '<html lang="en">',
            '<html lang="' . esc_attr($locale) . '" dir="' . esc_attr($dir) . '">',
            $html
        );

        // The HTML uses `../assets/fm.css` and `../assets/fm.js`, which work when
        // served from /public/index.html on a real path but break under WP's
        // ?rest_route=… query form (browser strips the query, ../ goes to /assets/
        // at site root). Rewrite to absolute URLs against our /assets/ route and
        // append a content-hash version so browsers pick up new builds.
        $assetsBase = rest_url('fluxfiles/v1/assets');
        $assetsDir  = FluxFilesPlugin::corePath('assets') ?: '';
        $jsHash  = $assetsDir && is_file("$assetsDir/fm.js")  ? substr(md5_file("$assetsDir/fm.js"),  0, 8) : 'x';
        $cssHash = $assetsDir && is_file("$assetsDir/fm.css") ? substr(md5_file("$assetsDir/fm.css"), 0, 8) : 'x';
        $jsSep  = strpos($assetsBase, '?') !== false ? '&' : '?';
        $html = str_replace('"../assets/fm.css"', '"' . esc_url($assetsBase . '/fm.css') . $jsSep . 'v=' . $cssHash . '"', $html);
        $html = str_replace('"../assets/fm.js"',  '"' . esc_url($assetsBase . '/fm.js')  . $jsSep . 'v=' . $jsHash  . '"', $html);

        // Bypass REST JSON serialization — the browser iframe must receive raw HTML
        // with the right content type, not a JSON-wrapped string.
        $this->sendRaw($html, 'text/html; charset=utf-8', ['Content-Language: ' . $locale, 'Cache-Control: no-cache, must-revalidate']);
    }

    public function serveUiAsset(\WP_REST_Request $request)
    {
        $file = (string) $request->get_param('file');
        // Regex on the route already restricts to fm.js / fm.css.
        $assetsDir = FluxFilesPlugin::corePath('assets');
        if ($assetsDir === null) {
            return $this->error('Assets not bundled', 500);
        }
        $path = $assetsDir . '/' . $file;
        $real = realpath($path);
        if ($real === false || strpos($real, realpath($assetsDir)) !== 0 || !is_file($real)) {
            return $this->error('Asset not found', 404);
        }
        $mime = substr($file, -3) === '.js' ? 'application/javascript' : 'text/css';
        // Cache busts on every file mtime so iframe never serves stale UI/JS after upgrades.
        $etag = '"' . substr(md5_file($real), 0, 16) . '"';
        $this->sendRaw(file_get_contents($real), $mime . '; charset=utf-8', [
            'Cache-Control: public, max-age=300, must-revalidate',
            'ETag: ' . $etag,
        ]);
    }

    /**
     * Send a non-JSON response from inside a REST handler. WP REST normally
     * json_encodes the response body; we need raw HTML / JS / CSS for the
     * iframe and its assets.
     */
    private function sendRaw(string $body, string $contentType, array $extraHeaders = []): void
    {
        status_header(200);
        header('Content-Type: ' . $contentType);
        foreach ($extraHeaders as $h) {
            header($h);
        }
        echo $body;
        exit;
    }

    public function serveLangList(\WP_REST_Request $request)
    {
        $langDir = FluxFilesPlugin::corePath('lang');
        if ($langDir === null) {
            return $this->ok([]);
        }
        $files = glob($langDir . '/*.json') ?: [];
        $result = [];
        foreach ($files as $f) {
            $data = json_decode((string) file_get_contents($f), true);
            if (!is_array($data)) continue;
            $code = $data['_meta']['locale'] ?? basename($f, '.json');
            $result[] = [
                'code' => $code,
                'name' => $data['_meta']['name'] ?? $code,
                'dir'  => $data['_meta']['direction'] ?? 'ltr',
            ];
        }
        return $this->ok($result);
    }

    public function serveLangMessages(\WP_REST_Request $request)
    {
        $locale = (string) $request->get_param('locale');
        $langDir = FluxFilesPlugin::corePath('lang');
        if ($langDir === null) {
            return $this->error('Locales not bundled', 500);
        }
        $path = $langDir . '/' . $locale . '.json';
        $real = realpath($path);
        if ($real === false || strpos($real, realpath($langDir)) !== 0 || !is_file($real)) {
            return $this->error('Locale not found', 404);
        }
        $data = json_decode((string) file_get_contents($real), true);
        if (!is_array($data)) {
            return $this->error('Invalid locale file', 500);
        }
        return $this->ok([
            'locale'   => $data['_meta']['locale'] ?? $locale,
            'dir'      => $data['_meta']['direction'] ?? 'ltr',
            'messages' => $data,
        ]);
    }
}
