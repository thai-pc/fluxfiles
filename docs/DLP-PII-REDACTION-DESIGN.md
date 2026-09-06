# DLP / PII Detection-on-Upload — Design Spec

Status: **Design only — not implemented.** Companion to `docs/GIT-DEPLOY-SECURITY-REVIEW.md`
(style/depth reference) and the shipped Virus module (closest analog — gitignored code,
but its shape is fully visible from `ModuleRegistry.php`/`Claims.php`/`FileManager.php`/
`index.php` and is what this spec mirrors throughout).

**Framing decision, stated up front:** this is **not a new standalone SKU**. Per
`docs/COMMERCIAL-STRATEGY.md` §3a/§6 ("Compliance is one Enterprise *bundle*, not
modules... never piecemeal") and `docs/LICENSING-PLAN.md`'s tier matrix, the Enterprise
plan already sells Virus + C2PA + Audit Export + SSO as one bundle to persona C
(enterprise/regulated self-hosters buying compliance checkboxes + SLA). DLP/PII detection
is designed here as a **fifth member of that same bundle** — same license tier, same
`services/license-server/Plans.php` `enterprise` entry, no separate price, no Studio
inclusion (mirrors how `backup` is Enterprise-only, not Studio). Nothing below proposes
a `dlp`-only purchase path, and nothing should be built that creates one.

---

## 1. Problem & who pays

**Persona:** C — Enterprise/regulated self-hoster (per `COMMERCIAL-STRATEGY.md` §0). This
buyer already wants "we scan every upload" (Virus) and "we can prove provenance" (C2PA)
and "we retain/export audit trails" (audit-export) and "we gate login via our IdP" (SSO).
"We block uploads containing PII we're not allowed to store" is the same shape of
checkbox — a compliance officer's question, not an end-user feature — and it composes
naturally with the existing four: a regulated tenant (healthcare, finance, HR SaaS)
wants virus scanning **and** PII exfiltration prevention **and** an audit trail of both,
sold together with one SLA conversation.

**Who pays:** the operator's Enterprise contract, exactly like the other four bundle
members. **Free/core and Pro/Studio tenants never see this claim do anything** (layers
1+2 of the gate 501/402 them before the claim is even checked) — same posture as
`allow_virus_scan`/`allow_c2pa` today.

**What it does:** synchronously inspects an upload's (or code-editor save's, or
zip-extract entry's) content for detectable PII entities (SSN, credit-card numbers,
email addresses at scale, phone numbers, IBAN, etc.) **before the bytes are written**,
and refuses the write if any configured entity type is found above a confidence
threshold — the same "block, don't store" contract Virus already has, applied to a
different kind of "infection."

---

## 2. Architecture fit

**Stateless, storage-resident, no new central state.** DLP adds **zero** new
`_fluxfiles/` files. It is a **write-path gate**, structurally identical to Virus:

- No new endpoint (see §4) — it hooks into the *existing* write paths
  (`upload`, `PUT /api/fm/content`, `extractZip` entries, plus the internal
  `putContent`/`writeStream` helpers those and other features funnel through).
- No verdict is ever persisted anywhere except the **existing** audit log
  (`_fluxfiles/audit.jsonl`), via one new action (`dlp_blocked`) — reusing storage the
  operator already controls retention/export/purge for via the **sibling**
  `audit-export` module already in the same bundle. This is a deliberate synergy point
  for the bundle pitch, not an accident: buying the bundle gets you the block *and* the
  compliance trail for it, from one license.
- The engine itself (Microsoft Presidio, see §2.1) is **BYO self-hosted infrastructure**,
  called over plain HTTP with a bounded timeout — the same trust/operational posture as
  `FLUXFILES_VIRUSTOTAL_KEY`/ClamAV for Virus, `FLUXFILES_AIVISION_ENDPOINT` for AI
  Vision, and the OIDC issuer for SSO. FluxFiles never bundles, trains, or hosts a PII
  model — it orchestrates a call to the operator's own instance, keeping the core
  stateless/no-GPU/no-infra (the same "BYO-key" principle `COMMERCIAL-STRATEGY.md` §2
  applies to AI features, generalized to "BYO-*engine*" for scanning features).

**Config = JWT claims** (§3): a new `allow_dlp_scan` gate claim plus four tuning claims,
all decoded/sanitized/clamped in `Claims::fromJwtPayload` like every other claim, all
documented in `docs/CONFIG.md`.

### 2.1 Why Presidio, and where the analogy to Virus *breaks*

[Microsoft Presidio](https://microsoft.github.io/presidio/api/analyzer_python/) is
MIT-licensed, ships a self-hostable `presidio-analyzer` HTTP service (Docker image,
`POST /analyze {text, language}` → `[{entity_type, score, start, end}, ...]`), detects
~30 built-in entity types (US_SSN, CREDIT_CARD, EMAIL_ADDRESS, PHONE_NUMBER, IBAN_CODE,
PERSON, LOCATION, IP_ADDRESS, …), and is actively maintained (2.2.x line, 8,800+ stars;
mid-2026 it began transitioning to an independent, community-governed org — worth a
footnote but not load-bearing to this design, since the module only depends on the
stable HTTP contract). This is the direct engine analog to ClamAV for Virus.

**But the analogy to Virus breaks on one point, and it drives the design:** ClamAV/
VirusTotal scan **raw bytes**, file-format-agnostic — `VirusScanModule::scanPath()`
takes a local path and works identically whether it's a JPEG, a ZIP, or a PHP script.
Presidio analyzes **text**. A PDF, a JPEG, a `.docx`, an MP4 are not text — feeding their
raw bytes to `/analyze` would either error or (worse) silently return "clean" because
the binary garbage happens to contain no string that looks like an SSN, which is a false
sense of security, not a working scanner.

**Resolution (mirrors this project's existing pattern of narrowing v1 scope — see §2.2
of `docs/GIT-DEPLOY-SECURITY-REVIEW.md`'s "one fixed command shape" and the URL-import
"v1 is sync, no queue — by design" precedent in `.claude/CLAUDE.md`):** v1 only submits
files whose **extension** is on a text-bearing allowlist (`dlp_scan_extensions`, §3) to
the engine at all. Everything else (images, video, PDF, Office binary formats, archives)
is **skipped, not blocked** — DLP simply doesn't run for them in v1, the same way
`/api/fm/optimize` skips non-image/non-PDF files rather than erroring on them. This is a
stated, documented scope limit (§9.1), not a silently-discovered gap: PDF/DOCX/image-OCR
text extraction is real, valuable, and explicitly deferred to v2 (analogous to how the
OCR module already demonstrates the "shell out to a binary if installed, 501 if not"
pattern that a future PDF/DOCX extractor would reuse).

This eligibility filter is evaluated **in core, before the paid module is ever invoked**
(see §6's fail-closed contract) — an operator who turns on `allow_dlp_scan` but has the
engine down can still upload a JPEG; only text-bearing uploads are affected. This is the
one structural addition beyond a straight copy of the Virus wiring.

### 2.2 No existing HTTP-client/circuit-breaker abstraction to reuse

Per the task brief, `packages/core/api/` was checked for a reusable scanner-HTTP-client
abstraction. **None exists.** `VirusScanModule`/`AiVisionModule`/the SSO OIDC client are
all gitignored private packages — their HTTP calls aren't visible here, but the *env var*
shape they expose (`FLUXFILES_VIRUSTOTAL_TIMEOUT`, `FLUXFILES_AIVISION_TIMEOUT`, no
"circuit breaker" env anywhere in `docs/CONFIG.md` §3) confirms the established pattern
is a **plain bounded-timeout cURL call per request, no persistent failure-tracking
state**. This project deliberately avoids new server-lifetime state (see
`docs/GIT-DEPLOY-SECURITY-REVIEW.md` F8: "flagged only so nobody 'fixes' it by adding a
persistent connection pool"). A circuit breaker that remembers "the engine has been down
for N requests, stop trying" is exactly that kind of new in-memory/stateful mechanism —
**deliberately not built**. `packages/dlp/`'s `PresidioClient` follows the established
plain-timeout pattern: `FLUXFILES_DLP_TIMEOUT` bounds one HTTP call; a sustained outage
fails closed on every eligible write until the operator notices and fixes it (loud, via
502s, not silently degraded — the correct failure mode for a security control).

---

## 3. JWT claims (to add to `docs/CONFIG.md` §2.13)

| Claim | Type | Default | Notes |
|---|---|---|---|
| `allow_dlp_scan` | bool | `false` | 3-layer gate claim for the `dlp` module (Enterprise bundle). Mirrors `allow_virus_scan` exactly — opt-in, and even when true the module's code must be installed + the license must cover it. |
| `dlp_entity_types` | string[]\|null | `null` | Allowlist of Presidio entity-type names (e.g. `["US_SSN","CREDIT_CARD","EMAIL_ADDRESS"]`) that trigger a block. **`null` = the engine's full default detection set** (whatever entities Presidio returns unfiltered) — chosen to match the existing `allowed_ext: null = broadest` precedent in this codebase rather than inventing a hidden "curated safe default" that would silently differ from what the operator's own Presidio instance reports. Operators who only care about high-value identifiers (and want to avoid over-blocking on `PERSON`/`LOCATION`) should set this explicitly. Sanitized: each entry uppercased/trimmed, must match `^[A-Z_]+$`, else dropped. |
| `dlp_scan_extensions` | string[]\|null | `null` → built-in list: `txt,csv,tsv,json,ndjson,log,md,yaml,yml,xml,html,htm,sql` | v1 scan-eligibility allowlist (§2.1) — extensions outside this list are **skipped, not blocked**, even with `allow_dlp_scan` on. Sanitized like `allowed_ext`: lowercase, no dot, alnum only. |
| `dlp_max_scan_kb` | int (KB) | `0` → default `2048` (2 MB) | Per-file cap on bytes read and sent to the engine. A file over this cap is **skipped, not blocked** (documented gap, §9.3) — v1 stays synchronous and doesn't want a 200 MB log export to block an upload request for tens of seconds. Clamped `[16, 51200]` (16 KB–50 MB). |
| `dlp_min_score` | float | `0` → default `0.6` | Minimum Presidio confidence score (0–1) an entity match must reach to count as "detected." Lower = more sensitive (more false positives on marginal matches like a phone-number-shaped string); higher = fewer false positives, more risk of a missed real PII value. Clamped `[0, 1]`. |

All five follow the existing `Claims::fromJwtPayload` sanitize-on-decode contract — a
malformed value never breaks the server, it falls back to the default, per
`docs/CONFIG.md` §0's blanket guarantee. `Claims::isAllowed()` gets one new case:
`case 'allow_dlp_scan': return $this->allowDlpScan;`.

**Deliberately NOT added:** a `dlp_base_url`/engine-URL claim. The engine endpoint is
server infrastructure the operator deploys once (like the OIDC issuer, like
`FLUXFILES_AIVISION_ENDPOINT`), not a per-tenant preference — it's an env var (§6),
never a JWT claim, so it's never visible to a browser holding a token.

---

## 4. Endpoints

**None new.** Exactly like Virus, DLP is a **hook**, not a route. It attaches to
existing write paths:

| Existing route/internal call | DLP effect when `allow_dlp_scan` is on |
|---|---|
| `POST /api/fm/upload` (and therefore `POST /api/fm/import-url`, which funnels through the same `upload()`) | Scans the uploaded file **before** hash/dims/write/variants, if its extension is eligible (§2.1). |
| `PUT /api/fm/content` (code/config editor save) | Scans the new text content before it overwrites the file — the same staging-to-temp-file pattern Virus already uses at this call site (`FileManager.php` line ~1955). |
| `POST /api/fm/extract` (each zip entry) | Scans each eligible entry **before** it's written, same as Virus's per-entry hook (`FileManager.php` line ~2617) — one infected/PII-bearing entry aborts the whole extract (two-pass atomic, matches `extract`'s existing all-or-nothing contract). |
| Internal `putContent`/`writeStream` helpers | Scanned at the same low level Virus already hooks (`FileManager.php` lines ~3078/~3117) — this is a free ride: any *other* feature that writes bytes through these helpers (watermark burn-in output, metadata import, a future C2PA-sign output path) automatically gets DLP coverage too, with no per-feature wiring. |
| `POST /api/fm/chunk/init\|presign\|complete\|abort` | **Refused outright with `409 dlp_unscannable` while `allow_dlp_scan` is true** — S3-multipart bytes go browser→S3 directly and never reach this server, so they cannot be scanned (identical unscannable-side-door problem, identical resolution, as `virus_unscannable`). This is the exact "multipart uploads have the identical problem" case flagged in the task brief. |

No dedicated on-demand "scan this existing stored file" endpoint in v1 (§9.4).

---

## 5. Storage layout

**No new `_fluxfiles/` files.** This is a deliberate choice, not an oversight: a file
that catalogues "these paths contain SSNs" would itself become a sensitive-data-at-rest
liability — the opposite of what a DLP feature should introduce. The only storage
footprint is the **existing** audit log gaining one new action:

`_fluxfiles/audit.jsonl` — one line, same shape every other audit action already uses:

```json
{"ts": 1757000000, "user": "42", "action": "dlp_blocked", "disk": "local", "path": "customers-export.csv", "detail": "US_SSN,CREDIT_CARD"}
```

`detail` carries **only the matched entity-type names** (comma-joined, matching the
existing `detail` field's plain-string shape), never the matched text or its position —
consistent with the response-body redaction rule in §6. This mirrors exactly how
`virus_blocked` is logged today (`index.php`'s catch block, ~line 475): the audit log
only records *successful* writes, so a blocked write is the one rejection that must
leave a trace on purpose, logged from the same `catch (ApiException $e)` block keyed off
`$e->getErrorCode() === 'pii_detected'`.

This ties the bundle together operationally: an Enterprise buyer's `audit-export`
license (same bundle) can now export "every PII-blocked upload attempt, by whom, when"
alongside `virus_blocked` events — a concrete cross-module synergy worth stating in the
sales pitch, not just the code.

---

## 6. Security

### 6.1 Fail-closed contract (mirrors Virus exactly, restated precisely per the task brief)

| Condition | HTTP | `error_code` | Thrown from | i18n'd in core `lang/*.json`? |
|---|---|---|---|---|
| Module not installed | 501 | `module_not_installed` | `ModuleRegistry::require()` (generic, existing) | Already generic/existing |
| No/invalid license | 402 | `license_required` / `license_expired` | `ModuleRegistry::require()` (generic, existing) | Already generic/existing |
| Claim off | 403 | `allow_dlp_scan_forbidden` | `ModuleRegistry::require()` (generic, existing) | **No new key needed** — `fm.js`'s error handler already regex-matches `^allow_[a-z0-9_]+_forbidden$` and falls back to the generic `error.module_forbidden` + `{module}` template (see `assets/fm.js` ~line 608). This is why `allow_share_forbidden`/`allow_virus_scan_forbidden`/etc. have no per-module lang key either — don't add one for `allow_dlp_scan_forbidden`. |
| `FLUXFILES_DLP_ENDPOINT` unset (module installed+licensed+claimed, but server has no engine configured) | 501 | `dlp_unconfigured` | **Inside `DlpModule` itself** (private package) | **No** — mirrors `c2pa_unconfigured`/`aivision_unconfigured`/`ocr_unavailable`, none of which have core lang entries either, because they're thrown by module code the MIT core never sees at build time. |
| Engine unreachable, connection refused, or times out (`FLUXFILES_DLP_TIMEOUT`) | 502 | `dlp_engine_unavailable` | Inside `DlpModule` | No, same reasoning as above |
| Engine returns a non-2xx or a body that doesn't parse as the expected `[{entity_type,score,...}]` shape | 502 | `dlp_engine_unavailable` | Inside `DlpModule` | No |
| PII detected (any entity in `dlp_entity_types` — or, if `null`, any entity at all — scoring ≥ `dlp_min_score`) | 422 | `pii_detected` | **`FileManager::assertNoPii()`** (core) | **Yes — new key, all 16 languages** |
| Chunk-upload route hit while `allow_dlp_scan` is true | 409 | `dlp_unscannable` | **`index.php`** (core, mirrors the existing `virus_unscannable` block) | **Yes — new key, all 16 languages** |
| Staging content to a temp file for scanning fails (disk full, permissions) | 500 | `dlp_failed` | **`FileManager.php`** (core, mirrors `virus_failed`) | **Yes — new key, all 16 languages** |

**Malformed verdict counts as detected — same posture as Virus's "a malformed verdict
counts as infected."** `FileManager::assertNoPii()`'s check:

```php
$verdict = ($this->dlpScanner)($localPath, $name);
if (!is_array($verdict) || ($verdict['clean'] ?? false) !== true) {
    // block — mirrors assertNoVirus's !is_array($verdict) || clean !== true check
}
```

If `$this->dlpScanner` (the closure wired in `index.php`/the proxies) throws — because
`ModuleRegistry::require('dlp', ...)` 501/402/403s, or `DlpModule::scanPath()` itself
throws 501/502 — that exception **propagates and refuses the write**, exactly like
Virus. Nothing catches it and silently allows the upload through.

### 6.2 No raw PII in the response — the one requirement genuinely new vs. Virus

Virus's `virus_detected` error already includes `{name, threat}` where `threat` is a
signature *name* (e.g. `Eicar-Test-Signature`), never file content — so there's no
existing precedent for "the verdict itself IS sensitive," which DLP's is. **The `422
pii_detected` response's `error_params` carries exactly:**

```json
{"name": "customers-export.csv", "entities": ["US_SSN", "CREDIT_CARD"]}
```

**Never included, at any layer:** the matched substring, the character offset Presidio
returns (offsets can be used to reconstruct roughly where in the file the value sits,
which combined with a known file format can leak more than intended), a count of matches
per type (a count could itself leak scale — "47 SSNs found" tells an attacker something
about the file even without the values), or the extracted text itself. `DlpModule::
scanPath()` is responsible for reducing Presidio's full match list down to a **deduped
set of entity-type strings only** before it ever returns to core — core never sees, and
therefore can never accidentally echo, matched text.

### 6.3 SSRF

**None needed.** `FLUXFILES_DLP_ENDPOINT` is operator-set server `.env` config, not user
input — same posture as `FLUXFILES_AIVISION_ENDPOINT` and the SSO OIDC issuer URL
(`docs/CONFIG.md` §3 notes both explicitly as "operator-trusted... no SSRF guard
needed"). No claim carries a URL for this feature, so there is no attacker-reachable
input that could redirect the outbound call.

### 6.4 owner_only / path scoping

Not applicable — DLP is a content check on bytes already staged to a local temp file
during an already-authorized, already-scoped write. It doesn't read or return any path
information beyond what the write operation itself already exposed.

### 6.5 Size / rate caps

- `dlp_max_scan_kb` bounds the per-file payload sent to the engine (§3).
- `FLUXFILES_DLP_TIMEOUT` (default 10s, suggested) bounds worst-case added latency per
  eligible write.
- **No new dedicated rate-limit bucket.** Unlike git-deploy (which introduced a brand
  new route worth its own bucket, per `GIT-DEPLOY-SECURITY-REVIEW.md` §4.9), DLP adds no
  route — it rides the existing `upload`/`content`/`extract` write-rate limits
  (`rate_write`/`FLUXFILES_RATE_LIMIT_WRITE`). Flagged as an open question (§9.7):
  an attacker who can trigger many small eligible uploads under the existing write quota
  is now also hammering the operator's Presidio instance per request — acceptable for
  v1, revisit if it proves to be a real bottleneck.

### 6.6 Signing / HMAC

Not applicable — no new public route, no new typed token (`t=...`) is minted for this
feature.

---

## 7. Package layout

### 7.1 Paid package — `packages/dlp/` (gitignored, mirrors `packages/virus/`)

- `composer.json` — `fluxfiles/dlp`; `fluxfiles/fluxfiles` floor = the first core tag
  that ships `FileManager::setDlpScanner()` + the `ModuleRegistry` `'dlp'` entry (per
  `.claude/CLAUDE.md`'s adapter-floor rule: composer answers "which core may this
  install against," so the floor must be the version that actually **calls** the
  module, not merely one where the class would resolve).
- `src/DlpModule.php` — implements `ModuleInterface`:
  - `public static function id(): string { return 'dlp'; }`
  - `public static function claim(): string { return 'allow_dlp_scan'; }`
  - `public function scanPath(string $localPath, ?array $entityTypes, float $minScore): array` —
    reads the (already core-size-capped) file as UTF-8 text (best-effort
    `mb_convert_encoding`/`iconv` fallback for non-UTF-8 text files), POSTs to
    `rtrim(getenv('FLUXFILES_DLP_ENDPOINT'), '/') . '/analyze'` with
    `{text, language: getenv('FLUXFILES_DLP_LANGUAGE') ?: 'en', entities: $entityTypes, score_threshold: $minScore}`
    via a bounded cURL call (`FLUXFILES_DLP_TIMEOUT`), parses the
    `[{entity_type,score,start,end}, ...]` response, and returns
    `['clean' => bool, 'entities' => string[]]` — deduped entity-type names only,
    **never** offsets/scores/matched text (§6.2). Throws `ApiException(501,
    'dlp_unconfigured')` if the endpoint env var is empty, or `ApiException(502,
    'dlp_engine_unavailable')` on connection failure/timeout/non-2xx/unparseable body.
- `src/PresidioClient.php` — the thin HTTP wrapper described in §2.2 (plain bounded
  cURL, no persistent state).
- `tests/` — the module's own test suite (not visible in this repo; mirrors the layout
  of the other 10 private modules).

### 7.2 Free/core changes (this repo, MIT)

- `packages/core/api/ModuleRegistry.php` — add `'dlp' => '\\FluxFiles\\Dlp\\DlpModule',`
  to `$map`.
- `packages/core/api/Claims.php` — 5 new public properties (`allowDlpScan`,
  `dlpEntityTypes`, `dlpScanExtensions`, `dlpMaxScanKb`, `dlpMinScore`), decode/sanitize
  logic in `fromJwtPayload()` (§3's clamps), one new `isAllowed()` case.
- `packages/core/api/FileManager.php`:
  - `private $dlpScanner = null;`
  - `public function setDlpScanner(callable $fn): void` — `fn(string $localPath, string
    $name): array{clean:bool, entities:string[]}`.
  - `private function assertNoPii(string $localPath, string $name): void` — the
    extension-eligibility + size-cap pre-filter (§2.1/§6.5) runs **before** invoking
    `$this->dlpScanner`; a skip is a silent no-op (not a "clean" verdict logged
    anywhere), an eligible file invokes the scanner and throws `422 pii_detected` on a
    non-clean/malformed verdict (§6.1).
  - Call sites added alongside every existing `$this->virusScanner !== null` /
    `assertNoVirus(...)` call: the upload path (~line 416), the content-edit path
    (~line 1955), the zip-extract per-entry path (~line 2617), and the two low-level
    `putContent`/`writeStream` helpers (~lines 3078/3117) — same five locations, so any
    future feature that writes through these shared helpers inherits DLP coverage for
    free, same as it inherits Virus coverage today.
- `packages/core/api/index.php`:
  - Wiring block next to the existing virus block (~line 344): `if
    ($claims->allowDlpScan) { $fm->setDlpScanner(static function (string $localPath,
    string $name) use ($claims): array { $dlp = \FluxFiles\ModuleRegistry::require('dlp',
    \FluxFiles\LicenseManager::fromEnv(), $claims); return $dlp->scanPath($localPath,
    $claims->dlpEntityTypes, $claims->dlpMinScore); }); }`
  - A second, independent chunk-route check (~line 1174, next to the existing virus
    one): `if ($claims->allowDlpScan && str_starts_with($uri, '/api/fm/chunk/')) { throw
    new ApiException(..., 409, 'dlp_unscannable'); }` — independent `if`, since
    `allow_virus_scan` and `allow_dlp_scan` are orthogonal claims and either (or both)
    can be on.
  - Catch-block audit logging (~line 475, alongside the `virus_detected` special case):
    `if ($e->getErrorCode() === 'pii_detected' && isset($auditLog, $claims)) { ...
    $auditLog->log($claims->userId, 'dlp_blocked', $disk, $name); }` — logging only
    `name` (the `entities` list could also be appended to `detail` per §5's shape, e.g.
    `implode(',', $p['entities'] ?? [])`).
- `docs/CONFIG.md` — §2.13 gets the 5 claims (table in §3 above) + a new bundle-module
  row.
- `packages/core/lang/*.json` (**all 16** — `en, vi, zh, ja, ko, fr, de, es, ar, pt, it,
  ru, th, hi, tr, nl**) — 3 new `error.*` keys (`pii_detected`, `dlp_unscannable`,
  `dlp_failed`) + 1 new `audit.actions.dlp_blocked` label. **No** `dlp_unconfigured`/
  `dlp_engine_unavailable` keys in core — those are module-internal (§6.1), matching the
  existing `ocr_unavailable`/`c2pa_unconfigured`/`aivision_unconfigured` precedent of
  *not* having core lang entries. (Memory note: per past i18n bulk-edits, hand-edit each
  file's existing formatting — don't `json.dump`-reformat a whole file for a 4-key add.)
- `services/license-server/Plans.php` — add `'dlp'` to the `enterprise` plan's `modules`
  array (line ~43). **Not** added to `studio` (mirrors `backup`'s Enterprise-only
  precedent) and not given its own plan (mirrors this doc's opening framing — no
  standalone SKU, ever).

### 7.3 Proxy parity — REQUIRED, not optional

The task brief calls this out explicitly, and this project has a specific, named
precedent for getting it wrong: `webhooks`/`auto_optimize`/`ai_auto_tag` were once
**inert** in the Laravel/WordPress proxies — the claim decoded fine, but neither proxy's
`fileManager()` builder actually wired the corresponding hook, so a token with the claim
set behaved differently depending on which adapter served it (see
`docs/CONFIG.md`/`.claude/CLAUDE.md`'s "Laravel/WP proxy inert claims gap" history). DLP
must not repeat this. Both proxy controllers build their **own** `FileManager` instance
(they don't reuse `index.php`) and therefore must independently call `setDlpScanner()`:

- `packages/laravel/src/Http/Controllers/FluxFilesController.php`:
  - `fileManager()` builder — add the DLP wiring block immediately after the existing
    virus block (~line 118-129), same shape, swapping `LicenseManager::fromEnv()` (this
    proxy's existing convention there).
  - All 4 chunk-route handlers (`chunkInit`/`chunkPresign`/`chunkComplete`/`chunkAbort`,
    ~lines 2020/2075/2118/2198) — add the same independent `dlp_unscannable` 409 check
    next to each existing `virus_unscannable` one.
- `packages/wordpress/includes/FluxFilesApi.php`:
  - Same builder wiring (~lines 580-591, using `FluxFilesPlugin::license()` per this
    proxy's existing convention) and the same 4 chunk-route checks (~lines
    2389/2430/2473/2553).
- `packages/laravel/src/FluxFilesManager.php` / `packages/wordpress/includes/
  FluxFilesPlugin.php` — forward all 5 new claims **unconditionally** from token-builder
  option overrides, matching exactly how `allow_virus_scan`/`allow_git_deploy` are
  forwarded today (no conditional "only if provided" logic that could silently drop a
  claim — see the `d880b98`/`fb7c8a2` role-preset lesson in `.claude/CLAUDE.md` about
  claims silently defaulting to something unintended).
- Bump both adapters' `composer.json` `fluxfiles/fluxfiles` floor to the core tag that
  first ships the DLP wiring (§7.1's reasoning applies identically here) — verified by
  the existing `scripts/check-adapter-core-floor.sh` CI job once the floor is set, but
  must be set **by hand** first since the packages are gitignored and can't be
  CI-guarded for "did you remember" the way MIT-core changes are.

---

## 8. Test plan

**Core unit** (`packages/core/tests/unit/`):
- Claim decode/defaults/clamping: `allow_dlp_scan` default false; `dlp_entity_types`
  sanitization (valid `^[A-Z_]+$` entries kept, garbage dropped, `null`/absent stays
  `null`); `dlp_scan_extensions` lowercasing/no-dot handling, default list when absent;
  `dlp_max_scan_kb` clamp `[16, 51200]`, `0`→default 2048; `dlp_min_score` clamp `[0,1]`,
  `0`→default 0.6 (note: `0` as "unset, use default" vs. `0` as "a legitimately
  permissive threshold" is ambiguous the same way `versioning_max`'s `0` is — document
  the same convention: `0`/absent means default, not literal zero).
- `Claims::isAllowed('allow_dlp_scan')`.

**Core integration** (`packages/core/tests/integration/test-dlp.php`, new file, mirrors
the existing `test-modules.php` pattern of registering a **fake** `ModuleInterface`
implementation via `ModuleRegistry::register()` so the gate logic is testable without
the real gitignored package):
- A stub `DlpModule` whose `scanPath()` is a closure the test controls: returns
  `clean=false` → assert upload of an eligible (`.txt`) file 422s with `pii_detected`
  and `error_params.entities` matching what the stub returned, and that **no** raw text
  from the uploaded fixture appears anywhere in the JSON response body (a real content
  assertion, not just a status-code check — this is the one behavior unique to DLP vs.
  Virus, §6.2).
- Same stub returns `clean=true` → upload succeeds.
- Malformed verdict (missing `clean` key, or `clean` as a non-bool truthy string) →
  blocked (fail-closed check, mirrors `assertNoVirus`'s equivalent test).
- A file whose extension is **not** in `dlp_scan_extensions` → upload succeeds AND the
  stub scanner is asserted **never called** (a call-counter closure) — proves the
  eligibility pre-filter, not just "happens to pass."
- A file over `dlp_max_scan_kb` → same "succeeds, scanner never called" assertion.
- Module not installed (no class registered) → `501 module_not_installed`.
- Unlicensed → `402 license_required`.
- Claim off → `403 allow_dlp_scan_forbidden`.
- The zip-extract per-entry path: one PII-bearing entry among several aborts the whole
  extract with none of the entries written (mirrors the existing virus zip-abort test).

**Chunk-route test**: `allow_dlp_scan=true` + any of `chunk/init|presign|complete|abort`
→ `409 dlp_unscannable`, independent of `allow_virus_scan`'s state (test both on and off
together, to prove the two checks are independent `if` blocks, not accidentally
coupled).

**Audit test**: a blocked upload produces exactly one `_fluxfiles/audit.jsonl` line with
`action: "dlp_blocked"`, `detail` containing only entity-type names, and (explicit
negative assertion) **not** containing the fixture's injected fake-SSN string.

**Self-booting e2e** (`packages/core/tests/e2e/test-dlp-http.php`, new file, follows the
existing `test-virus-http.php`/`test-stream-http.php` convention: boots its own `php -S`,
backs up/restores `.env`, needs `curl`; pick an unused port per the registry in
`.claude/CLAUDE.md` — e.g. `8113`, since `8110-8112` are taken by share): stands up a
tiny canned-response HTTP stub (a second `php -S` on another port, or a single-file
router) to play the role of Presidio, and exercises over real HTTP:
- Engine returns a PII entity above threshold → `422 pii_detected`, response body
  grepped to confirm the fixture's fake SSN string is **absent**.
- `FLUXFILES_DLP_ENDPOINT` pointed at a closed port → `502 dlp_engine_unavailable`.
- `FLUXFILES_DLP_ENDPOINT` unset while `allow_dlp_scan` is true → `501 dlp_unconfigured`.
- Module class not loaded at all → `501 module_not_installed`.
- `.jpg` upload with `allow_dlp_scan` true and the stub engine simulated as unreachable
  → succeeds anyway (extension not eligible, engine never called) — the key regression
  test for §2.1's design.

**CI guards that will fail until this is done right** (call out explicitly, both are
existing repo mechanisms, not new ones to build):
- `tests/unit/test-config-doc.php` — fails until the 5 claims are in `docs/CONFIG.md`.
- `tests/unit/test-i18n.php` — fails until the 3 new `error.*` keys + 1
  `audit.actions.dlp_blocked` key exist in **all 16** `lang/*.json` files.

**Adapter smokes** (`packages/laravel/tests/test-laravel-smoke.php`,
`packages/wordpress/tests/test-wp-smoke.php`, stubbed PHP per existing pattern): assert
the 5 claims forward unconditionally from `FluxFilesManager`/`FluxFilesPlugin` token
builders, and that both proxies' chunk-route handlers 409 with `dlp_unscannable` when
the claim is on.

**Adapter↔core floor guard**: once composer floors are bumped (§7.3), the existing
`scripts/check-adapter-core-floor.sh` CI job automatically verifies the floor is high
enough that `setDlpScanner()` actually exists at that floor — no new tooling needed,
just remembering to bump the number by hand (gitignored packages aren't CI-visible).

---

## 9. Open questions / trade-offs

1. **v1 text-extraction scope is narrow by design.** Only extensions on
   `dlp_scan_extensions` (plain-text-bearing formats) are ever submitted to the engine —
   no OCR-on-images, no PDF/DOCX/XLSX binary-format text extraction. This mirrors how
   this codebase consistently ships a narrower v1 and states the cut explicitly (URL
   import's "v1 is sync... a storage-resident job model would be the v2 path," Git
   deploy's "F5 documented, not solved"). **v2 candidate**: shell out to `pdftotext`/
   similar (mirrors the OCR module's existing "check for a binary at known paths, 501 if
   absent" pattern) to extend coverage to PDF/Office formats.
2. **Detect-and-block only — no auto-redact-and-replace.** Actually rewriting file bytes
   to redact PII in place (blur a face/SSN in an image via OCR, edit a PDF's content
   stream, rewrite an Office document's XML) is a categorically larger engineering scope
   than returning a boolean verdict — full parity with how this spec's task brief itself
   frames the cut, and consistent with this project's general bias (Optimize/Watermark
   both stop at "transform the whole file," never "edit around a detected region within
   it"). **v2 candidate**, explicitly out of scope here.
3. **`dlp_max_scan_kb` is skip-not-block for oversized files.** A very large CSV/log
   export could exceed the cap and slip through completely unscanned. This is an
   accepted v1 trade-off (keeping the feature synchronous, matching every other
   no-queue feature in this codebase) rather than a solved problem — an operator who
   needs to guarantee coverage of large exports should also constrain `max_upload`
   tightly enough that "large" and "PII-risk" don't overlap for their use case. Not
   fixed by this module.
4. **No on-demand "scan already-stored files" endpoint in v1** (e.g. `POST
   /api/fm/dlp/scan {disk,path}` to audit an existing bucket). Write-time-only, exactly
   like Virus. A bulk-audit crawl is a different feature shape (closer to `backup`'s
   "operator-driven, cron-scheduled subtree walk" than to a per-request write gate) and
   is left as a stated future item, not built here.
5. **No persistent circuit-breaker/backoff state** (§2.2) — a sustained Presidio outage
   fails closed on every eligible write until fixed. This is the correct failure mode
   for a security control (loud, not silently degraded) and avoids introducing new
   server-lifetime state this codebase has consistently avoided elsewhere.
6. **`dlp_entity_types: null` = engine's full default set, not a FluxFiles-curated
   "high-risk-only" starter list.** This was a genuine judgment call: the task brief's
   own reasoning for the claim ("avoid over-blocking on names/locations") could argue
   for a narrower built-in default. This spec picked "null = broadest" for consistency
   with `allowed_ext`'s existing null-semantics, on the theory that a silent curated
   default would be a support surprise ("why did it flag PERSON when I set nothing?").
   Worth revisiting if early Enterprise feedback says otherwise — flagged, not settled.
7. **No dedicated rate-limit bucket for this feature** (§6.5) — reuses the existing
   write bucket rather than getting its own the way `git-deploy` did. Revisit if the
   added per-request HTTP hop to Presidio proves to be an operational bottleneck in
   practice; not pre-built speculatively.
8. **Naming split, intentional:** module id `dlp` (the term Enterprise/compliance buyers
   search for and the term used in the bundle's marketing), JWT claim `allow_dlp_scan`,
   but the blocking error code is the more concrete `pii_detected` (what was actually
   found) rather than `dlp_blocked` (which is reserved for the **audit action name**
   instead, §5). Flagging so a future refactor doesn't "fix" this into false
   consistency and break the audit log's existing action-naming convention (`action`
   values are all past-tense/verb-like — `virus_blocked`, `dlp_blocked` — while
   `error_code` values name the condition — `virus_detected`, `pii_detected`).
