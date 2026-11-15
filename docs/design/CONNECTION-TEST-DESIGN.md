# Connection test (AI + storage) — design

Status: proposed · Target: core 1.6.0, wordpress 1.3.0, laravel next · Free/core (no module)

> **Decision (owner, revised 2026-11-15):** `/ai/test`, the doctor `ai` block and the UI AI row use the same eligibility as manual `/ai-tag`: disk in token + `write` + AI configured. `ai_auto_tag` plays no part.

## 1. Problem & who pays

On 2026-06-15 Anthropic retired `claude-sonnet-4-20250514`, which was `AiTagger`'s default model
for `claude`/`anthropic`. From then on every install without `FLUXFILES_AI_MODEL` failed to tag,
and nobody noticed for five months, for two reasons:

- (a) There is no AI connection test anywhere: not in core, WP, Laravel or the CLI.
- (b) `AiTagger::httpPost()` logs the provider's error, then throws a generic
  `502 'AI service returned an error'` with no `error_code`. Auto-tag on upload swallows even that.

Storage has a partial answer, but with gaps:

- `BucketDoctor` exists, with no CLI.
- The WP Cloud-tab "Test connection" only lists one item.
- That WP test needs the disk to be saved **and** ticked as enabled.

**Who:** every operator persona. That means self-host operators, WP site admins and Laravel
developers. **Free/core**: this is diagnostics for a free feature (BYO-key AI tagging) and for
storage. There is no `ModuleRegistry` gate and no licence.

## 2. Architecture fit

- **Stateless.** A test runs on the request that asks for it: one outbound call, no state written.
  Nothing is scheduled. WP Site Health's own weekly cron is WordPress's mechanism, not ours.
- **No new claim, no new env var.** The gate reuses `disks` + `perms`, plus "a provider is
  configured" (server config, not a claim). This is the same eligibility as `/ai-tag`. The 2/min
  limit is hard-coded, like the `notify_test` 5/min bucket. `docs/reference/CONFIG.md` is unchanged.
- **Storage-resident:**
  - The only thing written to user storage is the audit entry, plus `BucketDoctor`'s existing
    probe under `_fluxfiles/.doctor/`, which is deleted at once.
  - The WP Site Health cache is a WP transient, i.e. host state in the WP options table, the same
    tier as the WP settings. FluxFiles never writes it to user storage.
- **One classifier.** Real tagging and the test share the same request builders and the same error
  classifier. That way a passing test means tagging will work, and a tagging failure gives the
  same code the test would.

## 3. JWT claims

**None added.** Claims read:

| Claim | Use |
|---|---|
| `perms` ∋ `write` | required for `/ai/test` and for the doctor `ai` block (as for `/ai-tag`) |
| `prefix` | `trim(prefix,'/') === ''` (unscoped, admin) ⇒ response may include the redacted provider `detail` |
| `disks` | the optional body `disk` (which disk's audit log gets the entry) must be allowed, as `/ai-tag` asserts its `disk` |

`ai_auto_tag` is **not** read. It still only controls tagging on upload.

## 4. Error classification (shared)

New `packages/core/api/AiError.php` (top-level `api/`, so `test-i18n.php`'s scan sees it):

- `final class AiError`: the code constants, the English `message`/`hint` per code,
  `classify()`, and `redact()`.
- `class AiException extends ApiException`: HTTP **502**, code from `AiError`, plus
  `error_params` = `{provider, model, default_model, retry_after?}`. It also carries the redacted
  provider detail, which goes to the log and is never put in the envelope.

**Load-bearing: never answer a provider failure with 401/403/429.**

- `fm.js api()` treats **401 as "our token expired"** and starts a token refresh.
- 429 is our own limiter's `rate_limited`.

So every classified upstream failure is 502 and the code tells the cases apart. `ai_not_configured`
keeps its 400.

| Code | Trigger (any provider unless noted) | Hint (en; i18n `ai_test.hint.<code>`) |
|---|---|---|
| `ai_not_configured` (exists) | no provider; unknown provider; `compatible`/openai-shape without base URL or model (`error_params.missing` = `base_url`\|`model`) | Set FLUXFILES_AI_PROVIDER (and BASE_URL/MODEL for `compatible`). |
| `ai_auth` | 401; 403; Gemini 400 with `details[].reason=API_KEY_INVALID` or "API key not valid"; OpenRouter `/key` 401 | Check the API key (and that it may use this model/region). |
| `ai_model_not_found` | 404 whose JSON names a model (`not_found_error`, `model_not_found`, `NOT_FOUND`); 400/422 with `model_decommissioned` or a model regex (`/model/i` + `not found\|does not exist\|not a valid model\|decommissioned\|retired\|no longer available`); Anthropic `lifecycle: retired` (`params.reason=retired`) | Clear the model setting to use the provider default ({default_model}), or enter a current vision model. |
| `ai_no_vision` | 400 matching `/(image\|vision\|image_url\|multimodal)/i` + `/(not support\|unsupported\|only supported\|invalid content type)/i`; `checkModel`: Anthropic `capabilities.image_input.supported === false`, Gemini without `generateContent`, OpenRouter `input_modalities` without `image` | This model does not accept images; choose a vision model. |
| `ai_quota` | 402; OpenAI 429 `insufficient_quota`; Gemini 429 with quota text; Anthropic 400 "credit balance is too low" | Add credits or raise the spending limit at the provider. |
| `ai_rate_limited` | other 429 (`retry_after` from the header) | Provider rate limit; retry later or lower upload volume. |
| `ai_unavailable` | 5xx, 529 (overloaded) | Provider outage/overload; retry later. |
| `ai_network` | transport `status 0` (DNS, TLS, timeout, refused) | Check the base URL, DNS, outbound firewall/proxy. |
| `ai_blocked_host` | `SsrfGuard` refused the URL (`ssrf_blocked`/`host_not_allowed`/`url_invalid`); WP only, §8 | Private/local host: add it to FLUXFILES_SSRF_ALLOW_HOSTS. |
| `ai_bad_response` | 2xx but not JSON, or missing the API's envelope (`content`/`candidates`/`choices`); analyze(): model text not JSON (today's "AI returned invalid JSON") | The endpoint is not speaking the expected API; check provider/base URL. |
| `ai_provider_error` | any other HTTP ≥ 400, incl. a 404 that does not name a model (a wrong base URL) | See detail; check base URL and model. |
| `ai_model_deprecated` (warning, not an error) | Anthropic `lifecycle: deprecated` (`params.retires_at`) | Still works; switch before {retires_at}. |

`AiError::redact(string $s, string $apiKey): string` does the following:

- Removes the literal key.
- Replaces these with `***`:
  - `/sk-[A-Za-z0-9_-]{6,}/` (covers `sk-ant-`, `sk-or-`)
  - `/AIza[0-9A-Za-z_-]{10,}/`
  - `/gsk_\w{10,}/`
  - `/xai-\w{10,}/`
  - `/bearer\s+\S+/i`
  - `/(api[_-]?key|key|token)=[^&\s]+/i`
  - URL userinfo `#(https?://)[^/@\s]+@#`
- Strips control chars, collapses whitespace, and cuts to **200 chars** + `…`.

Every `Log::warn` in `AiTagger` goes through `redact()`. Today's `httpPost` logs the provider's raw
message, which OpenAI fills with a partly masked key.

## 5. `AiTagger` changes (`packages/core/api/AiTagger.php`)

- Refactor each `analyze<Api>()` into `send<Api>(string $b64, string $mime, string $prompt,
  int $maxTokens): array` (the decoded envelope). `analyze()` calls it with `buildPrompt()` and
  `MAX_TOKENS`, and `testConnection()` calls it with a probe prompt and `16`. Both go over the same
  URL, headers and transport.
- `httpPost()` stays `protected` with the same signature (tests override it). It now calls
  `AiError::classify($api, $status, $body, $headers, $transportError)` and throws `AiException`.
  New `protected httpGet(string $url, array $headers): array` is used by `checkModel()`.
- `public function testConnection(): array` sends a **built-in 16×16 PNG** (`private const
  PROBE_PNG` base64, ~100 bytes) with the prompt "Reply with OK." and `max_tokens`/
  `maxOutputTokens` 16.
  - **Success** means HTTP 2xx plus a well-formed envelope. Text is **not** required, because
    thinking models can spend a 16-token budget and return empty text.
  - It never throws, and always returns the result shape.
- `public function checkModel(): array` does a cheap lookup and **never sends an image**:

| API | Request | Verdict |
|---|---|---|
| anthropic | `GET {base}/models/{model}` (`x-api-key`, `anthropic-version`; accepts aliases) | 404 → `ai_model_not_found`; `lifecycle=retired` → `ai_model_not_found` (reason retired); `deprecated` → ok + `warning: ai_model_deprecated` + `retires_at`; `image_input.supported===false` → `ai_no_vision`; missing `lifecycle` (older API version) → ok |
| gemini | `GET {base}/models/{model}` (`x-goog-api-key` header, never `?key=`) | 404 → not found; `supportedGenerationMethods` lacks `generateContent` → `ai_no_vision` |
| openai-shape (openai, groq, mistral, xai, ollama, compatible) | `GET {base}/models` (`Authorization` only when a key is set) | 200 + `data[].id` has the model (exact; Ollama also `model:latest`) → ok; 200 without it → `ai_model_not_found`; 404/405 on `/models` → ok with `verified:false` (gateway has no list); optional modality hints (Mistral `capabilities.vision`) → `ai_no_vision` |
| openrouter | `GET {base}/key` (auth; `/models` is public, so it proves nothing about the key) + `GET {base}/models/{model}/endpoints` | `/key` 401 → `ai_auth`; endpoints 404 → not found (a `~` alias that 404s → `verified:false`); `architecture.input_modalities` without `image` → `ai_no_vision` |

`checkModel` uses its own transport cap of 4 MB, because a model list can be large; anything over
the cap is `ai_bad_response`. Timeouts are 10 s for `checkModel` and 30 s for `testConnection`
(not 60 s).

- `public function describe(): array` returns `{provider, model, default_model}`. It does no
  network call and never includes the key or the base URL.
- `public function api(): ?string` returns the wire protocol, for the classifier.

**Result shape** (same for both checks):

```json
{ "ok": false, "check": "vision", "provider": "claude", "model": "claude-sonnet-4-20250514",
  "verified": true, "latency_ms": 203, "code": "ai_model_not_found",
  "params": { "model": "claude-sonnet-4-20250514", "default_model": "claude-sonnet-5-5", "reason": "retired" },
  "message": "The AI model was not found or has been retired",
  "hint": "Clear the model setting to use the provider default (claude-sonnet-5-5), or enter a current vision model.",
  "warning": null, "retires_at": null,
  "detail": "HTTP 404 not_found_error: model: claude-sonnet-4-20250514" }
```

The fields are:

- `check` is `vision` or `model`.
- `ok:true` means `code:null`.
- `detail` is the redacted provider text. The HTTP endpoint drops it for scoped tokens (§6).
- `message` and `hint` are English. Clients localize them by `code`.

**Tagging surfaces the code.**

- `POST /ai-tag` now returns the classified envelope instead of the generic 502 text:
  `{"data":null,"error":"The AI model was not found or has been retired","error_code":"ai_model_not_found","error_params":{"provider":"claude","model":"…","default_model":"claude-sonnet-5-5"}}`.
  `fm.js` already maps it through `error.<code>`, so the existing `ai.failed` toast becomes
  actionable with no JS change.
- Upload auto-tag still never fails the upload. It adds `"ai_error": "<code>"` to the upload
  result, and `fm.js` shows **one** warning toast per upload batch (first code). This touches
  `FileManager.php` ~1255.

## 6. Endpoints

**`POST /api/fm/ai/test`** (authenticated with the main JWT; operator or tenant token). Body:
`{"disk":"local"}` (optional; where the audit entry goes).

The checks mirror what gates `/ai-tag` today: the global write bucket in `index.php`, then
`FileManager::aiTag()`'s `assertDisk` → `assertPerm('write')` → "tagger is null". Core builds the
tagger whenever `FLUXFILES_AI_PROVIDER` is set (index.php ~350), with no claim involved. In order:

1. The global write bucket, then a new pre-route bucket `RateLimiterFactory::make(2, 2, 60, $dbConn)
   ->check($claims->userId, 'ai_test')`, next to the `notify_test` one (index.php ~405). Failure →
   `429 rate_limited`.
2. `disk` is in the token → `403 disk_denied` (the same code `/ai-tag` uses).
3. `write` perm → `403 permission_denied` (the same code `/ai-tag` uses).
4. Tagger configured → `400 ai_not_configured`.
5. Run `testConnection()`. If the token is scoped, unset `detail`. Return **200** with the result
   shape, whether `ok` or not. A failed test is a successful diagnosis, as with `BucketDoctor`.

All of this lives in a shared `packages/core/api/AiTestEndpoint.php`, the same pattern as
`NotifyEndpoint`:

- `AiTestEndpoint::run(Claims $c, ?AiTagger $t, array $body): array` covers checks 2–5.
- `AiTestEndpoint::doctorBlock(Claims $c, ?AiTagger $t): ?array` is used by the doctor `ai` block
  below.

Core, Laravel and WP each call these in one line.

Audit and webhook:

- `AuditPolicy::ACTIONS` gets `'/ai/test' => 'ai_test'`. That needle shadows nothing.
- `AuditPolicy::detail('ai_test')` = `"ok claude/claude-sonnet-5-5 812ms"` or
  `"ai_auth claude/claude-sonnet-5-5"`. It never contains the key, detail or base URL.
- The audit key is `$claims->scopePath('')`, as for `fixity_check`, so a tenant sees its own test.
- It fires the generic write webhook, as `notify_test` does today. There is no special case.

**`GET /api/fm/disk/doctor`** gets an additive `ai` field. It is present only when the token has
`write` **and** a provider is configured, and it makes no network call:

- `"ai": {"configured": true, "provider": "claude", "model": "claude-sonnet-5-5"}`

Otherwise the field is absent.

**Proxies.**

- Laravel: `Route::post('ai/test', …aiTest)`.
- WP: `register_rest_route($ns, $p.'/ai/test', $writeArgs + handleAiTest)`.
- Both use `rateLimitBucket('ai_test', 2)`, the `AiTestEndpoint` helpers and `logAuditQuiet('ai_test')`.
- Both doctor handlers append `AiTestEndpoint::doctorBlock()`.
- Each proxy passes the same tagger its `/ai-tag` handler uses:
  - Laravel: `aiTagger()`, built whenever configured.
  - WP: built from the saved options, as `handleAiTag` does. Not `FluxFilesApi::aiTagger($claims)`,
    which returns null unless `ai_auto_tag`.
- `api-map.md`: the route is proxied, so the "not proxied" list is unchanged.

**WP-only (admin-ajax, not part of the proxy surface).**

- `wp_ajax_fluxfiles_test_ai`
- `wp_ajax_fluxfiles_test_disk` (upgraded)
- REST `fluxfiles/v1/site-health/{storage|ai}`, with permission `view_site_health_checks`

These are covered in §8.

## 7. Storage layout

There are no new `_fluxfiles/` files:

- The audit entry goes to `_fluxfiles/audit.jsonl`, or the DB audit table with the db backend, as
  today.
- `BucketDoctor`'s probe (`_fluxfiles/.doctor/<hex>.txt`) is unchanged.
- WP transients (host-side): `fluxfiles_health_ai` and `fluxfiles_health_storage`.
  - Value: `{result, at}`.
  - They never hold the key or the provider detail.
  - Any `update_option_fluxfiles_ai_*` (provider, key, model, base_url) or
    `update_option_fluxfiles_{s3,r2,sftp}_*` / `fluxfiles_disks` deletes them.

## 8. Clients

**Core UI** (`public/index.html` Doctor modal, `assets/fm.js`, then `bash scripts/build-ui.sh`):

- Below the storage checks, an "AI tagging" row appears when `doctorReport.ai` exists (write + AI
  configured). It shows `provider · model` and a **Test connection** button.
- The button calls `POST /api/fm/ai/test {disk: currentDisk}`. It never runs on its own when the
  modal opens, because each run is a paid call and the limit is 2/min.
- It renders:
  - ✓ `ai_test.ok` `{ms}`
  - ✗ `error.<code>` + `ai_test.hint.<code>` (+ `detail` in a `<pre>` when present)
  - ⚠ `ai_test.deprecated` `{retires_at}`
- New state: `aiTestResult`, `aiTesting`.

**Core CLI** (`packages/core/bin/fluxfiles`; add both to the usage block):

- `fluxfiles doctor [disk] [--origin=https://app.example] [--json]`. Default disk is `local`. It
  runs `BucketDoctor` on a configured disk (`DiskConfig::load`; BYOB disks are token-borne and out
  of scope). It prints `OK|WARN|FAIL  <id> - message` plus `fix:` lines.
  - Exit codes: 0 = ok or warnings only, 1 = usage/config, 2 = any `fail`.
  - It writes no audit entry: there are no claims, and the operator shell is already trusted.
- `fluxfiles ai:test [--cheap] [--json]` builds `AiTagger` from env. It runs `testConnection()`, or
  `checkModel()` with `--cheap`, and prints provider/model/latency/code/hint/detail. It never prints
  the key or the base URL.
  - Exit codes: 0 ok, 1 not configured/usage, 2 failed.

**Laravel**:

- `fluxfiles:doctor` gains `{--ai : Also run a live AI connection test (one tiny vision call)}`.
  It prints `OK ai - claude/claude-sonnet-5-5 (812 ms)` or `FAIL ai - ai_auth … fix: <hint>`, and
  a failure makes the command exit non-zero.
- The controller's `aiTagger()` accepts a container-bound `FluxFiles\HttpTransportInterface`, so
  the smoke test can inject a fake transport.
- No SSRF guard: like core, the base URL is operator config.

**WordPress** (`FluxFilesAdmin`, `assets/settings.js`, new `includes/FluxFilesSiteHealth.php`):

- **AI tab button**: AJAX action `fluxfiles_test_ai`, nonce `fluxfiles_test_ai` (localized next to
  `testNonce`), `manage_options`.
  - It tests the **saved** options. When the form is dirty it shows the existing `saveFirst` text.
    When no provider is saved it shows "Choose a provider and save first." It does **not** require
    "Tag on upload".
  - It is rate-limited to 2/min per WP user (bucket `ai_test`, the plugin's limiter).
  - It builds `AiTagger` with `new SsrfCheckedTransport(new FluxFilesHttpTransport())` and runs
    `testConnection()`.
  - Output: provider · model · ms, or a localized message + hint + redacted detail (the admin is
    the operator).
  - It writes the result into `fluxfiles_health_ai`.
  - The JS renders with `textContent` only.
- **Cloud tab test** (`ajaxTestDisk`, same action and nonce) changes in three ways:
  - It drops the "tick under Enabled disks" requirement.
  - It no longer mints a token or calls `rest_do_request`.
  - Instead it builds `new DiskManager([$disk => FluxFilesPlugin::diskConfigs()[$disk]])` from the
    saved options, which `diskConfigs()` already includes whether or not the disk is ticked.
  - **Why this is safe:** it is admin-only (nonce + `manage_options`), and no JWT exists, so nothing
    can be replayed or broadened. It runs exactly the credentials the admin saved, with the same
    SFTP `SsrfGuard::assertHostSafe` that `DiskManager::build()` already applies.
  - It runs `BucketDoctor($dm, new FluxFilesHttpTransport(), new FluxFilesUrlFetcher())->diagnose($disk,
    origin of home_url())`.
  - It returns `{summary, checks:[{id, status, label, message, fix}]}`. `label` comes from a
    `__()` map of check ids; message/fix stay English, as in the core UI.
  - It is limited to 5/min per user (bucket `disk_test`).
  - `disk_doctor` is audited best-effort with the user's normal token `sub`.
  - The JS renders a ✓/⚠/✗ list.
- **Site Health** (`site_status_tests` filter → `async`, each entry
  `{label, test: rest_url(...), has_rest: true, async_direct_test: callable}`):
  - `fluxfiles_storage`: for each **enabled** non-local disk, a read-only list (S3 `MaxKeys=1`,
    SFTP connect + list root). No probe write: Site Health runs on every screen visit and from WP's
    weekly cron. Results:
    - any failure → `critical`
    - all ok → `good`
    - no cloud disk → `good` "Local storage only"
    - cached 1 h
  - `fluxfiles_ai`: registered only when a provider is saved. It runs **`checkModel()` only**, which
    costs nothing.
    - `critical`: `ai_auth`, `ai_model_not_found`, `ai_no_vision`, `ai_blocked_host`,
      `ai_not_configured`.
    - `recommended`: `ai_quota`, `ai_rate_limited`, `ai_network`, `ai_unavailable`,
      `ai_bad_response`, `ai_provider_error`, and the `ai_model_deprecated` warning.
    - `good`: ok, including `verified:false` with a note to run the manual test.
    - Caching: a definite verdict is cached 12 h, a transient failure 15 min.
    - Actions link to the settings tab.
  - Every message is a WP `__()` string keyed by code (text domain `fluxfiles`, translated via
    WP.org, separate from core's `lang/*.json`).
- **WP.org constraints:**
  - nonces + capability on every AJAX/REST entry, `sanitize_key`/`wp_unslash` on input
  - no inline JS (extend the enqueued `settings.js`; Site Health needs no JS)
  - all output escaped
  - outbound HTTP via `wp_remote_request` (`FluxFilesHttpTransport`)
  - the AI call stays inside core `AiTagger`, which already carries the `PluginCheck…AIProvider`
    phpcs annotation; no new direct integration in WP code
  - claims forced off by `disableFileEditing()` stay untouched
  - the REST `/ai/test` route has the same eligibility as `handleAiTag`: `write` + a saved
    provider, regardless of "Tag on upload" / `ai_auto_tag`
- Also fix `handleAiTag`'s uncoded `ApiException('AI tagging is not configured', 400)` →
  `'ai_not_configured'`.

## 9. Security

- **SSRF.**
  - Core and Laravel: the base URL is operator env/config, the same posture as
    `FLUXFILES_AIVISION_ENDPOINT`, and Ollama on localhost must keep working. No guard.
  - WordPress: an admin enters the URL in a form, and the URL is already checked on save. New core
    `packages/core/api/SsrfCheckedTransport.php` (an `HttpTransportInterface` decorator) re-runs
    the same rule at call time:
    - allowlisted host (`SsrfGuard::isAllowlistedHost`), or else `SsrfGuard::assertSafeUrl`
    - a refusal surfaces as `ai_blocked_host`
    - it is wired for real tagging, the `/ai/test` proxy, the admin button and Site Health
  - Residual risk: DNS can change between the check and `wp_remote_request`, and WP's HTTP API
    cannot pin an IP. That is the same as today's tagging path, and it is documented.
  - When `SsrfCheckedTransport` is wired in WP, also reject any 3xx answer inside the decorator
    (defence in depth: today only the inner transports' no-redirect setting prevents a hop), and where
    the inner transport is cURL pin the checked IPs with `SsrfGuard::curlOptionsForIps()` plus the
    post-connect check, as URL import does.
- **Secrets.**
  - The key travels only in a header. Gemini already uses `x-goog-api-key`, never `?key=`.
  - It never appears in a response, the audit detail, a log line (`redact()`), CLI output or a
    transient.
  - `detail` is returned only to unscoped tokens, the WP admin and the CLI. A tenant gets
    code + hint, never the operator's billing or provider text.
  - The base URL is never returned.
- **Abuse and cost.**
  - Limits: `/ai/test` 2/min per user on top of the write bucket; WP AJAX 2/min; Cloud test
    5/min.
  - A probe is ~16×16 px + ≤ 16 output tokens. The health path never calls inference.
- **Provider/model visibility.** `error_params` (`provider`, `model`, `default_model`) reaches any
  `write`-capable token that calls `/ai-tag`. Accepted: it is not a secret and it is what makes the
  error actionable; only `detail` is withheld from scoped tokens.
- **Scoping.** No file path is involved. `disk` is checked against the token, and the audit key is
  the tenant scope root. `owner_only` and legal holds are not applicable because nothing is read or
  written.
- **ProcessEnv**: no subprocess, so not applicable.
- **i18n (16 locales, `packages/core/lang/*.json`):**
  - `error.*` keys: `ai_auth`, `ai_model_not_found`, `ai_no_vision`, `ai_quota`,
    `ai_rate_limited`, `ai_unavailable`, `ai_network`, `ai_blocked_host`, `ai_bad_response`,
    `ai_provider_error` (and `ai_not_configured`, which exists).
  - `ai_test.*` keys: `title`, `run`, `running`, `ok` `{ms}`, `deprecated` `{retires_at}`,
    `upload_failed` `{message}`, plus `hint.<code>` for each code above (placeholders
    `{default_model}`, `{retires_at}`).
  - Because `AiException` passes its code as an expression, add
    `'AiError.php' => [ …every code… ]` to `$declaredDynamic` in `tests/unit/test-i18n.php`.
    Otherwise the guard fails, which is the intent.

## 10. Package layout

All free/core; no private package.

- **Core, new:** `api/AiError.php` (+ `AiException`), `api/AiTestEndpoint.php`,
  `api/SsrfCheckedTransport.php`.
- **Core, edited:**
  - `api/AiTagger.php`, `api/FileManager.php` (upload `ai_error`), `api/AuditPolicy.php`
  - `api/index.php` (route + bucket + doctor block)
  - `bin/fluxfiles`, `assets/fm.js` (+ min), `public/index.html`, `lang/*.json` ×16
- **WP:** `includes/FluxFilesAdmin.php`, `includes/FluxFilesApi.php`, new
  `includes/FluxFilesSiteHealth.php`, `includes/FluxFilesPlugin.php` (hook registration),
  `assets/settings.js`, `composer.json` floor `^1.6.0`.
- **Laravel:** `routes/fluxfiles.php`, `src/Http/Controllers/FluxFilesController.php`,
  `src/Console/DoctorCommand.php`, `composer.json` floor `^1.6.0`.
- **Docs:**
  - `.claude/api-map.md` (route + doctor `ai` field)
  - `docs/guides/API.md` (route, error codes)
  - `docs/guides/DEPLOYMENT.md` (CLI `doctor`, `ai:test`)
  - `CHANGELOG.md`
  - `.claude/CLAUDE.md` Current Notes: one line ("AI errors are classified; `/ai/test` is 2/min,
    never 401/403/429 for upstream failures")
  - `CONFIG.md`: **no change**

## 11. Test plan

**Unit tests (core):**

- `tests/unit/test-ai-error.php` is table-driven over the providers and error classes. It uses
  realistic canned bodies for anthropic, gemini, openai, openrouter, groq and ollama, crossed with
  every code in §4.
  - Edge cases: a 404 HTML body counts as `ai_provider_error`, not "model not found"; Gemini 400
    `API_KEY_INVALID`; OpenAI 429 `insufficient_quota` vs a plain 429; Anthropic 529; Groq
    `model_decommissioned`.
  - `redact()` handles the literal key, each key pattern, URL userinfo, control chars and the
    200-char cut.
- `tests/unit/test-ai-tagger-connection.php` uses a `FakeTransport` that records requests.
  - `testConnection` hits the same URL and headers as `analyze` with `max_tokens` 16 and the
    probe PNG, and accepts empty text.
  - `checkModel` covers each provider's URL and auth header; that the key is never in a URL;
    Anthropic retired, deprecated and `image_input:false`; an OpenAI list without the id; a 404 on
    `/models` → `verified:false`; Ollama `:latest`; OpenRouter `/key` 401.
  - `analyze()` throws `AiException` with the right code and params, and `Log` output is redacted.
- `SsrfCheckedTransport`: a private IP → `ai_blocked_host`; an allowlisted host passes.
- `test-audit-policy.php`: `/api/fm/ai/test` → `ai_test`; `detail()` never contains a key-shaped
  string.
- The existing guards then cover the rest: `test-i18n.php` (parity + declaredDynamic) and
  `test-audit-coverage-http.php` (route named).

**HTTP e2e (core):** `tests/e2e/test-ai-test-http.php` binds **ephemeral ports**
(`stream_socket_server('tcp://127.0.0.1:0')`, as `test-write-policy-http.php` does). It boots two
servers:

- A fake provider: `php -S` on `tests/e2e/fixtures/fake-ai-provider.php`. The model name selects a
  scenario: `ok`, `retired`, `badkey`, `novision`, `quota`, `slow`. It serves the Anthropic, Gemini
  and OpenAI paths.
- Core, with `FLUXFILES_AI_PROVIDER=claude|gemini|compatible` + `BASE_URL` pointed at the fake.

It asserts:

- The gate matches `/ai-tag`:
  - a token without the disk gets 403 `disk_denied`, a read-only token 403 `permission_denied`;
  - with no provider configured, the result is 400 `ai_not_configured`;
  - write + configured gets 200 `ok` **both with `ai_auto_tag: false` and with it unset**.
- The 3rd call in a minute gets 429 `rate_limited`.
- A scoped token gets no `detail`; an unscoped token gets it.
- The raw response body **never contains the API key**.
- The audit entry `ai_test` has the expected detail.
- `/ai-tag` with a retired model returns 502 `ai_model_not_found` + `default_model`.
- An upload with auto-tag + `badkey` gets 200 + `ai_error: ai_auth`.
- The doctor `ai` block is present only for write + configured, and absent for read-only or
  unconfigured.
- The CLI `ai:test --json` and `doctor local --json` exit codes against the same fake.

**Browser (optional):** `tests/browser/doctor-ai.spec.ts`, with `page.route` mocking `/ai/test`.
It checks the row renders ✓/✗ and the localized hint.

**WP:**

- `test-wp-smoke.php`:
  - `ai/test` route is registered; drop it from any temporary allowlist. It answers for a write
    token whose `ai_auto_tag` is off, as `handleAiTag` does.
  - `ajaxTestAi` rejects a bad nonce or non-admin; reports unsaved/unconfigured; never echoes the
    key.
  - `ajaxTestDisk` works for a saved but not-ticked disk and never calls `tokenForCurrentUser`.
  - `site_status_tests` adds both async tests with `has_rest` + `async_direct_test`; the REST
    permission is `view_site_health_checks`.
  - Transient caching, TTL by verdict, and invalidation on `update_option_*`.
  - `ai_blocked_host` for a private base URL.
- Run **Plugin Check on the built zip** before the `wordpress-v1.3.0` tag; `check-wp-dist.php` is
  not enough.

**Laravel:** `test-laravel-smoke.php`: `ai/test` proxied (allowlist), gate + `ai_test` bucket of 2,
doctor `ai` block, `fluxfiles:doctor --ai` signature, and a non-zero exit with a fake failing
transport. The `adapter-core-floor` guard enforces the `^1.6.0` floor.

## 12. Phased PRs

1. **core: classifier.** `AiError`/`AiException`/`SsrfCheckedTransport`, the `AiTagger` refactor
   + `testConnection`/`checkModel`/`describe`, coded tagging errors + upload `ai_error`,
   `error.*` ×16, unit tests. There is no new route, so the adapter smokes are unaffected.
2. **core: endpoint + UI.**
   - `AiTestEndpoint`, `/api/fm/ai/test` + bucket + `AuditPolicy`, the doctor `ai` block, the
     Doctor AI row (+ min build), `ai_test.*` ×16, e2e, api-map/API.md.
   - **Add `'ai/test'` to both adapter smokes' `$intentionallyUnproxied`** with a "removed by the
     WP/Laravel PR" comment. Those smokes diff against monorepo core's `index.php`, and the
     adapters cannot call new core APIs before the floor bump.
3. **core: CLI.** `doctor` + `ai:test`, DEPLOYMENT.md, CHANGELOG → release **core-v1.6.0** (read
   `git tag | grep '^core-v' | sort -V | tail -1` first).
4. **WP-a.** Floor `^1.6.0`, the `ai/test` proxy (allowlist entry removed), the AI tab button, the
   `SsrfCheckedTransport` wiring, the `handleAiTag` code fix.
5. **WP-b.** The Cloud test on `BucketDoctor` (no enable-tick), Site Health → Plugin Check →
   **wordpress-v1.3.0**.
6. **Laravel.** Only after core-v1.6.0 is split to `fluxfiles-core`: floor `^1.6.0`, the route +
   controller (allowlist entry removed), the doctor block, `--ai`.

## 13. Open questions / trade-offs

1. **Code set.** This spec adds `ai_quota` (split from `ai_rate_limited`), `ai_unavailable` (5xx/529,
   split from `ai_network`) and an `ai_provider_error` catch-all to the approved list. The splits
   are there because the fixes differ: add credits vs wait vs check the URL. Collapse them if you
   prefer fewer codes.
2. **`detail` only for unscoped tokens.** This keeps the operator's provider and billing text from
   tenants. The alternative is to give every qualifying token the redacted detail.
3. **`ai_auto_tag` gating — resolved.** The owner chose `/ai-tag`'s eligibility (disk + `write` +
   AI configured). `ai_auto_tag` is not consulted, and no `ai_test_forbidden` code exists.
4. **Provider endpoints to verify while implementing:**
   - whether Anthropic's `lifecycle`/`capabilities` fields need an `anthropic-version` newer than
     `2023-06-01` (missing fields are treated as unknown/ok, so nothing breaks)
   - whether OpenRouter's `/models/{id}/endpoints` resolves `~`-aliases
   - whether Gemini `models.get` resolves `-latest` aliases
5. **Webhook noise.** `ai_test` fires the generic write webhook, as `notify_test` does. Exclude
   diagnostics from webhooks globally in a separate change if that is unwanted.
6. **Site Health storage check is list-only** (no write/delete probe). Permission drift on
   PutObject is caught only by the manual Cloud test or `fluxfiles doctor`.

Sources:

- [Anthropic Models API](https://platform.claude.com/docs/en/api/models)
- [Gemini models.get](https://ai.google.dev/api/models)
- [OpenRouter GET /api/v1/key](https://openrouter.ai/docs/api/reference/limits)
- [WP Site Health async tests (5.6)](https://make.wordpress.org/core/2020/11/15/site-health-check-changes-in-5-6/)
- [`site_status_tests`](https://developer.wordpress.org/reference/hooks/site_status_tests/)
