# FluxFiles — Configuration Reference

**The single source of truth for all FluxFiles configuration.** Two layers:

1. **JWT claims** — *per-token / per-tenant* config the operator mints into each token
   (the token **is** the config; the server is stateless). Listed in §2.
2. **Server env vars** — *server-wide* settings (secret, kill-switches, defaults).
   Listed in §3.

> Every claim is **sanitized/clamped on decode** by `Claims::fromJwtPayload`, so a bad
> value can never break the server — it falls back to the default.

---

## 1. How to set claims — one options object

The recommended way is **one options array** (no more 16 positional args). Any claim
that doesn't have a friendly option goes in the **`claims`** escape hatch by its raw
(snake_case) name — so nothing is unsettable, and all config lives in one place.

**PHP (standalone):**
```php
$token = fluxfiles_token([
    'user'   => 'user-42',
    'perms'  => ['read', 'write', 'delete'],
    'disks'  => ['sftp'],
    'prefix' => 'sites/acme/',
    'ttl'    => 1800,
    'edition'=> 'pro',                 // optional tier preset
    'webp'   => ['webp_max_width' => 1500],
    'media'  => ['preview_url_ttl' => 7200],
    'claims' => [                       // ← any claim by its raw name
        'allow_terminal'   => true,
        'terminal_pty_url' => 'https://term.acme.com/',
        'allow_optimize'   => true,
        'upload_collision' => 'overwrite',
    ],
]);
```
The **legacy positional form** still works (backward compatible), with an optional
final `$extra` array as the same escape hatch:
`fluxfiles_token('u', ['read'], ['local'], '', 10, null, 3600, …, $extra)`.

**Node (`@fluxfiles/node`):** `createToken({ userId, perms, …, claims: { … } })`
**Laravel:** `FluxFiles::token($user, ['perms' => …, 'claims' => […]])`
**WordPress:** `FluxFilesPlugin::token($userId, ['claims' => […]])`

All four accept the same `claims` map.

---

## 2. JWT claims

### 2.1 Identity & token (set automatically by the helpers)
| Claim | Type | Default | Notes |
|---|---|---|---|
| `sub` | string | `"0"` | User id (from `user`/`userId`). |
| `iat` | int (unix s) | now | Issued-at. |
| `exp` | int (unix s) | `iat + ttl` | Expiry; `ttl` is a helper param (seconds), not a claim. |
| `jti` | string | random | Token id. |
| `perms` | string[] | `["read"]` | `read`, `write`, `delete`. |
| `disks` | string[] | `["local"]` | Allowed disk names. |
| `prefix` | string | `""` | Path scope — all access sandboxed under this. |

### 2.2 Access & permissions
| Claim | Type | Default | Notes |
|---|---|---|---|
| `owner_only` | bool | `false` | Restrict delete/rename/move to the uploader. |
| `allow_download` | bool | `true` | `false` = preview-only (withholds `url`/`variants`, presign → 403). |
| `allow_chmod` | bool | `true` | Allow `POST /api/fm/chmod` on SFTP disks. |
| `allow_code_edit` | bool | `false` | Allow editing file text via `/api/fm/content` (config/code editor). |
| `allow_zip` | bool | `true` | Allow `POST /api/fm/zip`; also needs `allow_download`. |
| `allow_extract` | bool | `true` | Allow `POST /api/fm/extract`. |
| `zip_max_mb` | int (MB) | `1024` | Max total uncompressed size for a zip/extract (bomb cap). |
| `zip_max_files` | int | `10000` | Max file count for a zip/extract. |
| `allow_terminal` | bool | `false` | SSH command-runner on SFTP disks; needs `write`. Grants shell as the SSH user — opt-in. |
| `terminal_pty_url` | string (http/s) | `""` | Embed a self-hosted PTY server (ttyd/gotty/wetty) for an interactive terminal; empty → command-runner. Free. |
| `pdf_tools_url` | string (http/s) | `""` | Embed a self-hosted PDF toolkit (Stirling-PDF) — shows a "PDF tools" button. Empty → no button. Free BYO-embed. |
| `office_url` | string (http/s) | `""` | Embed a self-hosted office suite (Collabora/OnlyOffice) for .docx/.xlsx/.pptx…; may carry a `{url}` placeholder substituted with the selected file's URL. Empty → no action. Free BYO-embed. |
| `esign_url` | string (http/s) | `""` | Embed a self-hosted e-signature tool (DocuSeal) for signing PDFs/docs; may carry a `{url}` placeholder substituted with the selected file's URL. Empty → no action. Free BYO-embed. |
| `show_hidden` | bool | `false` | Show dotfiles / hidden entries in listings. |

### 2.3 Storage, quota & upload
| Claim | Type | Unit | Default | Notes |
|---|---|---|---|---|
| `max_upload` | int | MB | `10` | Max size per uploaded file. |
| `max_storage` | int | MB | `0` | Total quota under the prefix. `0` = unlimited. |
| `max_files` | int | count | `0` | Total file count under the prefix. `0` = unlimited. |
| `allowed_ext` | string[]\|null | — | `null` | Lowercase, no dot. `null` = all non-dangerous types (dangerous always blocked). |
| `upload_collision` | enum | — | `rename` | `rename` (keep both) / `reject` (409) / `overwrite`. |
| `dedupe_uploads` | bool | — | `false` | Refuse byte-identical re-uploads (SHA-256). Off = keep as a copy. |
| `variants` | object | px | — | Per-tenant WebP variant widths `{thumb,medium,large}` (16–8000). |

### 2.4 Rate limiting
| Claim | Type | Unit | Default | Notes |
|---|---|---|---|---|
| `rate_read` | int | req/min | `0` | `0` = inherit `FLUXFILES_RATE_LIMIT_READ`. |
| `rate_write` | int | req/min | `0` | `0` = inherit `FLUXFILES_RATE_LIMIT_WRITE`. |

### 2.5 On-demand images — WebP / AVIF / srcset (free; `GET /api/fm/img`)
| Claim | Type | Default | Notes |
|---|---|---|---|
| `webp_enabled` | bool | `true` | Expose `/api/fm/img` (image entries gain `img_base`). Requires a stream secret. |
| `webp_max_width` | int (px) | `2000` | Max resize width (clamped); bounds the cache-variant count. |
| `webp_default_quality` | int | `80` | Quality when omitted (snaps to 60/75/80/90). |
| `srcset_widths` | int[] (px) | `[320,640,768,1024,1366,1920]` | Responsive `srcset` ladder → `img_srcset`. |
| `srcset_sizes` | string | _(unset)_ | Emitted as `img_sizes` to pair with `img_srcset`. |

> Request-time params (not claims): `width`, `height`, `fit` (cover/contain), `dpr`
> (1/2/3), `quality`, `format` (`auto`→AVIF/WebP/original, or `avif`/`webp`).

### 2.6 Watermark — overlay (serve-time; free)
| Claim | Type | Default | Notes |
|---|---|---|---|
| `watermark_enabled` | bool | `false` | Overlay on `/api/fm/img`; forces preview-only. Source never modified. |
| `watermark_type` | enum | `text` | `text` or `logo`. |
| `watermark_text` | string | — | Text for `type=text`. |
| `watermark_logo_path` | string | — | Storage path to logo PNG for `type=logo` (missing → text fallback). |
| `watermark_position` | enum | `bottom-right` | `top-left`/`top-right`/`bottom-left`/`bottom-right`/`center`. |
| `watermark_opacity` | float | `0.6` | 0–1. |
| `watermark_font_size` | int (px) | `24` | 8–200 (text). |

> The **burn-in** watermark editor (`POST /api/fm/watermark`, permanent, with backup) is
> a separate free feature with no claim — it just needs `write`.

### 2.7 Media preview & streaming (free)
| Claim | Type | Unit | Default | Notes |
|---|---|---|---|---|
| `media_preview` | bool | — | `true` | Inline video/audio preview (else download link). |
| `preview_url_ttl` | int | s | `7200` | Presigned TTL for media (longer, so long videos don't 403). Cap 24h. |
| `max_preview_mb` | int | MB | `500` | Max media size eligible for inline preview. |
| `stream_token_ttl` | int | s | `3600` | TTL of the per-file stream token (gated local media, `FLUXFILES_LOCAL_PRIVATE`). |

### 2.8 Optimization (free/core; `POST /api/fm/optimize`)
| Claim | Type | Unit | Default | Notes |
|---|---|---|---|---|
| `allow_optimize` | bool | — | `false` | Opt-in (it replaces/deletes originals). 403 `optimize_forbidden` without it. |
| `auto_optimize` | bool | — | `false` | Recompress images → WebP on upload. |
| `optimize_quality` | int | 1–95 | `0` | `0` = default (82). |
| `optimize_keep_original` | bool | — | `false` | Keep the source (else replaced, needs `delete`). |
| `optimize_max_mb` | int | MB | `0` | Skip files larger than this. `0` = no limit. |
| `pdf_level` | enum | — | `ebook` | Ghostscript preset: `screen`/`ebook`/`printer`/`prepress`/`default`. |

### 2.9 AI auto-tag (free; BYO provider key)
| Claim | Type | Default | Notes |
|---|---|---|---|
| `ai_auto_tag` | bool\|null | `null` | `null` = inherit `FLUXFILES_AI_AUTO_TAG`; `true`/`false` overrides. |

### 2.10 URL import (free; off by default; `POST /api/fm/import-url`)
| Claim | Type | Unit | Default | Notes |
|---|---|---|---|---|
| `allow_url_import` | bool | — | `false` | Enable import-from-URL (SSRF-guarded). |
| `max_import_mb` | int | MB | `0` | Per-import size cap. `0` = default (50). |
| `import_url_allowlist` | string[] | host globs | — | Restrict to these hosts (e.g. `*.unsplash.com`). |
| `import_path` | string | path | — | Force imports into this path. |
| `import_rate_limit` | int | /min | `0` | `0` = inherit server default. |
| `import_concurrency` | int | — | `0` | Max concurrent imports. `0` = default. |

### 2.11 Usage dashboard (free; `GET /api/fm/usage`)
| Claim | Type | Unit | Default | Notes |
|---|---|---|---|---|
| `usage_cache_ttl` | int | s | `900` | `0` disables the cache. |
| `usage_warning_threshold` | int | % | `70` | Quota % → `warning`. |
| `usage_critical_threshold` | int | % | `90` | Quota % → `critical`. |
| `usage_top_folders_count` | int | — | `10` | Largest folders returned. |
| `usage_folder_depth` | int | — | `1` | Folder grouping depth. |

### 2.12 BYOB (Bring Your Own Bucket)
| Claim | Type | Notes |
|---|---|---|
| `byob_disks` | object | AES-256-GCM-encrypted per-disk S3/SFTP credentials; decrypted only at runtime. Use `fluxfiles_byob_token()`. |

### 2.13 Paid-module gates (inert unless the module is installed + licensed)
| Claim | Type | Default | Module |
|---|---|---|---|
| `allow_share` | bool | `false` | Branded Share. |
| `share_url_ttl` | int (s) | `60` | Lifetime of the presigned S3/R2 URL a share download redirects to. Clamped `[10, 300]`. On S3/R2 `max_downloads` counts **grants, not downloads** — a handed-out presigned URL stays fetchable until it expires, and this value bounds that window. Read at create time and baked into the share record. |
| `share_base_url` | string (http/s) | — | Public base the create response builds the recipient link from (e.g. `https://files.acme.com/public/share.html`). Non-http(s) dropped. Empty = the request origin + `/public/share.html` — i.e. derived from the `Host` header, so **set this explicitly behind a proxy/CDN** rather than trusting the forwarded host. |
| `share_preview` | bool | `true` | Allow the landing page to render an inline preview (images via `/api/fm/img`; PDFs only on uncapped shares — an `<iframe>` of the real bytes *is* a download). `false` = download-only. |
| `allow_intake` | bool | `false` | Intake / Upload Portals (public "send us your files" links). |
| `intake_base_url` | string (http/s) | — | Public base the intake create response builds the portal link from (e.g. `https://files.acme.com/public/intake.html`). Non-http(s) dropped. Empty = the request origin + `/public/intake.html` — i.e. derived from the `Host` header, so **set this explicitly behind a proxy/CDN**. Mirrors `share_base_url`. |
| `allow_versioning` | bool | `false` | File version history (keep prior versions on overwrite; list/restore). |
| `versioning_max` | int | `10` | Prior versions kept per file (hard cap 100). `0` = default. |
| `versioning_max_mb` | int (MB) | `25` | Skip versioning files bigger than this. `0` = default. |
| `allow_webhooks` | bool | `false` | Signed HTTP events on file changes (upload/delete/move…). |
| `webhook_url` | string (http/s) | — | Endpoint the signed event POST is sent to. Non-http dropped. |
| `webhook_events` | string[] | _(all)_ | Only these event names fire the webhook. Empty = all. |
| `webhook_secret` | string | — | HMAC signing secret (`X-FluxFiles-Signature`). Empty = `FLUXFILES_SECRET`. |
| `allow_ai_vision` | bool | `false` | AI Vision (BYO-key). |
| `allow_ocr` | bool | `false` | OCR. |
| `allow_virus_scan` | bool | `false` | Virus scan (Enterprise). |
| `allow_backup` | bool | `false` | Backup Bridge. |
| `allow_c2pa` | bool | `false` | C2PA provenance (Enterprise). |

> The `edition` preset (`pro`/`agency`/`enterprise`) defaults some of these on; an
> explicit claim always wins. The **license** still gates the actual code.

---

## 3. Server env vars (server-wide)

| Env var | Default | Notes |
|---|---|---|
| `FLUXFILES_SECRET` | — | **Required.** HS256 signing secret (**≥ 32 bytes**) — also used for stream/img per-file tokens. |
| `FLUXFILES_STORAGE_PATH` | — | Local disk root. |
| `FLUXFILES_ALLOWED_ORIGINS` | — | CORS allow-list for the embed. |
| `FLUXFILES_LOCALE` | `en` | Default UI locale. |
| `FLUXFILES_RATE_LIMIT_READ` / `_WRITE` | 60 / 10 | Per-user req/min defaults (claims override). |
| `FLUXFILES_LOCAL_PRIVATE` | `false` | Serve local media through `/api/fm/stream` (token-gated) instead of static URLs. |
| `FLUXFILES_XACCEL` | — | nginx `X-Accel-Redirect` internal location for the stream fast-path. |
| `FLUXFILES_TERMINAL_DISABLED` | `false` | Server kill-switch for the SSH terminal. |
| `FLUXFILES_TERMINAL_CONFIRM` | `true` | `false` disables the dangerous-command double-confirm. |
| `FLUXFILES_TERMINAL_TIMEOUT` | `30` | Per-command timeout (seconds). |
| `FLUXFILES_AI_PROVIDER` / `_MODEL` / `_API_KEY` | — | AI vision/tagging provider config (server-side; BYO key). |
| `FLUXFILES_AI_AUTO_TAG` | `false` | Default for `ai_auto_tag` (claim overrides). |
| `FLUXFILES_IMPORT_ALLOW_SVG` | `false` | Allow SVG via URL import. |
| `FLUXFILES_IMPORT_MAX_MB` / `_RATE_LIMIT` / `_TIMEOUT` | — | URL-import server defaults. |
| `FLUXFILES_SSRF_ALLOW_HOSTS` | — | SSRF allow-list (BYOB + import). |
| `FLUXFILES_SHARE_RATE_LIMIT` | `60` | Public share requests/min per share id (`share/info` + `share/file`). |
| `FLUXFILES_SHARE_UNLOCK_LIMIT` | `5` | Share password attempts/min per share id **+ client IP**. Stops one guesser; an attacker can rotate `REMOTE_ADDR`, so it is never the only limit. |
| `FLUXFILES_SHARE_UNLOCK_TOTAL` | `30` | Share password attempts/min per share id, **no IP component** — the ceiling IP rotation can't escape. Keep it comfortably above a shared-office NAT (all recipients behind one IP share the per-IP bucket); lower it for high-value links. |
| `FLUXFILES_LICENSE_KEY` | — | Signed license for paid modules (offline-verified). |
| `FLUXFILES_UPDATE_URL` | — | Vendor update server for `fluxfiles update <module>` (paid). |
| `FLUXFILES_DEMO` | `false` | Public "try it live" mode: `/public/` mints a hardened per-visitor token (own `demo/<id>/` sandbox, images only, small caps, owner-only, dangerous claims off) injected as `window.__FM_BOOT__` — safe to embed by iframe on a marketing site. |
| `FLUXFILES_DEMO_TTL_HOURS` | `6` | Demo sandbox lifetime + token TTL; older sandboxes auto-purge. |
| `FLUXFILES_DEMO_MAX_MB` / `_QUOTA_MB` / `_MAX_FILES` | `5` / `50` / `30` | Demo per-file size / total quota / file-count caps. |
| `FLUXFILES_DEMO_TOTAL_MB` | `2000` | Global demo disk budget across ALL sandboxes; purge deletes oldest first when over. |
| `FLUXFILES_DEMO_IP_MINTS` | `20` | Max NEW sandboxes one IP may mint per hour (anti sandbox-spam; returning visitors with a cookie are never throttled). Demo mode also **hard-strips S3/R2/SFTP** disks → local-only, zero egress cost. |

---

> **Keeping this in sync:** a guard test (`tests/unit/test-config-doc.php`) fails if a
> claim parsed in `Claims.php` is missing from §2 here — so this reference can't drift.
