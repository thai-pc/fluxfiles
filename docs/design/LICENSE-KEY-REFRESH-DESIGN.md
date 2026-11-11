# Licence Key Refresh: Design

Status: **Implemented end to end.** Licence server side (`POST /refresh`) first; the core client
shipped in core-v1.2.0 (`packages/core/api/LicenseRefresh.php`, `LicenseKeyFile.php`,
`fluxfiles license refresh`, CHANGELOG `[1.0.24]`), and FluxFiles Pro for WordPress runs the same
refresh daily via wp-cron (`ProRefresh`). The branches this text mentions are merged to master.

## 0. Summary

Monthly subscription customers get a new signed key on every Polar `order.paid`. If they
forget to paste it, `LicenseManager` switches the paid modules off at `expires + grace`
(31 + 7 days). This design adds:

1. A licence-server endpoint, **`POST /refresh`**. It takes the current key as a Bearer
   credential and returns the newest key that the same subscription (or renewal chain)
   has produced. It never mints anything.
2. A core client (`fluxfiles license refresh`, also run automatically at the start of
   `fluxfiles update <module>`). It verifies the new key offline and stores it in a
   **server-local overlay file** (`FLUXFILES_LICENSE_FILE`, default
   `<storage>/license-refresh.json`). `LicenseManager::fromEnv()` then prefers the file,
   but only when the file is bound to the exact env key the operator configured and its
   key is strictly newer.
3. The same refresh in **FluxFiles Pro for WordPress**, run from `ProUpdater`'s check and a
   per-site daily wp-cron event. The new key is written back to the option the key came
   from.

Not included: no runtime (request-path) refresh, no JWT claim, no database in core, no
install binding.

## 1. Problem and who pays

- **Persona:** a self-hosting operator on `pro-monthly` / `studio-monthly`, in core
  standalone, Laravel, or WordPress with FluxFiles Pro. Customers on annual plans with an
  early renewal and lifetime customers with an updates renewal see the same key churn,
  but a missed paste only stops their *updates*, because enforcement is `perpetual`.
- **Free or paid:** this is plumbing for paid licences, but it is **free/core code** with
  no `ModuleRegistry` gate. It only ever acts on a key the operator already holds. A
  missed paste today is a support ticket and churn risk for the vendor, so the vendor
  benefits most.
- **Why now:** 31-day keys plus a 7-day grace leave a monthly customer with a 38-day
  fuse they have to defuse by hand twelve times a year. Polar's renewal order is an
  ordinary `order.paid` with `billing_reason: subscription_cycle`
  ([Polar orders](https://polar.sh/docs/features/orders)), and `LicenseIssuer::issueNow()`
  mints a fresh row for it (`LicenseIssuer.php:84-143`).

## 2. Architecture fit

- **Core stays stateless.** The refreshed key is server-local runtime state of the same
  kind as `rate_limit.json` (the `<storage>` dir, `FLUXFILES_STORAGE_PATH`). It is not
  per-tenant data, so it does **not** go in `_fluxfiles/` (see §4.1). The vendor licence
  server is already the one stateful piece (its own SQLite). Only that server gains a
  column.
- **Runtime stays offline.** Verification is still Ed25519 against the embedded `k1`.
  The network is touched only from the CLI or WP cron, both of which the operator already
  runs for updates. A failure never changes what is running.
- **No B3 risk.** `/refresh` makes **no outbound calls** (unlike `/claim` and
  `/pro-download`, which wait on the update server). It runs on the public pool. The
  update server never calls `/refresh`. The loopback `/update-status` pool is unchanged.

## 3. Licence server: `POST /refresh`

### 3.1 Request

```
POST /refresh HTTP/1.1
Host: <licence server>
Authorization: Bearer <current licence key>
Content-Type: application/json

{"v":1,"client":"core/1.2.0"}
```

The key goes in the header only (it is the same bearer convention as the update channel,
`bin/fluxfiles:131`). It is never placed in the URL or the body. `client` is informational
(capped at 64 chars, `[a-z0-9./-]`, and never trusted). Bodies over 1 KB → 400.

### 3.2 Server steps (`server.php`, new block after `/pro-download`)

1. Read `Authorization: Bearer …`. Strip whitespace, cap at 4096 bytes (same as
   `findByKey`).
2. **Verify the signature first, with no DB touch.** Add a new
   `LicenseSigner::verify(string $key): ?array` that checks the derived public key
   (`sodium_crypto_sign_publickey_from_secretkey`), `alg=Ed25519`, and a known `kid`.
   Failure → `401 {"error":"invalid key"}`. This leaks nothing, because anyone with the
   MIT core's public key can compute it.
3. Per-licence throttle: `rateHit('refresh-jti:'.$jti, 3600, 10)` → `429` + `Retry-After`.
4. `findByJti($jti)`, then `hash_equals($row['license_key'], $key)`. A missing row
   (an offline `license-gen` key, or a manual key minted elsewhere) or a mismatch →
   generic 404.
5. Resolve the newest key (§3.3). Refusals (§3.4) → generic 404.
6. Respond (§3.5). Server-side `error_log` lines carry the jti and an outcome only, never
   the key or the email.

### 3.3 Which key comes back

What links rows **today**: `(lower(email), plan)` via `supersedeActiveForBuyerPlan`
(`LicenseStore.php:425`), `customer_id` (stored since the renewal work), `renews_jti`
(one-time updates renewals only), and `order_id` / `checkout_id` (one per row, which
cannot link cycles). The webhook does **not** store the Polar subscription id
(`PolarWebhook::extract`, `PolarWebhook.php:109-120`).

Email+plan is ambiguous. One buyer with two `pro-monthly` subscriptions (for example, an
agency with two clients) would refresh A's install into B's keys. If A cancels and B keeps
paying, A's install keeps running. The existing supersede logic already conflates the two:
each subscription's new row supersedes the other's.

**Decision: store Polar's `subscription_id`.**

- `PolarWebhook::extract()` adds `subscription_id` (`$d['subscription_id'] ??
  $d['subscription']['id'] ?? ''`) and `billing_reason`. Polar's Order object carries both
  ([Order schema](https://polar.sh/docs/api-reference/orders/get)).
- `LicenseStore::migrate()` adds a `subscription_id TEXT` column and
  `idx_subscription(subscription_id)`, using the guarded `addColumn()`.
- `LicenseIssuer::issueNow()` records the column. When it is known, it supersedes **by
  subscription** (new `supersedeActiveForSubscription($sid, $exceptJti)`) instead of by
  email+plan. This also fixes the two-subscriptions reminder bug. Rows without a
  subscription id keep the current email+plan behaviour.
- **Existing rows:** add a one-off `backfill-subscription-ids.php` (dry run by default,
  `--apply`, uses the existing `POLAR_TOKEN`). For each `gateway='polar'` row with a NULL
  `subscription_id`, it calls `GET /v1/orders/{order_id}` and writes the result. It is
  idempotent and safe to re-run, and it mirrors `backfill-supersede-duplicates.php`.

New method `LicenseStore::newestRefreshable(array $row, array $presented): ?array`:

| Presented row has | Candidate set |
|---|---|
| `subscription_id` | rows with the same `subscription_id` |
| `renews_jti` descendants (one-time lifetime + updates renewals) | BFS forward over `renews_jti` (the reverse of the existing cascade walk) |
| neither (legacy Polar row, not yet backfilled) | `gateway='polar'`, same `lower(email)` + `plan`, compatible `customer_id` (equal, or either NULL). **If those rows carry two or more distinct non-NULL `subscription_id`s → ambiguous → 404** and an ops log line. |
| manual row with no chain | the row itself only (always `current`) |

The candidate set is filtered to `status IN ('active','superseded')`,
`issued > presented.issued`, and `expires >= presented.expires`. Of what remains, the row
with the highest `issued` is returned. If nothing remains → `current`.

**Monotonic `issued`.** `LicenseSigner::mint()` gains an `issuedAtLeast` option.
`issueNow()` passes `max(issued in this subscription/chain) + 1`, so a server clock that
steps backwards cannot mint a key the client would reject as a downgrade (§8.4).

### 3.4 Refused, always the same generic 404

All of these return `404 {"error":"not available","hint":"paste the key from your licence e-mail"}`:

- presented row `revoked` / `refunded`, including rows cancelled by the existing
  `setStatusCascade`;
- a support-only plan (`modules` empty / `edition = support`): there is nothing to unlock;
- a no-expiry legacy key (already lifetime updates, nothing newer can be better);
- a stale key: `now > presented.expires + presented.grace + FLUXFILES_REFRESH_MAX_STALE_DAYS`
  (default 90; see §8.1);
- unknown jti, key mismatch, or ambiguous legacy linkage.

Revoking a whole subscription: `POST /revoke` accepts `{"subscription_id":"…","status":"revoked"}`
and applies the status to every row of that subscription, so its keys stop refreshing. A `revoked_subscriptions` marker makes that outlive the next `order.paid`: the paid order is recorded but stays revoked, is not mailed, and ops is notified. Revoking does not stop Polar billing, so also cancel the subscription in Polar.

### 3.5 Responses

| Status | Body | Meaning |
|---|---|---|
| 200 | `{"status":"refreshed","license_key":"<key>","jti":"…","issued":1794000000,"expires":1796678400}` | A newer key exists. The client trusts **only** the signed `license_key`; the other fields are for display. |
| 200 | `{"status":"current"}` | The presented key is the newest. |
| 401 | `{"error":"invalid key"}` | Missing, malformed, or bad signature. |
| 404 | `{"error":"not available","hint":"…"}` | Any refusal in §3.4. |
| 429 | `{"error":"too many attempts"}` + `Retry-After` | Throttled. |
| 500 | `{"error":"internal error"}` | Existing catch-all. |

**Oracle analysis.** 401 is computable offline. 404 groups revoked, refunded, unknown,
support, stale, and ambiguous, so a caller learns only "this key will not be refreshed"
(the same posture as `/pro-download`, `server.php:289-331`). The only distinction left is
`current` vs `refreshed`, and a caller can only see it while holding a validly signed,
non-revoked key. A key holder learning that a newer key exists is the purpose of the
endpoint. `Cache-Control: no-store` is already global (`server.php:52`).

### 3.6 Exposure: nginx and pools

- **Public vhost**, `location = /refresh`, on the **public** php-fpm pool. No CORS (it is
  for servers, not browsers): no `Access-Control-*` headers, so a web page cannot read
  the answer.
- nginx: `limit_req_zone $binary_remote_addr zone=ff_refresh:10m rate=30r/m;` with
  `limit_req zone=ff_refresh burst=60 nodelay;` and `client_max_body_size 1k;`. The
  default `combined` log format does not log `Authorization`. Keep it that way, and never
  add `$http_authorization` to a log format.
- **Implemented:** the server also keeps a DB per-IP bucket, `refresh-ip:<ip or /64>` at 120/hour (after the signature check, via `rateHit`), next to the per-jti 10/hour bucket. The nginx zone below is the first line; the DB bucket is the second.
- The per-IP flood limit lives in nginx (see above), not only SQLite. Signature verification (step 2) is
  cheaper than a `rate_hits` write, so invalid keys never reach the DB. The DB limit is
  per jti only, so an agency with 50 sites behind one NAT is not throttled.
- **Pools:** `/refresh` holds a public worker for one SQLite read and one `rate_hits`
  upsert, with no outbound wait, so it cannot deadlock. The internal `/update-status`
  pool stays separate and unchanged. Document in OPERATIONS that `/refresh` must not be
  routed to the internal pool, which is sized for the update server only.

## 4. Core client

### 4.1 Where the refreshed key lives

| Option | Verdict |
|---|---|
| (a) rewrite the host `.env` | **No.** `.env` is often read-only, deploy-managed, or absent (Docker/k8s/php-fpm `env[]`). After `artisan config:cache`, Laravel would not see the change anyway (`config/fluxfiles.php:161` captures env at cache time). Editing a file the operator owns is the most surprising choice. |
| (b) server-local key file | **Recommended.** It is read at runtime by `LicenseManager::fromEnv()`, so it works under `config:cache`. It sits next to the other runtime state, and deleting it is a full reset. |
| (c) `_fluxfiles/license.key` | **No.** `_fluxfiles/` is per-disk tenant storage (it can be S3/SFTP, or BYOB disks owned by a tenant). The vendor licence is server-wide, and the licence is resolved before any disk exists. FluxDirGuard protection also only covers local disks. |
| (d) print only | **Kept as an explicit mode** (`--print`) for operators who manage secrets externally (Vault, k8s). It is never the default, because it brings back the manual paste. |

**Path.** `FLUXFILES_LICENSE_FILE` if set (it must be absolute; a relative path is ignored
with a CLI warning). Otherwise `<FLUXFILES_STORAGE_PATH>/license-refresh.json`. Otherwise
`packages/core/storage/license-refresh.json`. One resolver,
`LicenseKeyFile::path(array $env)`, is shared by the CLI and the runtime, so the two always
agree. `/storage/` is already denied by `router.php:144-150` and
`docker/nginx.conf`. Composer users without `FLUXFILES_STORAGE_PATH` should set
`FLUXFILES_LICENSE_FILE` outside `vendor/`. If `composer install` wipes the file, the
install falls back to the env key until the next cron run fetches it again, so the loss
is harmless.

### 4.2 File shape (mode 0600)

```json
{
  "v": 1,
  "anchor": "sha256:<hex of the whitespace-stripped FLUXFILES_LICENSE_KEY it was derived from>",
  "key": "<signed licence key>",
  "jti": "…", "issued": 1794000000, "expires": 1796678400,
  "fetched_at": 1794000123
}
```

`jti` / `issued` / `expires` are a cache for `fluxfiles about`. Runtime always re-derives
them from the signed `key`.

### 4.3 Precedence: anchored overlay, strictly newer

`LicenseManager::fromEnv()` keeps its signature and calls
`LicenseKeyFile::effectiveKey($env)` when that class exists. The file key is used **only
if all of these hold**:

1. `FLUXFILES_LICENSE_KEY` is non-empty and verifies;
2. the file parses, `key` verifies, and `anchor === sha256(clean(env key))`;
3. `file.issued > env.issued` (signed values; strictly greater).

Otherwise the env key is used, exactly as today. Reasons:

- **An operator edit always wins at once.** Pasting any key into env changes the anchor,
  so the file is ignored until the next refresh, and that refresh can only move *forward
  from what was pasted*. An older file can never override a newer env key (rule 3).
- **No cross-licence bleed.** A plain "newest `issued` wins" would let a leftover file
  from licence A override a deliberately configured licence B (for example after moving
  from Studio to Pro). The anchor prevents that.
- **No env key, no overlay.** The file is not a standalone licence source in v1. That
  keeps "where does my licence come from" to one answer.

**Cost.** `fromEnv()` is called per gate. Memoise per process on
`(sha256(env key), path, inode, mtime, size)`: a `stat()` per call, and a signature
verification only when the memo key changes. This honours `ModuleRegistry::license()`'s
"resolved per call, never cached across env changes" rule (`ModuleRegistry.php:56-61`).

`LicenseManager` gains one accessor, `issuedAt(): ?int`. `fluxfiles about` prints
`Licence source: env | refreshed (fetched <date>) | refreshed file unreadable`. It never
prints the key or the path.

### 4.4 Verify, then store atomically

New pure class `FluxFiles\LicenseRefresh` (no file IO; the HTTP transport is injected; it
ships byte-identical to WP Pro's `lib/`):

- `request(string $key, string $baseUrl, callable $http): array{status, key?}` POSTs
  per §3.1. `baseUrl` must be https, or http to loopback for tests (same rule as
  `ProUpdater::isSecureUrl`). No redirects, TLS verify on, 5 s timeout, response capped
  at 16 KB.
- `accept(string $current, string $candidate): bool` returns true only if the candidate
  verifies with the embedded keys (`kid` known), its edition is not `free`,
  `candidate.issued > current.issued`, and `candidate.expires >= current.expires`. This
  is the client-side downgrade guard and does not depend on the server's (§8.3).

`LicenseKeyFile::store()` takes `flock()` on `<file>.lock`, re-reads the file and keeps
whichever key has the higher `issued` (two crons can race), then writes `tmp` in the same
directory under `umask(0077)` and `rename()`s it. A new file gets **0600**. An existing
file keeps its mode and group, capped at 0640: the operator can pre-create it
group-readable for the php-fpm user when cron runs as a different user. Never
world-readable.

### 4.5 Triggers

- **`fluxfiles update <module>`:** the refresh runs **before** the update request, and
  the update request then sends the *effective* key, not the raw `$_ENV` value
  (`bin/fluxfiles:93`). For a renewed perpetual licence, only the newest key carries the
  open update window, so the order matters. The refresh is best-effort. Failures print one
  line to stderr (`Licence refresh skipped: server unreachable`) and the update continues.
  `--no-refresh` opts out per run.
- **`fluxfiles license refresh [--check] [--print] [--json]`:** `--check` asks and
  reports without writing. `--print` writes the new key to stdout only and never to the
  file (option d). Exit codes: `0` refreshed or current, `1` usage/config, `2`
  network/server (retryable), `3` not available. Cron can alert on a non-zero exit. Skip
  the call (exit 0, "nothing to refresh") when the effective key has no `expires` or no
  modules.
- **Runtime lazy refresh in the grace window: no.** It would add an outbound call to the
  request path. It would also add a write to server state from the php-fpm user, and a
  thundering herd: every worker in grace hits the vendor at once, so the per-jti
  throttle fires and nothing improves. And egress is often blocked from web tiers, so the
  call would silently never work. Seven days of grace plus a daily cron is enough. The
  existing `status: grace` in `/api/fm/license` and the UI badge already tell the
  operator; their copy can add "run `fluxfiles license refresh`".
- **Recommended cron (docs):** `17 4 * * * php vendor/bin/fluxfiles license refresh`.

### 4.6 Offline and air-gapped installs

`FLUXFILES_LICENSE_REFRESH=false` disables every automatic call (CLI and WP). Any failure
(DNS, timeout, 4xx/5xx, bad signature, downgrade) leaves the file and env untouched and
never deletes anything. A 404 does not remove an existing overlay: runtime enforcement is
offline and unchanged. With the feature unused, behaviour is byte-for-byte today's.

### 4.7 A latent bug to fix in the same change

`bin/fluxfiles:91` loads `.env` from `realpath(__DIR__.'/../../..')`. In a Composer
install that path is **`vendor/`**, not the project root (the same expression is used as
`$vendorBase` at line 102). So the CLI does not read the host project's `.env`, and
`FLUXFILES_LICENSE_KEY` / `FLUXFILES_LICENSE_FILE` there are invisible to cron unless they
are exported. Add `dirname(__DIR__, 4)` (the project root) to the search list so the CLI
and the runtime resolve the same file. **Verify this on a real Composer install before
fixing.**

## 5. WordPress: FluxFiles Pro

The WP plugin uses no key file. The key lives where `ProLicense` already reads it
(`ProLicense.php:59-109`).

- **Source-aware write-back.** New `ProLicense::ownSource(): ?array{type, key}` mirrors
  `rawKey()` (not `updaterKey()`):
  - `site` option (single site or a non-network-active blog) → `update_option()`;
  - `network` option (network-active) → `update_site_option()`;
  - `constant` (`wp-config.php` cannot be edited) → overlay site option
    `fluxfiles_license_refreshed` = `{key, anchor, issued, at}`. `rawKey()` uses it only
    under the §4.3 rules (anchor = sha256 of the constant, strictly newer).
  - Each write is a compare-and-set: re-read the option and write only if it still equals
    the key that was presented, so an admin's concurrent paste always wins. Then call
    `ProLicense::resetMemo()`.
- **Never another site's key.** In a non-network-active multisite, `updaterKey()` can fall
  back to the main site's key or the first site's key (`ProLicense.php:96-107`). The
  refresh only ever presents and writes the **current blog's own** source. Other blogs are
  refreshed by their own cron, in their own context. Nothing iterates `get_sites()` for
  refresh.
- **Triggers:**
  1. In `ProUpdater::fetch()` (`ProUpdater.php:121`), before the manifest GET, when
     `ownSource()` is the same key as `updaterKey()`. This rides WP's twice-daily
     `wp_update_plugins` and the existing 12 h / 1 h manifest transient. The GET then
     uses the fresh key.
  2. A per-site daily event `fluxfiles_pro_license_refresh` (`wp_schedule_event` on
     `init` if missing; cleared on deactivation and in `uninstall.php`).
  - Both are throttled by a transient `fluxfiles_pro_license_refresh` (a site transient
    for network/constant, a blog transient for site): 12 h after refreshed/current, 1 h
    after a network error, 24 h after 404.
- **Transport:** `wp_remote_post(…/refresh, ['timeout'=>5,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$key]])`,
  with the same `LicenseRefresh::accept()` from `lib/`.
- **Config:** the `wp-config.php` constants `FLUXFILES_LICENSE_REFRESH` and
  `FLUXFILES_LICENSE_SERVER_URL` (same names as core). `ProUpdater::isSecureUrl()` gates
  the URL.
- **Admin UI:** in the Licence section, "Renewed automatically on {date}" plus the
  existing masked key (`ProLicense::mask`). On failure, a status word only, never a raw
  server body. Strings use the `fluxfiles-pro` text domain (not core's 16-locale
  catalogue).

## 6. Laravel adapter

**No code change in v1.** Runtime goes through core's `LicenseManager::fromEnv()`
(`FluxFilesController.php:1088`, `FluxFilesManager.php:864`), so the overlay is picked up
automatically. `FLUXFILES_LICENSE_FILE` is a `FLUXFILES_*` var, so `server_env` captures it
into the config cache (`config/fluxfiles.php:161`). The file itself is read at runtime, so
a refresh takes effect without `config:clear`. Documentation only: in
`packages/laravel/README.md`, set `FLUXFILES_LICENSE_FILE=/abs/path/storage/app/fluxfiles-license.json`
and schedule `fluxfiles license refresh` (it is a plain `vendor/bin` call, so it does not
need the `->command()` artisan form).

A thin `php artisan fluxfiles:license-refresh` wrapper and a Doctor row are **deferred**.
Either one needs new core API, so the Laravel core floor would have to be raised, which
§10 avoids for v1.

## 7. Configuration

**JWT claims: none.** This is server-wide licence plumbing, not per-tenant config.
`tests/unit/test-config-doc.php` guards claims only. The env vars below still go in
CONFIG.md §3 by rule.

| Name | Where | Default | Notes |
|---|---|---|---|
| `FLUXFILES_LICENSE_FILE` | core env | `<FLUXFILES_STORAGE_PATH>/license-refresh.json`, else `packages/core/storage/license-refresh.json` | Absolute path only. |
| `FLUXFILES_LICENSE_REFRESH` | core env / WP constant | `true` | `false` = no automatic call (CLI `update`, WP cron/updater). `fluxfiles license refresh` run explicitly still works. |
| `FLUXFILES_LICENSE_SERVER_URL` | core env / WP constant | `https://fluxfiles-license.thai-pc.com` | https, or http to loopback only. Operator-trusted (no SsrfGuard), same posture as `FLUXFILES_UPDATE_URL`. |
| `FLUXFILES_REFRESH_MAX_STALE_DAYS` | licence server `.env.example` | `90` | Days after `expires + grace` beyond which a presented key no longer refreshes. |

## 8. Security review

### 8.1 What a stolen key buys

- **Today:** for subscription keys, runtime until `expires + grace` (at most 38 days) and
  update downloads while the row is update-eligible. For perpetual keys, runtime forever
  and updates until `expires`.
- **With refresh:** a thief who keeps refreshing has working subscription modules **for as
  long as the victim keeps paying**, and an open update window for perpetual keys.
- **Binding options considered:**
  - Install or fingerprint binding: rejected. Every plan is unlimited sites, so one key
    legitimately runs on N servers, containers are ephemeral, and binding needs an online
    activation registry (the README's "add only if you must").
  - IP binding: rejected (same reasons).
  - Refresh-token rotation with reuse detection: rejected. N legitimate servers each
    refreshing the same key look exactly like reuse.
  - E-mail confirmation: rejected, because it defeats the automation.
- **Accepted, with three bounds:**
  1. the staleness cap (§3.4), so an old key leaked in a public repo goes dead after
     90 days past its grace;
  2. revoke-by-subscription (§3.4), the ops kill switch;
  3. a theft costs the thief nothing beyond what the stolen bearer secret already gave
     them for unlimited sites, and the victim is typically the server the key was stolen
     from.
- Optional v1.1: the admin `/licenses` view shows refresh calls per jti over 7 days, with
  a count of distinct /64 buckets (hashed, daily salt) as an abuse signal. See §11.

### 8.2 Replay and logging

- Keys never go in a URL, CLI argv, `error_log`, an exception message, or an nginx log.
- Every key parameter is `#[\SensitiveParameter]`. `CURLOPT_VERBOSE` stays off.
- Replaying `/refresh` with a captured key returns the same answer the key holder would
  get, with no state change (the endpoint is read-only apart from the throttle). TLS is
  required by the URL validation.
- WP stores keys in options, as today, and the transients never contain the key.

### 8.3 Downgrade

- The server filters to `issued > presented` and `expires >= presented` (§3.3).
- The client re-checks the same rules on the **signed** payloads (`LicenseRefresh::accept`),
  and the overlay additionally needs a newer issued than env (§4.3). So a compromised or
  buggy server can only hand out a vendor-signed key that is newer. Since the server holds
  the signing key, it is trusted to that extent by design.
- A newer key with an *earlier* expiry is refused, for example a mid-term switch from an
  annual plan to a monthly one. That case falls back to the manual paste and is called out
  in the CLI message.

### 8.4 Clock skew

- Every acceptance decision compares signed `issued` / `expires` values with **each
  other**, never with the local clock.
- The server's clock is used only for the staleness cap and the throttle.
- A server clock stepping backwards is covered by monotonic `issuedAtLeast` (§3.3).
- Runtime expiry still uses the local clock, as today.

### 8.5 Error codes and i18n

There are no new core API error codes and no web-UI strings in core, so no ×16 i18n work.
CLI output is English, like the rest of `bin/fluxfiles`. WP Pro strings go through `__()`
in `fluxfiles-pro`.

## 9. Files touched and size

| Area | Files | Est. |
|---|---|---|
| Licence server | `PolarWebhook.php` (+subscription_id, billing_reason), `LicenseStore.php` (column, `newestRefreshable`, `supersedeActiveForSubscription`, revoke by subscription), `LicenseSigner.php` (`verify`, `issuedAtLeast`), `LicenseIssuer.php`, `server.php` (`/refresh`, `/revoke` subscription), **new** `backfill-subscription-ids.php`, `README.md`, `.env.example`, `LicenseMailer.php` (subscription mail copy: "running `fluxfiles license refresh` daily makes this automatic") | ~1 day |
| Core (free) | **new** `api/LicenseRefresh.php`, **new** `api/LicenseKeyFile.php`, `api/LicenseManager.php` (`issuedAt()`, `fromEnv()` hook), `bin/fluxfiles` (`license refresh`, pre-update hook, effective key, `.env` path fix), `api/AboutInfo.php` (source line), `docs/reference/CONFIG.md`, `docs/guides/OPERATIONS.md` (nginx `/refresh`, cron), `CHANGELOG.md` | ~1.5 days |
| WP Pro (private) | `includes/ProLicense.php`, `includes/ProUpdater.php`, `includes/ProPlugin.php` (cron), `includes/ProAdmin.php` (status row), `uninstall.php`, `lib/LicenseRefresh.php` (+ repinned `lib/LicenseManager.php`), `modules.lock.json` `_core.tag`, the `test-pro-smoke.php` lib list | ~1 day |
| Laravel | `README.md` only | <1 h |

Total: about **3.5 to 4 dev days**, most of it tests.

## 10. Test plan

**Licence server** (`services/license-server/tests/`):

- `test-license-server.php` (unit, in-memory SQLite):
  - `extract()` carries `subscription_id` / `billing_reason`;
  - the migration adds the column on an old DB;
  - `newestRefreshable` covers: subscription chain → newest; two subscriptions on one
    email do not cross; renews-chain BFS; legacy fallback; ambiguous legacy → null;
    revoked, refunded, and cascade-cancelled rows are excluded; never older `issued`,
    never earlier `expires`; support and no-expiry refused; the staleness cap;
  - supersede by subscription leaves the other subscription active;
  - `issuedAtLeast` holds with a mocked clock that moves backwards;
  - `verify()` rejects a wrong kid, a tampered payload, and an oversize key.
- **New** `test-refresh-http.php` (self-boot `php -S`, ephemeral port):
  - 401 / 404 / 200-current / 200-refreshed bodies match §3.5 exactly;
  - revoked, unknown, and support responses are byte-identical;
  - the jti throttle gives 429 with `Retry-After`;
  - the key never appears in the server's stderr log;
  - `/refresh` answers while the internal pool is stopped (proves no outbound
    dependency);
  - `/revoke {subscription_id}` blocks the next refresh.
- The backfill script runs dry vs `--apply` against a stubbed Polar HTTP seam.

**Core** (`packages/core/tests/`):

- `unit/test-license-key-file.php` covers the precedence matrix:
  - no file; anchored and newer → file;
  - anchor mismatch → env; equal or older → env;
  - bad signature, malformed JSON, or an unreadable file → env;
  - empty env → no licence; a relative `FLUXFILES_LICENSE_FILE` is ignored;
  - the memo invalidates on mtime change;
  - `fromEnv()` is unchanged when the class is absent.
- `unit/test-license-refresh.php` (throwaway Ed25519 keys via the `$publicKeys` seam):
  - `accept()` rules;
  - `request()` against a stub transport: refreshed, current, 404, 429, 5xx, timeout,
    oversize body, redirect refused, http non-loopback refused;
  - the key is absent from every captured stderr and `error_log` line.
- `integration/test-license-key-file-write.php`:
  - 0600 on create; mode and group preserved, capped at 0640;
  - two concurrent writers keep the higher `issued`;
  - the tmp file is cleaned up on failure;
  - `router.php` denies `/storage/license-refresh.json`.
- **New** `e2e/test-license-refresh-http.php` (self-boot, ephemeral port, stub licence
  server; gated on the local signing key like `test-intake-http.php`):
  - `fluxfiles license refresh` writes the file;
  - `fluxfiles update <m> --check` sends the *refreshed* key to a stub update server;
  - `FLUXFILES_LICENSE_REFRESH=false` makes no request;
  - an unreachable server → `update` still exits on its own result.

**WP Pro** (`packages/wordpress-pro/tests/`, stubbed WP):

- `test-pro-license-refresh.php`:
  - site, network, and constant sources each write to the right place;
  - CAS: an admin paste between request and write wins;
  - a non-network multisite never reads or writes another blog's option;
  - transient throttle timings; the cron event is scheduled and cleared on uninstall;
  - `rawKey()` uses the constant's overlay only when it is anchored and newer;
  - the updater GET uses the fresh key;
  - the key never appears in a transient.
- `test-pro-smoke.php`: the lib byte-identity list gains `LicenseRefresh`.

## 11. Rollout and compatibility

**Order:**

1. **Licence server.** Deploy the migration and webhook capture. Run
   `backfill-subscription-ids.php` dry, then with `--apply`. Add the nginx
   `location = /refresh`, then deploy the endpoint. Smoke test with a test key. Hold the
   mail-copy change until step 2 ships, so the mail never advertises a command that does
   not exist.
2. **Core.** Tag `core-vX.Y.Z`, taking the real next version from
   `git tag | grep '^core-v' | sort -V | tail -1` and never from the changelog. Add a
   CHANGELOG entry with a `> Released:` line, and push at most 3 tags per push. Wait for
   the split to `fluxfiles-core`. Then ship the licence mail copy.
3. **WP Pro.** Repin `_core.tag` to that core tag, recopy `lib/`, and release the plugin
   (its own version) through the pack and catalogue flow.
4. **Laravel.** README only, with **no floor bump**: the runtime gain comes through
   whatever core the host resolves. If the artisan wrapper is added later, follow the
   floor order: wait for the split, run `composer update fluxfiles/fluxfiles
   --no-install`, run `scripts/check-adapter-core-floor.sh`, then tag.
5. **Paid modules.** Unaffected: they consult `ModuleRegistry::license()`, and no module
   floor changes.

**Compatibility matrix:**

- old core with the new server: no calls, nothing changes;
- new core with the old server: `/refresh` returns 404, which is "not available", and
  the CLI prints one line;
- new core with no file: identical to today;
- removing the file fully reverts.

## 12. Open questions for the owner

1. **Stolen-key trade-off (§8.1).** Accept unbounded refresh while the subscription is
   paid, with a 90-day staleness cap and revoke-by-subscription? Or also limit refresh to
   `enforcement = subscription` keys only? The second choice drops the
   perpetual-early-renewal benefit but narrows the perpetual-key theft gain to nothing.
2. **Staleness default.** Is 90 days right? Shorter means less exposure from old leaks,
   but more manual pastes for customers whose cron is broken.
3. **Default licence host.** Confirm `https://fluxfiles-license.thai-pc.com` as the
   public default for `FLUXFILES_LICENSE_SERVER_URL`, and that it is the public vhost
   rather than the loopback one.
4. **Plan changes inside a subscription** (`billing_reason: subscription_update`).
   Should refresh follow an upgrade or downgrade of edition automatically? This design
   says yes, because it is the same paid subscription. It refuses only an earlier expiry.
5. **The `bin/fluxfiles` `.env` path (§4.7).** Confirm on a real Composer install, then
   fix it in this change or separately.
6. **Abuse telemetry (§8.1).** Is a per-jti distinct-/64 counter worth storing
   (hashed IP-derived data) in v1, or should it wait until the first suspected leak?

## Appendix: rows to add to `docs/reference/CONFIG.md` (§3 env table)

No JWT claims. Add after `FLUXFILES_UPDATE_URL`:

| Variable | Default | Description |
|---|---|---|
| `FLUXFILES_LICENSE_FILE` | `<FLUXFILES_STORAGE_PATH>/license-refresh.json` (else `packages/core/storage/license-refresh.json`) | Absolute path of the server-local file where `fluxfiles license refresh` stores the newest key of your subscription (JSON, mode 0600). Used instead of `FLUXFILES_LICENSE_KEY` only when it was derived from that exact key and is newer. Delete it to revert. Keep it outside `vendor/` on Composer installs. Never web-served (`/storage/` is denied). |
| `FLUXFILES_LICENSE_REFRESH` | `true` | `false` stops `fluxfiles update` (and FluxFiles Pro's updater/cron, as a `wp-config.php` constant) from fetching a renewed key automatically. An explicit `fluxfiles license refresh` still runs. For air-gapped installs. |
| `FLUXFILES_LICENSE_SERVER_URL` | `https://fluxfiles-license.thai-pc.com` | Vendor licence server used for key refresh (`POST /refresh`, key sent as a Bearer header). https only (http to loopback for tests). WordPress: `wp-config.php` constant read by FluxFiles Pro. |

Licence server `.env.example`:

```
# Key refresh (POST /refresh). Days after a key's expiry + grace beyond which it can no
# longer fetch its subscription's newest key (bounds the value of an old leaked key).
# FLUXFILES_REFRESH_MAX_STALE_DAYS=90
```
