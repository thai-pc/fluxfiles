<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JWT Secret
    |--------------------------------------------------------------------------
    |
    | The shared secret used to sign JWT tokens for FluxFiles authentication.
    | Must be at least 32 characters for HS256.
    |
    */
    'secret' => env('FLUXFILES_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | FluxFiles Endpoint
    |--------------------------------------------------------------------------
    |
    | The URL where FluxFiles is served. When using "proxy" mode, this is
    | auto-resolved from your Laravel app URL + route prefix.
    | When using "standalone" mode, set this to your FluxFiles server URL.
    |
    */
    'endpoint' => env('FLUXFILES_ENDPOINT', ''),

    /*
    |--------------------------------------------------------------------------
    | Storage Backend
    |--------------------------------------------------------------------------
    |
    | "json" — metadata/search/folder-index/trash/audit live in the storage
    |          disk's own `_fluxfiles/*.json` sidecars (default, matches core
    |          standalone's default).
    | "db"   — the same data lives in your Laravel app's database, via
    |          LaravelDbMetadataHandler + the fluxfiles_* tables published by
    |          `php artisan vendor:publish --tag=fluxfiles-migrations`.
    | `db_connection` lets FluxFiles' tables live on a different named
    | connection (config/database.php) than your app's default; null = the
    | app's default connection.
    |
    */
    'storage_backend' => env('FLUXFILES_STORAGE_BACKEND', 'json'),
    'db_connection'   => env('FLUXFILES_DB_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | "proxy" — FluxFiles API runs through Laravel routes (no separate server).
    | "standalone" — FluxFiles runs on its own server; Laravel only generates
    |                tokens and embeds the iframe.
    |
    */
    'mode' => env('FLUXFILES_MODE', 'proxy'),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | The URL prefix for FluxFiles routes when running in proxy mode.
    |
    */
    'route_prefix' => 'api/fm',

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to FluxFiles routes in proxy mode.
    |
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins (CORS)
    |--------------------------------------------------------------------------
    |
    | Origins allowed to access the FluxFiles API. Only used in proxy mode.
    | Set to ['*'] to allow all origins, or specify domains.
    |
    */
    'allowed_origins' => array_filter(
        explode(',', env('FLUXFILES_ALLOWED_ORIGINS', ''))
    ),

    /*
    |--------------------------------------------------------------------------
    | UI framing (CSP frame-ancestors)
    |--------------------------------------------------------------------------
    |
    | Who may frame /public/index.html. Empty = "'self'" plus allowed_origins
    | above; both empty = no header (any site may frame it). Same as core's
    | FLUXFILES_FRAME_ANCESTORS.
    |
    */
    'frame_ancestors' => env('FLUXFILES_FRAME_ANCESTORS', ''),

    /*
    |--------------------------------------------------------------------------
    | Per-user claims resolver
    |--------------------------------------------------------------------------
    |
    | The token policy for each user, used by every token minted WITHOUT an
    | explicit overrides array: tokenForUser(), <x-fluxfiles>, @fluxfilesToken,
    | the GET {route_prefix}/token refresh route and the session-only fallback in
    | the FluxFilesAuth middleware. A class name implementing
    | FluxFiles\Laravel\ClaimsResolver (resolved from the container), an
    | invokable class, or a callable — `fn ($user) => ['prefix' => "users/{$user->id}"]`.
    | Prefer a class name (closures can't be `config:cache`d), or register one at
    | runtime with FluxFiles::resolveClaimsUsing(...) in a service provider.
    |
    | null = no per-user policy: the refresh route then only re-mints from the
    | component's signed refresh ticket, and session-only API requests (no
    | Bearer) are refused instead of getting the config `defaults` below.
    |
    */
    'claims_resolver' => null,

    // Lifetime (seconds) of the signed refresh ticket <x-fluxfiles> hands its
    // default onTokenRefresh hook — how long a page left open can keep re-minting
    // its token from the Laravel session. Default 12 hours.
    'refresh_ticket_ttl' => 43200,

    /*
    |--------------------------------------------------------------------------
    | Server environment for core + the paid modules
    |--------------------------------------------------------------------------
    |
    | Core and the paid modules read their server settings straight from the
    | process environment ($_ENV / getenv()): FLUXFILES_LICENSE_KEY, the module
    | engines (FLUXFILES_AIVISION_*, FLUXFILES_DLP_ENDPOINT, FLUXFILES_C2PA_MANIFEST,
    | FLUXFILES_WEBHOOK_ALLOW_INTERNAL, …) and kill switches such as
    | FLUXFILES_TERMINAL_DISABLED. After `php artisan config:cache` Laravel no
    | longer loads .env at runtime, so values kept only in .env vanished: every
    | paid module answered 402 (unlicensed) and a kill switch silently turned off.
    | Every FLUXFILES_* value present when config is loaded is captured here (so it
    | lands in the config cache) and put back into $_ENV at boot for any key the
    | real environment doesn't already set — a real env var always wins.
    |
    */
    'server_env' => array_filter(
        array_merge(getenv() ?: [], $_ENV),
        static fn ($v, $k) => is_string($k) && str_starts_with($k, 'FLUXFILES_') && is_scalar($v),
        ARRAY_FILTER_USE_BOTH
    ),

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | Path to FluxFiles runtime files such as rate-limit state.
    |
    */
    'storage_path' => env('FLUXFILES_STORAGE_PATH', storage_path('fluxfiles')),

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute, per user)
    |--------------------------------------------------------------------------
    |
    | Server-wide defaults. A token may raise/lower them per tenant with the
    | `rate_read` / `rate_write` claims (0 in the claim = inherit these).
    |
    */
    'rate_limit_read'  => (int) env('FLUXFILES_RATE_LIMIT_READ', 60),
    'rate_limit_write' => (int) env('FLUXFILES_RATE_LIMIT_WRITE', 10),

    // The /img and /stream endpoints authenticate on a per-file token, not the
    // main JWT, so they get their own per-`sub` buckets instead of the read
    // budget above. Generous by design: a gallery page fires dozens of /img
    // requests, and a seeking <video> one /stream Range request per seek. Set
    // either to 0 to disable that bucket.
    'rate_limit_img'    => (int) env('FLUXFILES_IMG_RATE_LIMIT', 120),
    'rate_limit_stream' => (int) env('FLUXFILES_STREAM_RATE_LIMIT', 300),

    // Dedicated buckets, matching core. URL import makes an outbound request per
    // call (a token's `import_rate_limit` claim overrides this); /usage and
    // /duplicates list the whole prefix per call. 0 disables a bucket.
    'rate_limit_import' => (int) env('FLUXFILES_IMPORT_RATE_LIMIT', 10),
    'rate_limit_usage'  => 2,

    /*
    |--------------------------------------------------------------------------
    | Disks
    |--------------------------------------------------------------------------
    |
    | Storage disk configurations for FluxFiles.
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root'   => env('FLUXFILES_LOCAL_ROOT', storage_path('app/public/fluxfiles/uploads')),
            'url'    => '/storage/fluxfiles/uploads',
            // true = files are NOT served from the public 'url' above; the UI's
            // list() marks them gated and the embedded client fetches bytes via
            // the signed `/api/fm/stream` route instead (mirrors core's
            // FLUXFILES_LOCAL_PRIVATE). Only matters once 'root' is not web-served.
            'private' => (bool) env('FLUXFILES_LOCAL_PRIVATE', false),
        ],
        's3' => [
            'driver'     => 's3',
            'region'     => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
            'bucket'     => env('AWS_BUCKET', ''),
            'key'        => env('AWS_ACCESS_KEY_ID', ''),
            'secret'     => env('AWS_SECRET_ACCESS_KEY', ''),
            // Custom S3-compatible endpoint (MinIO / DO Spaces). Empty = native AWS.
            'endpoint'   => env('AWS_ENDPOINT', ''),
            // 'private' = presigned GET URLs (default); 'public' = direct object URLs.
            'visibility' => env('AWS_VISIBILITY', 'private'),
            // CDN / custom-domain base for a public disk, e.g. https://cdn.example.com
            'public_url' => env('AWS_PUBLIC_URL', ''),
            // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h, max 24h.
            'url_ttl'    => (int) env('AWS_URL_TTL', 3600),
        ],
        'r2' => [
            'driver'     => 's3',
            'endpoint'   => 'https://' . env('R2_ACCOUNT_ID', '') . '.r2.cloudflarestorage.com',
            'region'     => 'auto',
            'bucket'     => env('R2_BUCKET', ''),
            'key'        => env('R2_ACCESS_KEY_ID', ''),
            'secret'     => env('R2_SECRET_ACCESS_KEY', ''),
            // 'public' needs a public bucket + public_url (r2.dev / custom domain).
            'visibility' => env('R2_VISIBILITY', 'private'),
            'public_url' => env('R2_PUBLIC_URL', ''),
            // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h, max 24h.
            'url_ttl'    => (int) env('R2_URL_TTL', 3600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | FluxFiles Base Path
    |--------------------------------------------------------------------------
    |
    | Path to the FluxFiles installation directory (where public/, assets/,
    | and fluxfiles.js live). Only used in proxy mode for serving static assets.
    |
    */
    'base_path' => env('FLUXFILES_BASE_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Permissions
    |--------------------------------------------------------------------------
    |
    | Default JWT claims applied when generating tokens via the facade.
    | Can be overridden per-call.
    |
    */
    'defaults' => [
        'perms'       => ['read', 'write', 'delete'],
        'disks'       => ['local'],
        'prefix'      => '',
        'max_upload'  => 10,    // MB — max size per uploaded file
        'allowed_ext' => null,  // lowercase, no dot; null = all safe types
        'max_storage' => 0,     // MB — total quota per prefix (0 = unlimited)
        'max_files'   => 0,     // max number of files per prefix (0 = unlimited)
        'ttl'         => 3600,  // seconds — token lifetime
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Defaults
    |--------------------------------------------------------------------------
    |
    | Default picker/UI options for the <x-fluxfiles> Blade component. These are
    | NOT JWT claims — they configure the embedded picker. `multiple` enables
    | multi-select (onSelect then receives an array).
    |
    */
    'multiple' => false,

    /*
    |--------------------------------------------------------------------------
    | AI Auto-Tag
    |--------------------------------------------------------------------------
    |
    | Provider (leave empty to disable). Bring your own key — nothing is proxied:
    |   claude | anthropic   Anthropic Messages API
    |   gemini | google      Google Gemini
    |   openai               OpenAI
    |   openrouter, groq, mistral, xai|grok, ollama   OpenAI-compatible gateways
    |   compatible           any other OpenAI-compatible endpoint
    |
    | Model and base URL fall back to the provider's defaults when empty; both are
    | required with 'compatible' (a self-hosted Ollama/LiteLLM/vLLM or a gateway).
    | Auto-tag on upload: when true, images are automatically tagged on upload.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | Default locale for the FluxFiles UI. Supported: en, vi, zh, ja, ko,
    | fr, de, es, ar, pt, it, ru, th, hi, tr, nl. Default: en.
    |
    */
    'locale' => env('FLUXFILES_LOCALE', ''),

    'ai_provider'  => env('FLUXFILES_AI_PROVIDER', ''),
    'ai_api_key'   => env('FLUXFILES_AI_API_KEY', ''),
    'ai_model'     => env('FLUXFILES_AI_MODEL', ''),
    'ai_base_url'  => env('FLUXFILES_AI_BASE_URL', ''),
    'ai_auto_tag'  => env('FLUXFILES_AI_AUTO_TAG', false),
];
