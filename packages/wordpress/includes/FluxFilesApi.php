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
        // Existing installs never re-run activate(), so (re)apply the storage
        // web-server rules here too — this is the path every upload goes through.
        FluxFilesPlugin::protectStorage();
        // Key for the tamper-evident audit chain (an option, not the env).
        if (class_exists(\FluxFiles\AuditChain::class)) {
            \FluxFiles\AuditChain::configure((string) get_option('fluxfiles_secret', ''));
        }

        // Module engine settings (Settings → FluxFiles → Module engines) live in an
        // option; the paid modules read $_ENV. Seeded here so every REST handler —
        // scan, webhooks, AI, … — sees them before any module runs.
        FluxFilesPlugin::seedModuleEnv();

        $this->diskManager = new DiskManager(FluxFilesPlugin::diskConfigs());
        $this->metaRepo = get_option('fluxfiles_storage_backend', 'json') === 'db'
            ? new WpDbMetadataHandler($this->diskManager)
            : new StorageMetadataHandler($this->diskManager);
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    /**
     * A Bearer request to OUR namespace that also carries a stale/foreign REST
     * nonce (wp.apiFetch adds X-WP-Nonce to every call) must not be refused by
     * WP's cookie check — but it must not run as the cookie user either.
     *
     * WP core's rest_cookie_check_errors() (priority 100) already handles the
     * normal case: a request with NO nonce is downgraded to user 0 and returns
     * true, so fm.js's Bearer calls never reach this filter's branch. What it
     * does on a BAD nonce is return `rest_cookie_invalid_nonce` while leaving
     * the cookie user logged in. The old version of this filter cleared ANY
     * auth error whenever "/fluxfiles/v1/" appeared anywhere in the URI (query
     * string included) — so `POST /wp-json/wp/v2/users?_wpnonce=bad&x=/fluxfiles/v1/`
     * with any `Authorization: Bearer x` ran as the logged-in admin, CSRF check gone.
     *
     * Now: only the route WP will actually dispatch (restRoute()) counts, only
     * the exact `rest_cookie_invalid_nonce` error is neutralised (another
     * plugin's auth error is never touched), and the user is reset to 0 — the
     * same thing WP does for a nonce-less request — so permission_callback
     * judges the Bearer alone and the cookie identity is never used.
     *
     * @param mixed $result
     * @return mixed
     */
    public static function bypassCookieAuthForBearer($result)
    {
        if (!($result instanceof \WP_Error) || $result->get_error_codes() !== ['rest_cookie_invalid_nonce']) {
            return $result;
        }
        if (!self::isOwnRestRoute(self::restRoute())) {
            return $result;
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+\S/i', (string) $auth)) {
            return $result;
        }
        wp_set_current_user(0);
        return true;
    }

    /**
     * The REST route WordPress is about to dispatch — the same value
     * rest_api_loaded() hands to serve_request(), never a substring of the
     * raw URI. WP::parse_request() lets `?rest_route=` in $_GET win over the
     * pretty-permalink match, so the query var is authoritative; $_GET and
     * then the path after the REST prefix are fallbacks for an early call.
     */
    public static function restRoute(): string
    {
        $wp = $GLOBALS['wp'] ?? null;
        if (is_object($wp) && isset($wp->query_vars['rest_route']) && is_string($wp->query_vars['rest_route'])) {
            return '/' . ltrim($wp->query_vars['rest_route'], '/');
        }
        if (isset($_GET['rest_route']) && is_string($_GET['rest_route'])) {
            return '/' . ltrim($_GET['rest_route'], '/');
        }
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        $prefix = '/' . trim(function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json', '/') . '/';
        $homePath = rtrim((string) (parse_url(function_exists('home_url') ? home_url('/') : '/', PHP_URL_PATH) ?: ''), '/');
        $base = $homePath . $prefix;
        if (strncmp($path, $base, strlen($base)) !== 0) {
            return '';
        }
        return '/' . substr($path, strlen($base));
    }

    /** True for `/fluxfiles/v1` and anything under it (WP matches routes case-insensitively). */
    public static function isOwnRestRoute(string $route): bool
    {
        $ns = '/fluxfiles/v1';
        return strcasecmp(rtrim($route, '/'), $ns) === 0 || strncasecmp($route, $ns . '/', strlen($ns) + 1) === 0;
    }

    public static function registerRoutes(): void
    {
        $api = new self();
        $ns  = 'fluxfiles/v1';

        // A Bearer call to our namespace that also carries a stale nonce must not be
        // refused — nor run as the cookie user (see bypassCookieAuthForBearer()).
        // Priority 999: AFTER WP's `rest_cookie_check_errors` (priority 100).
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

        // Download a selection as a streamed zip. The UI's `canZip` gate defaults to
        // ON (allow_zip defaults true in Claims), so the toolbar's "Download ZIP"
        // button renders in proxy mode too — it 404'd here until this route existed.
        // Streams bytes straight to the client like handleStream()/handleImg(), so
        // it never returns a WP_REST_Response.
        register_rest_route($ns, $p . '/zip', array_merge($writeArgs, [
            'callback' => [$api, 'handleZip'],
        ]));

        // SFTP file permissions. SFTP reaches proxy mode two ways: a BYOB sftp disk
        // in the token (registered in fileManager()) and an operator-configured one,
        // which is also why /terminal + /git-deploy are proxied — chmod is the same
        // class of SFTP-only route and was simply never ported.
        register_rest_route($ns, $p . '/chmod', [
            [
                'methods'             => 'GET',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleGetChmod'],
            ],
            [
                'methods'             => 'POST',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleSetChmod'],
            ],
        ]);

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
        register_rest_route($ns, $p . '/duplicates', array_merge($readArgs, [
            'callback' => [$api, 'handleDuplicates'],
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

        // Compliance Readiness Scorecard (free/core) — read-only capability
        // checklist, gated by the 'audit' perm, not a module/license check.
        register_rest_route($ns, $p . '/compliance/scorecard', array_merge($readArgs, [
            'callback' => [$api, 'handleComplianceScorecard'],
        ]));

        // Audit export/purge (paid module)
        register_rest_route($ns, $p . '/audit/export', array_merge($readArgs, [
            'callback' => [$api, 'handleAuditExport'],
        ]));
        register_rest_route($ns, $p . '/retention/run', array_merge($writeArgs, [
            'callback' => [$api, 'handleRetentionRun'],
        ]));
        register_rest_route($ns, $p . '/fixity/check', array_merge($writeArgs, [
            'callback' => [$api, 'handleFixityCheck'],
        ]));
        register_rest_route($ns, $p . '/audit/verify', array_merge($readArgs, [
            'callback' => [$api, 'handleAuditVerify'],
        ]));
        register_rest_route($ns, $p . '/audit/purge', array_merge($writeArgs, [
            'callback' => [$api, 'handleAuditPurge'],
        ]));

        // Legal hold (paid module) — place/release/list are gated; status is
        // free/core visibility. Enforcement lives inside FileManager (core).
        register_rest_route($ns, $p . '/hold/status', array_merge($readArgs, [
            'callback' => [$api, 'handleHoldStatus'],
        ]));
        register_rest_route($ns, $p . '/hold', array_merge($writeArgs, [
            'callback' => [$api, 'handleHold'],
        ]));
        register_rest_route($ns, $p . '/hold/release', array_merge($writeArgs, [
            'callback' => [$api, 'handleHoldRelease'],
        ]));
        register_rest_route($ns, $p . '/hold/list', array_merge($readArgs, [
            'callback' => [$api, 'handleHoldList'],
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
        register_rest_route($ns, $p . '/virus/scan', array_merge($writeArgs, [
            'callback' => [$api, 'handleVirusScan'],
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

        // One-click Git deploy (SFTP disks only; free/core, not a paid module) —
        // target path/branch/hooks come from claims, never the request body
        // (docs/security/GIT-DEPLOY-SECURITY-REVIEW.md §4.1).
        register_rest_route($ns, $p . '/git-deploy', array_merge($writeArgs, [
            'callback' => [$api, 'handleGitDeploy'],
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
        // fm.js/fm.css plus the vendored lazy-loaded libraries under
        // assets/vendor/ (xterm, CodeMirror) — fm.js derives its base from its
        // own <script src>, which is this route. No '..' can match, and
        // serveUiAsset() re-checks containment with realpath().
        register_rest_route($ns, '/assets/(?P<file>fm\.(?:min\.)?(?:js|css)|vendor/[A-Za-z0-9._/-]+\.(?:js|css))', array_merge($publicArgs, [
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
     * The WordPress capability a cookie-session user must hold to reach the
     * FluxFiles data routes at all.
     *
     * `is_user_logged_in()` alone is not a gate on an open-registration site:
     * the default role there is Subscriber, and every one of them used to get a
     * full read+write+delete token over the whole disk. `upload_files` is the
     * lowest built-in capability that already means "this person may put files
     * on this site" (Author and up by default), which is exactly the question
     * these routes ask.
     *
     * Operators who genuinely want a lower bar can lower it — that is a
     * deliberate, explicit act, not the default:
     *
     *   add_filter('fluxfiles_required_capability', fn () => 'read');
     */
    public static function requiredCapability(): string
    {
        $cap = 'upload_files';
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fluxfiles_required_capability', $cap);
            if (is_string($filtered) && $filtered !== '') {
                $cap = $filtered;
            }
        }
        return $cap;
    }

    /**
     * Permission callback — accept either:
     *   1. A valid Bearer JWT (signed with FLUXFILES_SECRET), used by fm.js
     *      from inside the iframe.
     *   2. A logged-in WP user with a valid REST nonce (X-WP-Nonce header),
     *      used when the wrapping page calls our routes through wp.apiFetch,
     *      AND holding requiredCapability().
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
        return is_user_logged_in() && current_user_can(self::requiredCapability());
    }

    /**
     * Permission callback for the token-refresh route: the logged-in WP session
     * only (cookie + REST nonce). Deliberately does NOT accept a Bearer JWT —
     * the refresh route exists precisely because the JWT has expired. Same
     * capability bar as checkAuth(), since what it hands back is a token for
     * those very routes.
     */
    public static function checkLoggedIn(): bool
    {
        return is_user_logged_in() && current_user_can(self::requiredCapability());
    }

    /** Attachment creation requires the WP media-upload capability. */
    public static function checkCanUpload(): bool
    {
        return is_user_logged_in() && current_user_can('upload_files');
    }

    /**
     * Register a picked FluxFiles file as a WP attachment (idempotent) and return its
     * id + rewritten URL. Body: {key, disk?, width?, height?, alt?, caption?, variants?}.
     *
     * Everything that decides WHICH file this is comes from the server, never the
     * body: the key is resolved through the requesting WP user's own FluxFiles token
     * (disk allow-list, read perm, tenant prefix, system/hidden paths, existence,
     * owner_only) and the URL is derived from the disk config. The body's `url`,
     * `name` and `mime` are ignored. This matters because the stored key is what
     * onDelete() later removes from storage when `fluxfiles_delete_storage` is on —
     * a client-chosen key used to make any Author an arbitrary-file-delete primitive.
     */
    public function handleAttach(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params() ?: $request->get_params();
        $rawKey = (string) ($body['key'] ?? ($body['path'] ?? ''));
        $disk = (string) ($body['disk'] ?? 'local');
        if ($rawKey === '') {
            return $this->error('A file key is required', 400, 'missing_param');
        }
        try {
            // The WP session user's token — the same one the shortcode/block/media
            // button minted for the iframe this pick came from. Deliberately NOT the
            // Bearer: this route is cookie + nonce authenticated (checkCanUpload).
            $claims = $this->lastClaims = JwtMiddleware::handle(
                FluxFilesPlugin::tokenForCurrentUser(),
                (string) get_option('fluxfiles_secret', '')
            );
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            // disk allow-list + read perm + scope + system + hidden (core's fileMeta()).
            $fileMeta = $fm->fileMeta($disk, $rawKey);
            $key = $claims->scopePath($rawKey);
            // Judged below the tenant prefix (as core's hidden check is), so a dotted
            // prefix segment can't lock a tenant out of the whole bridge.
            if (FluxFilesAttachments::isUnsafeKey($claims->unscopePath($key))) {
                throw new ApiException('Access denied: system path', 403, 'system_path');
            }
            if (!$this->diskManager->disk($disk)->fileExists($key)) {
                throw new ApiException('File not found', 404, 'not_found');
            }
            // owner_only: an attachment is a delete handle on the storage file (see
            // onDelete), so it may only be minted by the file's owner. Unowned legacy
            // files pass, exactly like FileManager::assertOwner().
            if ($claims->ownerOnly) {
                $owner = $this->metaRepo->get($disk, $key)['uploaded_by'] ?? null;
                if ($owner !== null && $owner !== $claims->userId) {
                    throw new ApiException('You can only modify files you uploaded', 403, 'owner_only');
                }
            }

            $config = $this->diskManager->config($disk);
            $url = FluxFilesAttachments::stableUrl($config, $key);
            if ($url === null) {
                // Private S3/R2, gated local, SFTP: the only URL is an expiring
                // presign/token, so the attachment would break within the hour.
                throw new ApiException('This disk has no stable public URL, so its files cannot become Media Library attachments', 422);
            }

            $res = FluxFilesAttachments::findOrCreate([
                'url'      => $url,
                'key'      => $key,
                'disk'     => $disk,
                'basename' => basename($key),
                'mime'     => (string) ($fileMeta['mime'] ?? ''),
                'width'    => (int) ($body['width'] ?? 0),
                'height'   => (int) ($body['height'] ?? 0),
                'alt'      => (string) ($body['alt'] ?? ''),
                'caption'  => (string) ($body['caption'] ?? ''),
                'variants' => $this->attachVariants($config, $disk, $key, $body['variants'] ?? null),
            ]);
            return $this->ok($res);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        } catch (\Throwable $e) {
            return $this->error('Attach failed', 400);
        }
    }

    /**
     * Keep only the client's variant URLs that point at an EXISTING file in this
     * file's own `_variants/` directory on the same disk, rebuilt server-side from
     * the disk config — so a pick can't plant arbitrary URLs in the srcset.
     *
     * @param array<string,mixed> $config
     * @param mixed $variants
     * @return array<string,array{url:string}>|null
     */
    private function attachVariants(array $config, string $disk, string $key, $variants): ?array
    {
        if (!is_array($variants)) {
            return null;
        }
        $dir = dirname($key);
        $variantsDir = ($dir !== '.' && $dir !== '' ? $dir . '/' : '') . '_variants/';
        $base = FluxFilesAttachments::stableUrl($config, $variantsDir);
        if ($base === null) {
            return null;
        }
        $fs = $this->diskManager->disk($disk);
        $out = [];
        foreach ($variants as $name => $v) {
            $vurl = is_array($v) ? ($v['url'] ?? '') : $v;
            if (!is_string($name) || !is_string($vurl) || strncmp($vurl, $base, strlen($base)) !== 0) {
                continue;
            }
            $file = substr($vurl, strlen($base));
            if ($file === '' || preg_match('#[/\\\\?\#%\x00]|^\.#', $file)) {
                continue;
            }
            try {
                if ($fs->fileExists($variantsDir . $file)) {
                    $out[$name] = ['url' => (string) FluxFilesAttachments::stableUrl($config, $variantsDir . $file)];
                }
            } catch (\Throwable $e) {
            }
        }
        return $out ?: null;
    }

    /**
     * Storage side of deleting a FluxFiles-backed attachment (FluxFilesAttachments::
     * onDelete, opt-in via `fluxfiles_delete_storage`). Goes through the DELETING WP
     * user's own FileManager, so their delete perm, tenant prefix, owner_only and
     * legal holds all apply, plus the version/variant/metadata cleanup a raw
     * Flysystem delete skipped — and it is audited like any other delete. No WP user
     * (cron, CLI) → no token → the file stays (the safe default). Never throws.
     *
     * @return bool true when the storage file was deleted
     */
    public function deleteAttachmentStorage(string $disk, string $key, int $postId): bool
    {
        try {
            $claims = $this->lastClaims = JwtMiddleware::handle(
                FluxFilesPlugin::tokenForCurrentUser(),
                (string) get_option('fluxfiles_secret', '')
            );
        } catch (\Throwable $e) {
            return false;
        }
        $detail = 'wp_attachment_delete post=' . $postId;
        try {
            if (FluxFilesAttachments::isUnsafeKey($key)) {
                throw new ApiException('Access denied: system path', 403, 'system_path');
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            $scoped = $claims->scopePath($key);
            // Explicit, so a hold is honoured even on a core floor whose delete()
            // predates hold enforcement.
            $hold = $this->metaRepo->holdBlocking($disk, $scoped);
            if ($hold !== null) {
                throw new ApiException('This item is under legal hold and cannot be modified', 403, 'legal_hold_active');
            }
            if (!$this->diskManager->disk($disk)->fileExists($scoped)) {
                return false;
            }
            $this->fileManager($claims)->delete($disk, $scoped);
            $this->logAuditQuiet($claims, 'delete', $disk, $scoped, $detail);
            return true;
        } catch (ApiException $e) {
            try {
                $this->writeAudit($claims, 'access_denied', $claims->hasDisk($disk) ? $disk : ($claims->allowedDisks[0] ?? $disk),
                    $claims->normalizeKey($key), ($e->getErrorCode() ?? 'forbidden') . ' ' . $detail);
            } catch (\Throwable $ignored) {
            }
            return false;
        } catch (\Throwable $e) {
            return false;
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
        // Reset FIRST: one FluxFilesApi instance serves every request of a REST batch,
        // and a 403 thrown below (token_not_access) must not be audited under the
        // PREVIOUS request's identity.
        $this->lastClaims = null;
        $secret = get_option('fluxfiles_secret', '');
        $auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        // Remembered so error() can audit a refused (403) request — see auditDenied().
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return $this->lastClaims = JwtMiddleware::handle($m[1], $secret);
        }
        return $this->lastClaims = JwtMiddleware::handle(FluxFilesPlugin::tokenForCurrentUser(), $secret);
    }

    /** Claims of the current request once authenticated (null before / on 401). */
    private ?\FluxFiles\Claims $lastClaims = null;

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

        // File versioning (paid module). Only the KEEPER (snapshot before overwrite) is
        // gated on the acting token's `allow_versioning` claim — so the free core keeps
        // no versions. The purger + mover are housekeeping for history that already
        // EXISTS, which may belong to another user whose token did carry the claim: a
        // delete/rename by a token without it must still erase/re-key that history, or
        // a permanent delete becomes undoable and a re-used path inherits someone
        // else's versions. So those two are wired whenever installed + licensed.
        if (\FluxFiles\ModuleRegistry::installed('versioning')
            && FluxFilesPlugin::license()->licensed('versioning')) {
            $versioning = new \FluxFiles\Versioning\VersioningModule();
            $diskManager = $this->diskManager;
            // The metadata repo lets the module bind history to the file's owner
            // (finding B2); an older module simply ignores the extra argument.
            $metaRepo = $this->metaRepo;
            if ($claims->allowVersioning ?? false) {
                $fm->setVersionKeeper(static function (string $d, string $key, $fs) use ($versioning, $claims, $diskManager, $metaRepo) {
                    $versioning->keep($fs, $key, $claims, $diskManager, $d, $metaRepo);
                });
            }
            // Erase version history on permanent delete, so `/delete` can't be undone
            // via a restore of a version saved before it — see VersioningModule::purge().
            $fm->setVersionPurger(static function (string $d, string $key, $fs) use ($versioning, $diskManager) {
                $versioning->purge($fs, $key, $diskManager, $d);
            });
            // Re-key version history on rename/move so it follows the file instead
            // of being orphaned under the old path's hash. method_exists: an older
            // installed module predates move() — keep/purge still work without it — and
            // this adapter may run against a core floor that predates setVersionMover().
            if (method_exists($versioning, 'move') && method_exists($fm, 'setVersionMover')) {
                $fm->setVersionMover(static function (string $d, string $from, string $to, $fs) use ($versioning, $diskManager, $metaRepo) {
                    $versioning->move($fs, $from, $to, $diskManager, $d, $metaRepo);
                });
            }
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

        // DLP / PII scan (paid module) — same "REST proxy handles /upload itself"
        // reasoning and the same lazy-gate-inside-the-callback contract as above.
        if (($claims->allowDlpScan ?? false)) {
            $fm->setDlpScanner(static function (string $localPath, string $name) use ($claims): array {
                /** @var \FluxFiles\Dlp\DlpModule $dlp */
                $dlp = \FluxFiles\ModuleRegistry::require('dlp', FluxFilesPlugin::license(), $claims);
                return $dlp->scanPath($localPath, $claims->dlpEntityTypes, $claims->dlpMinScore);
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

    /**
     * A dedicated per-user bucket on top of rateLimit(), for the routes core gives
     * their own tighter budget (index.php: `import`, `usage_refresh`). Same file +
     * window as the generic buckets, keyed by name so it never eats the read/write
     * budget. Throws ApiException 429 like rateLimit().
     */
    private function bucketLimit(\FluxFiles\Claims $claims, string $bucket, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }
        (new RateLimiterFileStorage(FluxFilesPlugin::storagePath() . '/rate_limit.json', $limit, $limit))
            ->check($claims->userId, $bucket);
    }

    /**
     * Per-subject rate limit for the two token-authenticated media endpoints.
     *
     * `/img` and `/stream` authenticate on a per-file token, not the main JWT, so
     * there is no Claims to run rateLimit() against — without this an unbounded
     * loop over `/img`'s width/height/quality/format/dpr axes fills a tenant's
     * `_variants/` with cache entries it can neither see nor purge. Own bucket,
     * keyed on the token's `sub`, so it never eats the tenant's API read budget.
     * Mirrors core's ff_media_rate_limit() in index.php.
     *
     * Returns false (429 already emitted) when the caller must stop.
     */
    private function mediaRateLimit(string $sub, string $bucket, int $default): bool
    {
        $limit = (int) apply_filters('fluxfiles_rate_limit_' . $bucket, $default);
        if ($limit <= 0) {
            return true; // explicitly disabled by the operator
        }
        try {
            $storagePath = FluxFilesPlugin::storagePath();
            (new RateLimiterFileStorage($storagePath . '/rate_limit.json', $limit, $limit))
                ->check($sub !== '' ? $sub : 'anonymous', $bucket);
            return true;
        } catch (ApiException $e) {
            // A limiter that cannot write its own state must not take the endpoint
            // down with it — only a genuine 429 blocks the request.
            if ($e->getHttpCode() !== 429) {
                return true;
            }
            status_header(429);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Too many requests';
            return false;
        }
    }

    /**
     * Most handlers pass the tenant-RELATIVE path the client sent. Core scopes every
     * audit key (index.php's central hook) because readAudit()/isPathInScope() match
     * on the FULL path — an unscoped key made a prefixed tenant's own actions
     * invisible to them and let a sibling tenant with the same relative path see
     * them. scopePath() is idempotent, so already-scoped callers are unaffected.
     * $scopeKey=false is for the few entries whose "key" is an id (trash/hold), not a path.
     */
    private function logAudit(\FluxFiles\Claims $claims, string $action, string $disk, string $key, ?string $detail = null, bool $scopeKey = true): void
    {
        $this->writeAudit($claims, $action, $disk, $scopeKey ? $this->auditKey($claims, $key) : $key, $detail);
    }

    private function writeAudit(\FluxFiles\Claims $claims, string $action, string $disk, string $key, ?string $detail = null): void
    {
        $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);
        $audit->log($claims->userId, $action, $disk, $key, null, null, $detail);
    }

    /** Tenant-scoped form of a relative key; normalized-but-unscoped when it can't be scoped. */
    private function auditKey(\FluxFiles\Claims $claims, string $key): string
    {
        if ($key === '') {
            return '';
        }
        try {
            return $claims->scopePath($key);
        } catch (ApiException $e) {
            return $claims->normalizeKey($key);
        }
    }

    /** logAudit() for paths where a log failure must never fail the request itself. */
    private function logAuditQuiet(\FluxFiles\Claims $claims, string $action, string $disk, string $key, ?string $detail = null): void
    {
        try {
            $this->logAudit($claims, $action, $disk, $key, $detail);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Record an authenticated request refused with a 403 (`access_denied`), the
     * proxy twin of core's ff_audit_denied(): written to a disk the token CAN use
     * (a `disk_denied` probe must not write onto that disk), key scoped like every
     * other entry, or normalized-but-unscoped when the probe is the cross-tenant
     * path itself (then only an unscoped admin sees it). Best-effort.
     */
    private function auditDenied(\FluxFiles\Claims $claims, ?string $code): void
    {
        try {
            $body = json_decode((string) file_get_contents('php://input') ?: '{}', true);
            $in = (is_array($body) ? $body : []) + $_POST + $_GET;
            $rawKey = '';
            foreach (['path', 'key', 'from', 'src_path'] as $k) {
                if (isset($in[$k]) && is_scalar($in[$k]) && (string) $in[$k] !== '') {
                    $rawKey = (string) $in[$k];
                    break;
                }
            }
            $disk = '';
            foreach (['disk', 'src_disk'] as $k) {
                if (isset($in[$k]) && is_scalar($in[$k])) {
                    $disk = (string) $in[$k];
                    break;
                }
            }
            $route = (string) ($_GET['rest_route'] ?? (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
            $detail = ($code ?? 'forbidden') . ' ' . (string) ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $route;
            if ($disk === '' || !$claims->hasDisk($disk)) {
                if ($disk !== '') {
                    $detail .= ' disk=' . $disk;
                }
                $disk = $claims->allowedDisks[0] ?? '';
            }
            if ($disk === '') {
                return;
            }
            try {
                $key = $rawKey === '' ? '' : $claims->scopePath($rawKey);
            } catch (ApiException $scopeErr) {
                $key = $claims->normalizeKey($rawKey);
            }
            // writeAudit(), not logAudit(): $key is already scoped — or deliberately
            // left unscoped for a cross-tenant probe, which must not be re-prefixed.
            $this->writeAudit($claims, 'access_denied', $disk, $key, mb_substr($detail, 0, 500));
        } catch (\Throwable $e) {
            // Auditing must never turn a clean 403 into a 500.
        }
    }

    /**
     * The three refused writes core's index.php records even though the write
     * itself failed: `virus_detected` → `virus_blocked`, `pii_detected` →
     * `dlp_blocked` (detail = entity-TYPE names only, never matched text),
     * `legal_hold_active` → `legal_hold_blocked` (detail = hold id). Key rules are
     * core's ff_blocked_write_key(): an editor save names the file in the body
     * `path`, an upload names the folder in `$_POST['path']` and the file in the
     * error `name`, an extract entry stays relative. Best-effort.
     *
     * @param array<string,mixed> $params
     */
    private function auditRefusedWrite(\FluxFiles\Claims $claims, ?string $code, array $params): void
    {
        $action = [
            'virus_detected'    => 'virus_blocked',
            'pii_detected'      => 'dlp_blocked',
            'legal_hold_active' => 'legal_hold_blocked',
        ][(string) $code] ?? null;
        if ($action === null) {
            return;
        }
        try {
            $json = json_decode((string) file_get_contents('php://input') ?: '{}', true);
            $body = is_array($json) ? $json : [];
            $in = $body + $_POST + $_GET;
            $disk = '';
            foreach (['disk', 'src_disk'] as $k) {
                if (isset($in[$k]) && is_scalar($in[$k]) && (string) $in[$k] !== '') {
                    $disk = (string) $in[$k];
                    break;
                }
            }
            if ($disk === '' || !$claims->hasDisk($disk)) {
                $disk = $claims->allowedDisks[0] ?? 'local';
            }
            $detail = null;
            if ($action === 'legal_hold_blocked') {
                $rel = '';
                foreach (['path', 'from', 'src_path'] as $k) {
                    if (isset($body[$k]) && is_scalar($body[$k]) && (string) $body[$k] !== '') {
                        $rel = (string) $body[$k];
                        break;
                    }
                }
                // trash/purge carries only a trash_id: fall back to the tenant-relative
                // path FileManager put in the exception; scopePath() is idempotent.
                if ($rel === '') {
                    $rel = (string) ($params['path'] ?? '');
                }
                $detail = (string) ($params['hold_id'] ?? '');
            } else {
                $name = (string) ($params['name'] ?? $params['entry'] ?? '');
                if (!isset($params['entry']) && is_string($body['path'] ?? null) && $body['path'] !== '') {
                    $rel = $body['path'];
                } elseif (!isset($params['entry']) && is_string($_POST['path'] ?? null) && trim($_POST['path'], '/') !== '') {
                    $rel = trim($_POST['path'], '/') . '/' . $name;
                } else {
                    $rel = $name;
                }
                if ($action === 'dlp_blocked') {
                    $detail = implode(',', array_map('strval', (array) ($params['entities'] ?? [])));
                }
            }
            $this->logAudit($claims, $action, $disk, $rel, $detail);
        } catch (\Throwable $e) {
            // Auditing must never turn a clean 4xx into a 500.
        }
    }

    /**
     * The `X-Accel-Redirect` target for a storage key (core's ff_xaccel_uri(), inlined:
     * the adapter floor predates it). nginx URL-DECODES this header, so a raw key breaks
     * for a `%` (404) or `?`/`#` (cut off as query/fragment): each segment is
     * percent-encoded, the `/` separators stay.
     */
    public static function xaccelUri(string $base, string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));
        return rtrim($base, '/') . '/' . implode('/', $segments);
    }

    /**
     * `audit_reads` for /stream (core's ff_audit_stream_read()): only the first
     * request of a playback — no Range, or one starting at byte 0. Best-effort.
     *
     * @param array<string,mixed> $scope
     */
    private function auditStreamRead(array $scope, \WP_REST_Request $request): void
    {
        if (empty($scope['auditRead'])) {
            return;
        }
        $range = trim((string) ($request->get_header('range') ?? ''));
        if ($range !== '' && !preg_match('/^bytes=0-/', $range)) {
            return;
        }
        try {
            (new AuditLogStorage($this->metaRepo, [(string) $scope['disk']]))
                ->log((string) $scope['sub'], 'stream_read', (string) $scope['disk'], (string) $scope['path']);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Fire a webhook for a file-changing action (paid module). Gated the same
     * 3-layer way index.php's inline wiring does it (installed + licensed +
     * `allow_webhooks` + a configured `webhook_url`) — best-effort, never throws
     * into the request path (WebhooksModule::dispatch swallows its own failures).
     *
     * @param array<string,mixed> $context
     */
    private function dispatchWebhook(\FluxFiles\Claims $claims, string $event, array $context, bool $scopePath = true): void
    {
        if (!$claims->allowWebhooks || $claims->webhookUrl === ''
            || !\FluxFiles\ModuleRegistry::installed('webhooks')
            || !FluxFilesPlugin::license()->licensed('webhooks')) {
            return;
        }
        // Core sends the scoped audit key as `path`; most callers here pass the
        // relative one. Idempotent, so already-scoped callers are unaffected.
        if ($scopePath && isset($context['path']) && is_string($context['path'])) {
            $context['path'] = $this->auditKey($claims, $context['path']);
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
        if ($this->lastClaims !== null) {
            $this->auditRefusedWrite($this->lastClaims, $code, $params);
        }
        // legal_hold_active already has its own `legal_hold_blocked` line (core does the same).
        if ($status === 403 && $this->lastClaims !== null && $code !== 'legal_hold_active') {
            $this->auditDenied($this->lastClaims, $code);
        }
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

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function unscopeRows(\FluxFiles\Claims $claims, array $rows, string $field): array
    {
        foreach ($rows as &$row) {
            if (is_array($row) && isset($row[$field])) {
                $row[$field] = $claims->unscopePath((string) $row[$field]);
            }
        }
        unset($row);
        return $rows;
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

            // Scalars only: `?sort[]=x` must be a 400 from core, not a PHP warning.
            $q = static fn (string $k, string $d = ''): string => is_scalar($v = $request->get_param($k)) ? (string) $v : $d;
            return $this->ok($fm->list(
                $request->get_param('disk') ?? 'local',
                $request->get_param('path') ?? '',
                max(0, (int) $q('limit', '0')),
                $q('cursor'),
                // Server-side sort/filter (core >= 0.2.107); an older core ignores them.
                $q('sort'),
                $q('order', 'asc'),
                $q('type', 'all')
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
                // FILTER_VALIDATE_BOOLEAN, as core does: a multipart field is a string, and
                // (bool) "false" / (bool) "0 " is TRUE — silently forcing an overwrite.
                filter_var($request->get_param('force_upload') ?? false, FILTER_VALIDATE_BOOLEAN)
            );

            // The uploaded file's key (core's multipart fallback), not the target dir.
            $uploadedKey = (string) ($result['key'] ?? ($request->get_param('path') ?? ''));
            $this->logAudit(
                $claims,
                'upload',
                $request->get_param('disk') ?? 'local',
                $uploadedKey
            );
            $this->dispatchWebhook($claims, 'upload', [
                'disk' => $request->get_param('disk') ?? 'local',
                'path' => $uploadedKey,
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
            // Before ANY outbound fetch, as core does (index.php): a read-only token
            // must not be able to make the server request URLs at all, and the fetch
            // gets its own tighter bucket (`import_rate_limit` claim, default 10/min)
            // so it can't run at the generic upload rate.
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
            }
            $importLimit = ($claims->importRateLimit ?? 0) > 0
                ? (int) $claims->importRateLimit
                : (int) apply_filters('fluxfiles_import_rate_limit', 10);
            $this->bucketLimit($claims, 'import', $importLimit);
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
            // A POST — core counts every POST as a write (index.php $isWriteAction): a
            // PUT/POST presign mints an upload URL, and verify reads + hashes the whole file.
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body   = $this->body($request);
            $disk   = $body['disk'] ?? null;
            $path   = $body['path'] ?? null;
            $method = $body['method'] ?? null;
            $ttl    = $body['ttl'] ?? null;
            // Core/Laravel both uppercase the method and forward size/size_bytes —
            // without this, FileManager::presign()'s `in_array($method, ['GET','PUT'])`
            // check rejects a lowercase "put" (400) before the guards below even run,
            // and a PUT with no size always 400s on the required-size check.
            $method = strtoupper((string) $method);
            $sizeBytes = (int) ($body['size'] ?? $body['size_bytes'] ?? 0);

            if (!$disk || !$path || $method === '' || !$ttl) {
                throw new ApiException('Missing required fields', 400);
            }

            // A `method:"PUT"` presign mints a URL the browser PUTs straight to
            // S3/R2, so those bytes never reach this server and cannot be scanned
            // either — the same unscannable side door the chunk routes refuse
            // below, so it gets the same fail-closed treatment and the same codes.
            // POST (form upload straight to the bucket) is the same unscannable door.
            if ($method === 'PUT' || $method === 'POST') {
                if ($claims->allowVirusScan) {
                    throw new ApiException(
                        'Direct-to-storage upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                        409,
                        'virus_unscannable'
                    );
                }
                if ($claims->allowDlpScan) {
                    throw new ApiException(
                        'Direct-to-storage upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                        409,
                        'dlp_unscannable'
                    );
                }
            }

            $presignResult = $fm->presign($disk, $path, $method, (int) $ttl, $sizeBytes);
            // A GET presign hands out a download URL, a PUT one an upload URL.
            $this->logAuditQuiet($claims, (['PUT' => 'presign_put', 'POST' => 'presign_post'][$method] ?? 'presign_get'), (string) $disk, $claims->scopePath((string) $path), 'ttl=' . (int) $ttl);
            $this->dispatchWebhook($claims, (['PUT' => 'presign_put', 'POST' => 'presign_post'][$method] ?? 'presign_get'), ['disk' => (string) $disk, 'path' => $claims->scopePath((string) $path), 'name' => basename((string) $path)]);
            return $this->ok($presignResult);
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
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403, 'permission_denied');
            }
            // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
            // internal detail), so a prefixed tenant used to get 403 path_denied on every
            // metadata read/save/delete. scopePath() prefixes it — idempotent for an
            // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
            $key = $claims->scopePath((string) $key);
            // Normalize + refuse system paths BEFORE the repo builds any path from it —
            // same as core's handleGetMetadata (isPathInScope() validates, not sanitizes).
            $key = $this->fileManager($claims)->validateScopedPath($key);

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
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
            }
            // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
            // internal detail), so a prefixed tenant used to get 403 path_denied on every
            // metadata read/save/delete. scopePath() prefixes it — idempotent for an
            // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
            $key = $claims->scopePath((string) $key);
            // owner_only + legal hold + system-path (_fluxfiles/, _variants/) refusal,
            // exactly as core/Laravel do — without it any write token could rewrite
            // another user's alt/title/tags or forge bookkeeping under _fluxfiles/.
            $key = $this->fileManager($claims)->assertCanModifyScopedPath((string) $disk, $key);

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
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
            }
            // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
            // internal detail), so a prefixed tenant used to get 403 path_denied on every
            // metadata read/save/delete. scopePath() prefixes it — idempotent for an
            // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
            $key = $claims->scopePath((string) $key);
            // Same guards as handleSaveMetadata() above.
            $key = $this->fileManager($claims)->assertCanModifyScopedPath((string) $disk, $key);

            $this->metaRepo->delete($disk, $key);
            $this->logAudit($claims, 'metadata_update', $disk, $key);
            $this->dispatchWebhook($claims, 'metadata_update', ['disk' => $disk, 'path' => $key, 'name' => basename($key)]);

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
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403, 'permission_denied');
            }

            $rows = $this->metaRepo->search(
                $disk,
                $query,
                (int) ($request->get_param('limit') ?? 50),
                $claims->pathPrefix,
                $claims->showHidden
            );
            // Strip the tenant prefix like core does, so search keys are relative to
            // the client root — matching list()/navigation (the prefix is internal).
            return $this->ok($this->unscopeRows($claims, $rows, 'file_key'));
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
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403, 'permission_denied');
            }

            $rows = $this->metaRepo->searchFolders(
                $disk,
                $query,
                (int) ($request->get_param('limit') ?? 50),
                $claims->pathPrefix,
                $claims->showHidden
            );
            return $this->ok($this->unscopeRows($claims, $rows, 'dir_key'));
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

            $content = $fm->getContent(
                $request->get_param('disk') ?? 'local',
                (string) ($request->get_param('path') ?? '')
            );
            // Opt-in read audit (`audit_reads`). `?? false` keeps an older core working.
            if ($claims->auditReads ?? false) {
                $this->logAuditQuiet($claims, 'content_read', (string) ($request->get_param('disk') ?? 'local'), $claims->scopePath((string) ($request->get_param('path') ?? '')));
            }
            return $this->ok($content);
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
            $this->logAudit($claims, 'content_update', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'content_update', [
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

    /**
     * Stream a multi-file/folder selection as a zip. Every gate (allow_zip,
     * allow_download, read perm, disk scope, the file-count/byte caps, owner_only)
     * lives in FileManager::zipManifest(), so this is a passthrough like the rest.
     *
     * Declared `void`: ZipStream writes its own headers and body to php://output,
     * so there is no WP_REST_Response to return. The error path has to emit JSON
     * by hand for the same reason — once bytes are flushed we can't fall back, so
     * the manifest (which is what actually throws) is built BEFORE any output.
     */
    public function handleZip(\WP_REST_Request $request): void
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body  = $this->body($request);
            $disk  = (string) ($body['disk'] ?? 'local');
            $paths = is_array($body['paths'] ?? null) ? $body['paths'] : [];
            $name  = isset($body['name']) ? (string) $body['name'] : null;

            // Resolve + validate first so a rejection is still a clean JSON error,
            // then audit the bulk download (the classic exfiltration shape) before
            // any byte is sent — core does the same.
            $manifest = $fm->zipManifest($disk, $paths);
            $names = array_map(static fn ($e) => (string) $e['key'], $manifest['entries'] ?? []);
            $this->logAuditQuiet(
                $claims,
                'zip_download',
                $disk,
                $claims->scopePath((string) ($paths[0] ?? '')),
                mb_substr((int) ($manifest['count'] ?? 0) . ' files, ' . (int) ($manifest['total'] ?? 0) . ' bytes: '
                    . implode(', ', array_slice($names, 0, 20)) . (count($names) > 20 ? ', …' : ''), 0, 1000)
            );

            // WP has already sent no body of its own, but it buffers — drop that so
            // ZipStream's own Content-Type/Disposition headers are the ones sent.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $fm->streamZip($disk, $paths, $name);
            exit;
        } catch (ApiException $e) {
            if ($e->getHttpCode() === 403 && $this->lastClaims !== null && $e->getErrorCode() !== 'legal_hold_active') {
                $this->auditDenied($this->lastClaims, $e->getErrorCode());
            }
            status_header($e->getHttpCode());
            wp_send_json(['data' => null, 'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(), 'error_params' => $e->getErrorParams()], $e->getHttpCode());
        }
    }

    /**
     * Read the Unix mode of one file on an SFTP disk. SFTP is reachable in proxy
     * mode via a BYOB sftp disk in the token (see fileManager()), which is the same
     * reason /terminal and /git-deploy are proxied.
     */
    public function handleGetChmod(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->getMode(
                $this->strParam($request, 'disk'),
                $this->strParam($request, 'path')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Set the Unix mode of one file on an SFTP disk. The allow_chmod claim is
     * checked here to match core's index.php (FileManager::setMode() enforces the
     * write perm, path scope and owner_only, but not this claim).
     */
    public function handleSetChmod(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            if (!$claims->allowChmod) {
                throw new ApiException('Changing permissions is not allowed', 403, 'chmod_forbidden');
            }
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = (string) ($body['disk'] ?? '');
            $path = (string) ($body['path'] ?? '');
            $result = $fm->setMode($disk, $path, (string) ($body['mode'] ?? ''));
            $this->logAudit($claims, 'chmod', $disk, $path, 'mode=' . $result['mode']);
            $this->dispatchWebhook($claims, 'chmod', [
                'disk' => $disk,
                'path' => $path,
                'name' => basename($path),
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
            $this->logAudit($claims, 'restore', (string) $disk, (string) $trashId, null, false);
            $this->dispatchWebhook($claims, 'restore', ['disk' => (string) $disk, 'path' => (string) $trashId, 'name' => basename((string) $trashId)], false);

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
            $this->logAudit($claims, 'purge', (string) $disk, (string) $trashId, null, false);
            $this->dispatchWebhook($claims, 'purge', ['disk' => (string) $disk, 'path' => (string) $trashId, 'name' => basename((string) $trashId)], false);

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

            $report = (new BucketDoctor($this->diskManager))->diagnose($disk, $origin ?: null);
            // It writes and deletes a probe object — logged like any other write.
            $this->logAuditQuiet($claims, 'disk_doctor', $disk, '');

            return $this->ok($report);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Storage usage dashboard: quota + per-type/-folder breakdown (one
     * listContents pass via getUsageBreakdown). Proxy mode recomputes each call
     * (no cache layer here); the standalone core endpoint adds the file cache.
     */
    /** Duplicate finder (core's GET /api/fm/duplicates). */
    public function handleDuplicates(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            // Hashes/lists the whole prefix: core's dedicated 2/min bucket, not the
            // generic 60/min read one (index.php `usage_refresh`).
            $this->bucketLimit($claims, 'usage_refresh', 2);
            $fm = $this->fileManager($claims);
            if (!method_exists($fm, 'findDuplicates')) {
                throw new ApiException('The duplicate finder needs a newer FluxFiles core', 501, 'not_found');
            }
            return $this->ok($fm->findDuplicates(
                (string) ($request->get_param('disk') ?: 'local'),
                max(1, min(200, (int) ($request->get_param('limit') ?: 50)))
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleUsage(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk = (string) ($request->get_param('disk') ?? 'local');
            // QuotaManager has no disk ACL of its own and DiskManager builds any
            // configured disk regardless of claims, so the allowlist is enforced
            // here — same gate order as handleSearch()/handleGitDeploy().
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403, 'permission_denied');
            }
            $quotaManager = new QuotaManager($this->diskManager);
            $top = $claims->usageTopFoldersCount > 0 ? $claims->usageTopFoldersCount : 10;
            $depth = $claims->usageFolderDepth > 0 ? $claims->usageFolderDepth : 1;

            // A breakdown is one listContents pass over the whole prefix. Mirror core:
            // a plain call is served from a short cache (usage_cache_ttl, default 900s),
            // and only a forced recompute (`refresh=true`) or a cache miss lists the
            // storage — with ?refresh=true on core's dedicated 2/min bucket, so the
            // endpoint can't be looped to hammer ListObjects at the 60/min read rate.
            $refresh = (string) ($request->get_param('refresh') ?? '') === 'true';
            if ($refresh) {
                $this->bucketLimit($claims, 'usage_refresh', 2);
            }
            $cacheKey = 'fluxfiles_usage_' . md5($disk . '|' . $claims->pathPrefix . '|' . $top . '|' . $depth);
            $ttl = ($claims->usageCacheTtl ?? 0) > 0 ? (int) $claims->usageCacheTtl : 900;
            $cached = $refresh ? false : get_transient($cacheKey);
            if (is_array($cached) && isset($cached['at'], $cached['breakdown']) && is_array($cached['breakdown'])) {
                $breakdown = $cached['breakdown'];
                $age = max(0, time() - (int) $cached['at']);
            } else {
                $breakdown = $quotaManager->getUsageBreakdown($disk, $claims->pathPrefix, $top, $depth);
                set_transient($cacheKey, ['at' => time(), 'breakdown' => $breakdown], $ttl);
                $age = 0;
            }
            $resp = $quotaManager->usageResponse(
                $breakdown,
                $claims->maxStorageMb,
                $claims->usageWarningThreshold,
                $claims->usageCriticalThreshold
            );
            $resp['cache_age_seconds'] = $age;

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
                $revoked = $module === 'share'
                    ? $mod->revokeShare($this->diskManager, $claims, $disk, $jti)
                    : $mod->revokePortal($this->diskManager, $claims, $disk, $jti);
                $this->logAuditQuiet($claims, $module . '_revoke', $disk, '', 'jti=' . $jti);
                $this->dispatchWebhook($claims, $module . '_revoke', ['disk' => $disk, 'path' => '', 'name' => '']);
                return $this->ok($revoked);
            }

            $fm = $this->fileManager($claims);
            $res = $module === 'share'
                ? $mod->createShare($fm, $this->diskManager, $claims, $secret, $body)
                : $mod->createPortal($fm, $this->diskManager, $claims, $secret, $body);
            // Minting a public link is exactly what an access review must be able to see.
            $this->logAuditQuiet($claims, $module . '_create', $disk, $claims->scopePath((string) ($body['path'] ?? '')));
            $this->dispatchWebhook($claims, $module . '_create', ['disk' => $disk, 'path' => $claims->scopePath((string) ($body['path'] ?? '')), 'name' => basename((string) ($body['path'] ?? ''))]);

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
        // …and gates on the licence via core's env-only `fromEnv()` helper (it reads
        // $_ENV, never a WP option) — which the "no fromEnv() in the proxy" smoke guard
        // can't see, because the call lives in the core file required above. A WordPress
        // licence normally lives in the plugin OPTION (Settings → FluxFiles → Licence —
        // shared hosting has no env to put it in), so without this every recipient of a
        // share link / upload portal got `402 license_required` on a fully licensed
        // site, and a portal upload's virus/DLP scan (also fromEnv) refused the file.
        $licenseKey = FluxFilesPlugin::licenseKey();
        if ($licenseKey !== '') {
            $_ENV['FLUXFILES_LICENSE_KEY'] = $licenseKey;
        }
        // Same for the module engine settings (portal uploads run virus/DLP scans,
        // share/intake dispatch webhooks): a real env var still wins.
        FluxFilesPlugin::seedModuleEnv();

        if ($which === 'share') {
            handleSharePublic($method, $uri, $this->diskManager, FluxFilesPlugin::diskConfigs(), $this->metaRepo);
        } else {
            handleIntakePublic($method, $uri, $this->diskManager, FluxFilesPlugin::diskConfigs(), $this->metaRepo);
        }
        exit;   // the core handler has already sent status, headers and body
    }

    public function handleSharePublicRoute(\WP_REST_Request $r): void { $this->publicLink('share', $r); }
    public function handleIntakePublicRoute(\WP_REST_Request $r): void { $this->publicLink('intake', $r); }

    /**
     * Optimization (FREE/core) — mirrors index.php's /api/fm/optimize route. Opt-in
     * per token via `allow_optimize` (it replaces/deletes originals, so it's a
     * deliberate capability, not on by default); the module itself enforces write.
     * NOT a ModuleRegistry-gated paid module (that map entry was removed when
     * Optimize moved to free/core) — a direct claim check, same as the on-upload
     * auto-optimize hook above.
     */
    public function handleOptimize(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            if (!$claims->allowOptimize) {
                throw new ApiException('This token may not use optimization', 403, 'optimize_forbidden');
            }
            $module = new \FluxFiles\OptimizeModule();
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
            $this->logAuditQuiet($claims, 'webhook_test', $claims->allowedDisks[0] ?? 'local', '');
            $this->dispatchWebhook($claims, 'webhook_test', ['disk' => $claims->allowedDisks[0] ?? 'local', 'path' => '', 'name' => '']);

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
                (string) ($request->get_param('path') ?: ''),
                $this->metaRepo
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
            $disk = (string) ($body['disk'] ?? 'local');
            $path = (string) ($body['path'] ?? '');
            $versionId = (string) ($body['version_id'] ?? '');
            $result = $module->restore($fm, $this->diskManager, $claims, $disk, $path, $versionId, $this->metaRepo);
            $this->logAudit($claims, 'version_restore', $disk, $path, $versionId);
            $this->dispatchWebhook($claims, 'version_restore', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
    /** On-demand scan of an already-stored file (paid `virus` module) — core's POST /api/fm/virus/scan. */
    public function handleVirusScan(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);
            if (!method_exists($fm, 'scanForViruses')) {
                throw new ApiException('On-demand virus scan needs a newer FluxFiles core', 501, 'virus_unavailable');
            }
            $virus = \FluxFiles\ModuleRegistry::require('virus', FluxFilesPlugin::license(), $claims);
            $body = $this->body($request);
            $disk = (string) ($body['disk'] ?? 'local');
            $path = (string) ($body['path'] ?? '');
            $result = $fm->scanForViruses($disk, $path, static fn (string $p): array => $virus->scanPath($p));
            $this->logAudit($claims, 'virus_scan', $disk, $claims->scopePath($path),
                !empty($result['clean']) ? 'clean' : 'infected: ' . (string) ($result['threat'] ?? 'unknown'));
            $this->dispatchWebhook($claims, 'virus_scan', ['disk' => $disk, 'path' => $claims->scopePath($path), 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleOcr(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('ocr', FluxFilesPlugin::license(), $claims);
            $body = $this->body($request);
            $result = $module->run($fm, $this->diskManager, $claims, $body);
            $this->logAudit($claims, 'ocr', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));
            $this->dispatchWebhook($claims, 'ocr', [
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
            // A POST — core counts every POST as a write (index.php $isWriteAction): a
            // PUT/POST presign mints an upload URL, and verify reads + hashes the whole file.
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('c2pa', FluxFilesPlugin::license(), $claims);
            $c2paBody = $this->body($request);
            $result = $module->verify($fm, $this->diskManager, $claims, $c2paBody);
            $this->logAuditQuiet($claims, 'c2pa_verify', (string) ($c2paBody['disk'] ?? 'local'), $claims->scopePath((string) ($c2paBody['path'] ?? '')));
            $this->dispatchWebhook($claims, 'c2pa_verify', ['disk' => (string) ($c2paBody['disk'] ?? 'local'), 'path' => $claims->scopePath((string) ($c2paBody['path'] ?? '')), 'name' => basename((string) ($c2paBody['path'] ?? ''))]);

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

    /**
     * One-click Git deploy — SFTP disks only. Free/core, not a ModuleRegistry
     * module. Mirrors index.php's /api/fm/git-deploy gate order exactly: server
     * kill-switch, allow_git_deploy claim, write perm, disk ACL, driver check,
     * unconfigured-path check, then run. Target path/branch/hooks come from
     * claims only — the request body carries at most `disk` — per
     * docs/security/GIT-DEPLOY-SECURITY-REVIEW.md §4.1. No dedicated rate-limit bucket
     * here (this plugin has no per-action bucket precedent — see handleTerminal()/
     * handleImportUrl() — so it reuses the generic write bucket like everything else).
     */
    public function handleGitDeploy(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            if ((getenv('FLUXFILES_GIT_DEPLOY_DISABLED') ?: '') === 'true') {
                throw new ApiException('Git deploy is disabled on this server', 403, 'git_deploy_disabled');
            }
            $claims = $this->claims();
            if (!$claims->allowGitDeploy) {
                throw new ApiException('Git deploy is not allowed', 403, 'git_deploy_forbidden');
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
                throw new ApiException('Git deploy only works on an SFTP disk', 400, 'terminal_unsupported');
            }
            if ($claims->gitDeployPath === '') {
                throw new ApiException('git_deploy_path claim is not configured for this token', 400, 'git_deploy_unconfigured');
            }
            [$conn, $root] = $this->diskManager->sftpConnection($disk);
            $path = \FluxFiles\SshTerminal::resolveCwd($claims->gitDeployPath, $root);
            $timeout = (int) (getenv('FLUXFILES_GIT_DEPLOY_TIMEOUT') ?: 120);
            $result = \FluxFiles\GitDeploy::run($conn, $path, $claims->gitDeployBranch, $claims->gitDeployHooks, $timeout);
            if (empty($result['shell_ok'])) {
                throw new ApiException('This host does not allow a shell (SFTP-only)', 400, 'terminal_no_shell');
            }
            if (!empty($result['locked'])) {
                throw new ApiException('A deploy is already in progress for this repo', 409, 'git_deploy_in_progress');
            }
            if (!empty($result['unsafe_config'])) {
                throw new ApiException(
                    'The repository\'s own git config contains a command-execution setting and was refused',
                    409,
                    'git_deploy_unsafe_repo'
                );
            }
            $detail = $claims->gitDeployPath . ($claims->gitDeployBranch !== '' ? '@' . $claims->gitDeployBranch : '');
            $this->logAudit($claims, 'git_deploy', $disk, '', $detail);
            $this->dispatchWebhook($claims, 'git_deploy', ['disk' => $disk, 'path' => '', 'name' => '']);

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
    // (see docs/guides/FEATURES.md — overlay preview stays a core-standalone/embed-only
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

        if (!$this->mediaRateLimit((string) ($scope['sub'] ?? ''), 'stream', 300)) {
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
            $this->auditStreamRead($scope, $request);
            // Production fast path: hand the bytes to nginx (native Range, no PHP copy).
            $xaccel = (string) (getenv('FLUXFILES_XACCEL') ?: '');
            if ($xaccel !== '') {
                header('Content-Type: ' . $mime);
                header('X-Accel-Buffering: no');
                header('X-Accel-Redirect: ' . self::xaccelUri($xaccel, $path));
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
            $this->auditStreamRead($scope, $request);
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
     * False when a verified /img token asks for something this proxy cannot honour —
     * today the overlay watermark (ImageToken's `watermark`, embedded only when
     * enabled). Kept separate so the fail-closed rule is testable without exit().
     *
     * @param array<string,mixed> $scope ImageToken::verify() output
     */
    public static function imgScopeServable(array $scope): bool
    {
        $wm = $scope['watermark'] ?? null;
        return !(is_array($wm) && $wm !== []);
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

        if (!$this->mediaRateLimit((string) ($scope['sub'] ?? ''), 'img', 120)) {
            exit;
        }

        // This port does not composite the overlay watermark. A token that carries
        // one (the `claims` escape hatch, or a Node/Python-minted token) must fail
        // closed rather than hand out the clean pixels the watermark exists to withhold.
        if (!self::imgScopeServable($scope)) {
            status_header(415);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Watermarked previews are not supported by this proxy';
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

        // A watermark scope was refused above (imgScopeServable()).
        // allow_download DOES reach here: the token is minted by core's FileManager, which
        // stamps `dl=0` for a preview-only tenant. Both of this handler's
        // fall-throughs would otherwise return the untransformed source.
        $noOriginal = !$scope['allowDownload'];
        if ($format === '') {
            if ($noOriginal) {
                $format = 'webp';
            } else {
                $this->serveBytes((string) $fs->read($path), $origMime);
                exit;
            }
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
            // Animated GIF / SVG / non-raster / bomb — serve the original
            // untouched, unless the token is preview-only.
            if ($noOriginal) {
                status_header(415);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Cannot transform this image type';
                exit;
            }
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

            $disk = (string) ($request->get_param('disk') ?? 'local');
            // QuotaManager has no disk ACL of its own and DiskManager builds any
            // configured disk regardless of claims, so the allowlist is enforced
            // here — same gate order as handleSearch()/handleGitDeploy().
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403, 'permission_denied');
            }
            $quotaManager = new QuotaManager($this->diskManager);

            return $this->ok($quotaManager->getQuotaInfo(
                $disk,
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

            // Same gate as core's /api/fm/audit: reading the activity log needs an
            // explicit 'audit' permission (off by default), so an ordinary read
            // token cannot see who did what.
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);

            // $claims is what makes list() scope entries to the token's path
            // prefix — audit.jsonl is per-disk, not per-tenant, so without it
            // every tenant on a shared disk reads the whole log.
            return $this->ok($audit->list(
                (int) ($request->get_param('limit') ?? 100),
                (int) ($request->get_param('offset') ?? 0),
                ($request->get_param('actor') ?: null),
                $claims,
                [
                    'action' => $request->get_param('action'),
                    'from'   => $request->get_param('from'),
                    'to'     => $request->get_param('to'),
                    'path'   => $request->get_param('path'),
                ]
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Compliance Readiness Scorecard (FREE/core, docs/design/COMPLIANCE-SCORECARD-DESIGN.md)
     * — a read-only capability checklist (virus scan / C2PA / audit export / SSO /
     * DLP / legal hold). Gated by the same `audit` perm as the activity log, not a
     * module/license check — every paid row simply reads `available: false` on an
     * unlicensed server. Mirrors index.php's `/api/fm/compliance/scorecard` route.
     */
    public function handleComplianceScorecard(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            return $this->ok(\FluxFiles\ComplianceScorecard::build($claims, FluxFilesPlugin::license()));
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
    /**
     * Audit chain verification (paid audit-export module) — core's
     * GET /api/fm/audit/verify. Admin-only: the log is per-disk, not per-tenant.
     */
    /** Retention rules (legal-hold module) — core's POST /api/fm/retention/run. */
    public function handleRetentionRun(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $body = $this->body($request);
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            $disk = (string) ($body['disk'] ?? 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $fm = $this->fileManager($claims);
            if (!method_exists($fm, 'runRetention')) {
                throw new ApiException('Retention rules need a newer FluxFiles core', 501, 'not_found');
            }
            \FluxFiles\ModuleRegistry::require('legal-hold', FluxFilesPlugin::license(), $claims);
            $dryRun = filter_var($body['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $report = $fm->runRetention($disk, $dryRun);
            $this->auditRetention($claims, $disk, $report);
            return $this->ok($report);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /** One audit line per file acted on, plus the run itself (core does the same). */
    private function auditRetention(\FluxFiles\Claims $claims, string $disk, array $report): void
    {
        foreach ($report['items'] as $it) {
            if (in_array($it['result'], ['trashed', 'deleted'], true)) {
                $this->logAuditQuiet($claims, 'retention_' . $it['action'], $disk, $claims->scopePath($it['path']), 'age_days=' . $it['age_days']);
            }
        }
        $this->logAuditQuiet($claims, 'retention_run', $disk, '', sprintf('%s acted=%d held=%d',
            !empty($report['dry_run']) ? 'dry-run' : 'applied', (int) $report['acted'], (int) $report['held']));
        $this->dispatchWebhook($claims, 'retention_run', ['disk' => $disk, 'path' => '', 'name' => '']);
    }

    /** Fixity / integrity check (audit-export module) — core's POST /api/fm/fixity/check. */
    public function handleFixityCheck(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            $body = $this->body($request);
            $disk = (string) ($body['disk'] ?? 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $fm = $this->fileManager($claims);
            if (!method_exists($fm, 'runFixityCheck')) {
                throw new ApiException('The integrity check needs a newer FluxFiles core', 501, 'not_found');
            }
            \FluxFiles\ModuleRegistry::require('audit-export', FluxFilesPlugin::license(), $claims);
            $report = $fm->runFixityCheck($disk, $this->metaRepo->readAudit($disk));
            foreach (['changed_unexplained' => 'changed', 'missing_unexplained' => 'missing'] as $list => $what) {
                foreach ($report[$list] as $key) {
                    $this->logAuditQuiet($claims, 'fixity_mismatch', $disk, $claims->scopePath((string) $key), $what);
                }
            }
            $this->logAuditQuiet($claims, 'fixity_check', $disk, '', sprintf('checked=%d unexplained=%d',
                (int) $report['checked'], count($report['changed_unexplained']) + count($report['missing_unexplained'])));
            $this->dispatchWebhook($claims, 'fixity_check', ['disk' => $disk, 'path' => '', 'name' => '']);
            return $this->ok($report);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleAuditVerify(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            if (trim($claims->pathPrefix, '/') !== '') {
                throw new ApiException('Audit verification requires an unscoped (admin) token', 403, 'forbidden');
            }
            $disk = (string) ($request->get_param('disk') ?: 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            \FluxFiles\ModuleRegistry::require('audit-export', FluxFilesPlugin::license(), $claims);
            return $this->ok(\FluxFiles\AuditChain::verify(array_merge(
                $this->metaRepo->readAuditArchive($disk),
                $this->metaRepo->readAudit($disk)
            )));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

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

            $result = $module->purge($this->metaRepo, $claims, $disk, $before);
            $this->logAudit($claims, 'audit_purge', $disk, '', "before={$before}");
            $this->dispatchWebhook($claims, 'audit_purge', ['disk' => $disk, 'path' => '', 'name' => '']);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Legal hold

    /**
     * Legal hold — STATUS is free/core (visibility, no license/claim check), same
     * posture as index.php's GET /api/fm/hold/status. Ported directly since it's
     * not reusable app code — same pattern as /stream and /img.
     */
    public function handleHoldStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $disk = (string) ($request->get_param('disk') ?: 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $path = (string) ($request->get_param('path') ?? '');
            $hold = $this->metaRepo->holdCovering($disk, $claims->scopePath($path));
            if ($hold === null) {
                return $this->ok(['on_hold' => false]);
            }
            $out = ['on_hold' => true, 'hold_id' => $hold['hold_id'] ?? null];
            if ($claims->hasPerm('audit')) {
                $out['reason'] = $hold['reason'] ?? null;
                $out['placed_by'] = $hold['placed_by'] ?? null;
                $out['placed_at'] = $hold['placed_at'] ?? null;
            }
            return $this->ok($out);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Legal hold — PLACE/RELEASE/LIST are the paid-gated management half (see
     * docs/design/RETENTION-LEGAL-HOLD-DESIGN.md §2). Enforcement itself is free/core
     * and license-independent, wired unconditionally into
     * FileManager::assertNoActiveHold() (inherited from core, no proxy change needed).
     */
    public function handleHold(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $body = $this->body($request);
            $disk = (string) ($body['disk'] ?? 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            if (!isset($body['path'])) {
                throw new ApiException('Missing required field: path', 400, 'missing_param');
            }

            /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
            $module = \FluxFiles\ModuleRegistry::require('legal-hold', FluxFilesPlugin::license(), $claims);

            $path = (string) $body['path'];
            $reason = (string) ($body['reason'] ?? '');
            $result = $module->place($this->metaRepo, $this->diskManager, $claims, $disk, $path, $reason);
            $this->logAudit($claims, 'legal_hold_place', $disk, $path, $reason);
            $this->dispatchWebhook($claims, 'legal_hold_place', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleHoldRelease(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $body = $this->body($request);
            $disk = (string) ($body['disk'] ?? 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            if (!isset($body['hold_id'])) {
                throw new ApiException('Missing required field: hold_id', 400, 'missing_param');
            }

            /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
            $module = \FluxFiles\ModuleRegistry::require('legal-hold', FluxFilesPlugin::license(), $claims);

            $holdId = (string) $body['hold_id'];
            $reason = (string) ($body['reason'] ?? '');
            $result = $module->release($this->metaRepo, $claims, $disk, $holdId, $reason);
            $this->logAudit($claims, 'legal_hold_release', $disk, $holdId, $reason, false);
            $this->dispatchWebhook($claims, 'legal_hold_release', ['disk' => $disk, 'path' => $holdId, 'name' => $holdId], false);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function handleHoldList(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $disk = (string) ($request->get_param('disk') ?: 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $includeReleased = filter_var($request->get_param('include_released') ?? false, FILTER_VALIDATE_BOOLEAN);

            /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
            $module = \FluxFiles\ModuleRegistry::require('legal-hold', FluxFilesPlugin::license(), $claims);

            return $this->ok($module->list($this->metaRepo, $claims, $disk, $includeReleased));
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
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
            }

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }

            $fm = $this->fileManager($claims);
            $sizeBytes = (int) ($body['size'] ?? $body['size_bytes'] ?? 0);
            if ($sizeBytes <= 0) {
                throw new ApiException('Missing required field: size', 400, 'missing_param');
            }
            $scopedPath = $fm->validateUserPath($path);
            $scopedPath = $fm->validateChunkUpload($disk, $scopedPath, $sizeBytes, true);
            $chunker = new ChunkUploader($this->diskManager);
            $result = $chunker->initiate($disk, $scopedPath, method_exists($fm, 'chunkInitMetadata') ? $fm->chunkInitMetadata($disk, $scopedPath) : []);
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
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
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
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }

            $chunker = new ChunkUploader($this->diskManager);

            $key = $this->fileManager($claims)->validateScopedPath($key);
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
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
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
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }
            $key = $fm->validateScopedPath($key);

            $chunker = new ChunkUploader($this->diskManager);

            $result = $chunker->complete(
                $disk, $key, $uploadId, $parts,
                fn(int $size) => $fm->validateChunkUpload($disk, $key, $size),
                $claims->uploadCollision === 'overwrite'
            );

            $this->metaRepo->save($disk, $key, [
                'uploaded_by' => $claims->userId,
                'size' => $result['size'],
                'modified' => time(),
            ]);
            $this->metaRepo->saveHash($disk, $key, '');
            // Core's central hook (index.php) audits/webhooks EVERY successful POST
            // /api/fm/chunk/* substep (init/complete/abort all match `$isWriteAction &&
            // $data !== null`), not only completion — mirror that here rather than
            // inventing an adapter-specific "only log completion" policy. This is the
            // point where the object actually lands, so it's the most meaningful entry.
            $this->logAudit($claims, 'chunk_upload', $disk, $key);
            $this->dispatchWebhook($claims, 'chunk_upload', ['disk' => $disk, 'path' => $key, 'name' => basename($key)]);

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
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
            }

            $body     = $this->body($request);
            $disk     = $body['disk'] ?? null;
            $key      = $body['key'] ?? null;
            $uploadId = $body['upload_id'] ?? null;

            if (!$disk || !$key || !$uploadId) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }

            $chunker = new ChunkUploader($this->diskManager);

            $key = $this->fileManager($claims)->validateScopedPath($key);
            $result = $chunker->abort($disk, $key, $uploadId);
            // Matches core's behavior — see the comment on handleChunkComplete() above.
            $this->logAudit($claims, 'chunk_upload', $disk, $key);
            $this->dispatchWebhook($claims, 'chunk_upload', ['disk' => $disk, 'path' => $key, 'name' => basename($key)]);

            return $this->ok($result);
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
        $dbg = getenv('FLUXFILES_DEBUG');
        $debugUi = (defined('FLUXFILES_DEBUG') && FLUXFILES_DEBUG)
            || in_array(strtolower((string) ($dbg === false ? '' : $dbg)), ['1', 'true', 'yes', 'on'], true);
        // Prefer the pre-minified fm.min.js / fm.min.css when the bundled core ships
        // them (older cores don't -> fall back to the sources); debug forces sources.
        $pick = static function (string $file) use ($assetsDir, $debugUi): string {
            $min = (string) preg_replace('/\.(js|css)$/', '.min.$1', $file);
            return (!$debugUi && $assetsDir && is_file("$assetsDir/$min")) ? $min : $file;
        };
        $hash = static function (string $file) use ($assetsDir): string {
            return $assetsDir && is_file("$assetsDir/$file") ? substr(md5_file("$assetsDir/$file"), 0, 8) : 'x';
        };
        $jsFile  = $pick('fm.js');
        $cssFile = $pick('fm.css');
        $jsSep  = strpos($assetsBase, '?') !== false ? '&' : '?';
        $html = str_replace('"../assets/fm.css"', '"' . esc_url($assetsBase . '/' . $cssFile) . $jsSep . 'v=' . $hash($cssFile) . '"', $html);
        $html = str_replace('"../assets/fm.js"',  '"' . esc_url($assetsBase . '/' . $jsFile)  . $jsSep . 'v=' . $hash($jsFile)  . '"', $html);
        // Vendored Alpine (core >= the release that stopped loading it from unpkg).
        $alpine = 'vendor/alpine/alpine.min.js';
        $html = str_replace('"../assets/' . $alpine . '"', '"' . esc_url($assetsBase . '/' . $alpine) . $jsSep . 'v=' . $hash($alpine) . '"', $html);

        // Bypass REST JSON serialization — the browser iframe must receive raw HTML
        // with the right content type, not a JSON-wrapped string.
        $this->sendRaw($html, 'text/html; charset=utf-8', self::uiHtmlHeaders($locale));
    }

    /**
     * Response headers for the iframe UI — the same posture core's index.php sends:
     *  - `Referrer-Policy: no-referrer`: the dev-only `?token=` form must never leak
     *    via Referer (Origin, which the CSRF check uses, is unaffected);
     *  - `frame-ancestors`: clickjacking. Core has no safe default (it is embedded
     *    cross-origin by design), but this UI is only ever framed by the shortcode /
     *    media button / block on THIS site, so default to self + the site's own
     *    origins. A headless front-end on another origin widens it with the
     *    `fluxfiles_frame_ancestors` filter ('' drops the header).
     *
     * @return list<string>
     */
    public static function uiHtmlHeaders(string $locale): array
    {
        $headers = [
            'Content-Language: ' . $locale,
            'Cache-Control: no-cache, must-revalidate',
            'Referrer-Policy: no-referrer',
            'X-Content-Type-Options: nosniff',
        ];
        $sources = ["'self'"];
        foreach ([function_exists('home_url') ? home_url('/') : '', function_exists('site_url') ? site_url('/') : ''] as $u) {
            $parts = parse_url((string) $u);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
                if (!in_array($origin, $sources, true)) {
                    $sources[] = $origin;
                }
            }
        }
        $frameAncestors = trim((string) apply_filters('fluxfiles_frame_ancestors', implode(' ', $sources)));
        // Same character allow-list as core: a filter must not be able to smuggle a
        // second directive (`;`) or a header break into the policy.
        if ($frameAncestors !== '' && preg_match('/^[A-Za-z0-9 :\/\.\*\-\']+$/', $frameAncestors)) {
            $headers[] = 'Content-Security-Policy: frame-ancestors ' . $frameAncestors;
        }
        return $headers;
    }

    public function serveUiAsset(\WP_REST_Request $request)
    {
        $file = (string) $request->get_param('file');
        // Regex on the route already restricts to fm[.min].js / fm[.min].css / vendor/*.{js,css}.
        $assetsDir = FluxFilesPlugin::corePath('assets');
        if ($assetsDir === null) {
            return $this->error('Assets not bundled', 500);
        }
        $path = $assetsDir . '/' . $file;
        $real = realpath($path);
        // Trailing separator on the base, so a sibling dir whose name merely
        // starts with it (…/assets-x) cannot pass the prefix test.
        $realBase = realpath($assetsDir);
        if ($real === false || $realBase === false
            || strncmp($real, $realBase . '/', strlen($realBase) + 1) !== 0
            || !is_file($real)) {
            return $this->error('Asset not found', 404);
        }
        $mime = substr($file, -3) === '.js' ? 'application/javascript' : 'text/css';
        // The UI page links `?v=<content hash>`: immutable only when it matches the file
        // served here; otherwise a short cache + a cheap mtime+size ETag and 304.
        [$headers, $notModified] = self::assetCaching(
            $real,
            self::assetVersionMatches($real, $realBase, (string) $request->get_param('v')),
            (string) ($request->get_header('if_none_match') ?? '')
        );
        if ($notModified) {
            status_header(304);
            foreach ($headers as $h) {
                header($h);
            }
            exit;
        }
        $this->sendRaw(file_get_contents($real), $mime . '; charset=utf-8', $headers);
    }

    /**
     * Hash memo keyed by path + mtime + size (so a changed file is a new key): per
     * request always, and across requests through APCu when it is enabled - the
     * unauthenticated asset route must not re-read and re-hash files on every hit,
     * 304 revalidations included.
     */
    private static function memoAssetHash(string $key, callable $compute): string
    {
        static $mem = [];
        if (isset($mem[$key])) {
            return $mem[$key];
        }
        $apcu = function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);
        if ($apcu) {
            $hit = apcu_fetch('fluxfiles_asset_' . md5($key), $ok);
            if ($ok && is_string($hit)) {
                return $mem[$key] = $hit;
            }
        }
        $val = $compute();
        if ($apcu && $val !== '') {
            apcu_store('fluxfiles_asset_' . md5($key), $val, 3600);
        }
        return $mem[$key] = $val;
    }

    /**
     * Does ?v= name the file that is actually being served? Only then is the URL
     * safe to cache as immutable: a stale node mid-rolling-deploy, or a hand-typed
     * v, would otherwise pin the wrong bytes for a year. Accepts a prefix (>= 8
     * hex) of the file's md5 (fm.js / fm.css / Alpine, as the page links them), or
     * - for the lazily loaded vendor/xterm and vendor/codemirror files - of that
     * vendor tree's content hash (fm.js's FM_VENDOR_VER; same algorithm as
     * scripts/vendor-ver.mjs). Needs no newer core API: it only reads the files.
     */
    public static function assetVersionMatches(string $filePath, string $assetsDir, string $v): bool
    {
        if (!preg_match('/^[0-9a-f]{8,32}$/', $v)) {
            return false;
        }
        $len = strlen($v);
        $st = @stat($filePath);
        $md5 = $st === false ? false : self::memoAssetHash($filePath . '|' . $st['mtime'] . '|' . $st['size'], static fn () => (string) @md5_file($filePath));
        if ($md5 !== false && $md5 !== '' && hash_equals(substr($md5, 0, $len), $v)) {
            return true;
        }
        $base = rtrim($assetsDir, '/');
        if (strncmp($filePath, $base . '/', strlen($base) + 1) === 0
            && preg_match('#^vendor/(xterm|codemirror)/#', substr($filePath, strlen($base) + 1), $m)) {
            $dir = $base . '/vendor/' . $m[1];
            $files = [];
            $sig = '';
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
                    $files[] = $rel;
                    $sig .= $rel . ':' . $f->getMTime() . ':' . $f->getSize() . ';';
                }
            }
            // Stat-only signature: the (~700 KB) tree is re-read and re-hashed only when a file changed.
            $tree = self::memoAssetHash('tree|' . $dir . '|' . md5($sig), static function () use ($files, $dir): string {
                sort($files, SORT_STRING);
                $h = hash_init('md5');
                foreach ($files as $r) {
                    hash_update($h, $r . "\0");
                    hash_update($h, (string) file_get_contents($dir . '/' . $r));
                    hash_update($h, "\0");
                }
                return hash_final($h);
            });
            return hash_equals(substr($tree, 0, $len), $v);
        }
        return false;
    }

    /**
     * Cache headers for a UI asset. $versioned is true only when `?v=` matched the
     * served file (assetVersionMatches()).
     *
     * @return array{0:string[],1:bool} [headers, notModified]
     */
    public static function assetCaching(string $real, bool $versioned, string $ifNoneMatch): array
    {
        $etag = '"' . dechex((int) filemtime($real)) . '-' . dechex((int) filesize($real)) . '"';
        if ($versioned) {
            return [['Cache-Control: public, max-age=31536000, immutable', 'ETag: ' . $etag], false];
        }
        $match = false;
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || (str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate) === $etag) {
                $match = true;
                break;
            }
        }
        return [['Cache-Control: public, max-age=300, must-revalidate', 'ETag: ' . $etag], $match];
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
