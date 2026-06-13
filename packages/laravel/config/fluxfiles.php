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
        ],
        's3' => [
            'driver' => 's3',
            'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
            'bucket' => env('AWS_BUCKET', ''),
            'key'    => env('AWS_ACCESS_KEY_ID', ''),
            'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
        ],
        'r2' => [
            'driver'   => 's3',
            'endpoint' => 'https://' . env('R2_ACCOUNT_ID', '') . '.r2.cloudflarestorage.com',
            'region'   => 'auto',
            'bucket'   => env('R2_BUCKET', ''),
            'key'      => env('R2_ACCESS_KEY_ID', ''),
            'secret'   => env('R2_SECRET_ACCESS_KEY', ''),
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
    | Provider: 'claude' or 'openai' (leave empty to disable).
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
    'ai_auto_tag'  => env('FLUXFILES_AI_AUTO_TAG', false),
];
