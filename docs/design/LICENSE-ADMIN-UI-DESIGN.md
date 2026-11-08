# Licence Server Admin UI, Statistics and Alerts — Design

Status: **Approved for implementation** (owner review 2026-11-08). Nothing in this document is implemented yet.

This spec covers `services/license-server/` as it will look once `feat/update-server-pro-entitlement`,
`feat/license-mail-html` and `feat/license-refresh-server` are merged to master. **§17 is the handoff for
the implementing agent.** It is self-contained, but read §2 and §5 before writing code.

## 0. Decisions

### 0.1 Design decisions

| Question | Decision |
|---|---|
| How the UI is reached | **SSH tunnel only.** The UI listens on loopback (`127.0.0.1:9472`) and the owner reaches it with `ssh -L`. There is no public admin domain. Cloudflare Access is Phase 3 and opt-in. |
| Human login | Password plus TOTP with a server-side session. The admin token is never typed into a browser. |
| k1 boundary | The UI and the ops worker run as their **own Unix users**, with no key path and no DB access. They do everything over HTTP against the loopback admin API. The admin API is the only place that holds the DB and the signing path. Issuing goes through the existing `POST /issue` → `LicenseIssuer`. |
| Pools / processes | Loopback is split into a **status** pool (`/update-status`), an **admin-api** pool (CLI, UI and ops-worker routes) and a **ui** pool. Telegram, health checks and counters run in a **systemd-timer ops worker**, outside php-fpm. The call graph has no cycle (§2.4). |
| Stack | Server-rendered plain PHP, one vanilla `admin.js`, one `admin.css`, and charts as inline SVG generated in PHP. No build step, no composer dependencies, no CDN, no inline JS or CSS. |
| Strings | Vietnamese by default, with an English toggle. Times are shown in `Asia/Ho_Chi_Minh`, with UTC in `title`. |
| New state | New tables in `licenses.sqlite` (§5). The UI's users and sessions live in its own `admin.sqlite`. The ops worker's cursor lives in a state file. |
| Polar API token | Never stored on the server. The UI uses deep links and webhook-driven state. One-off backfills read a token from env or a file and do not keep it (the same pattern as `backfill-subscription-ids.php`). |
| Alerts | Telegram, **outbound only** (`sendMessage` to one chat). Alerts go through a queue table, deduplicated and rate-limited. Optional read-only `/stats` and `/today` commands via `getUpdates`. Nothing can act through the bot. |
| JWT claims / `CONFIG.md` | **None.** This is vendor back-office. New settings are licence-server env vars (§13), documented in the service README and `.env.example`. |

### 0.2 Owner decisions (2026-11-08)

| Topic | Decision |
|---|---|
| Sandbox purchases | Polar **sandbox** goes to a **separate licence server instance**. Production holds real customers only. `is_test` stays, for manual comp and test rows. |
| Failed-webhook bodies | Kept **7 days** (not 30), or until resolved. |
| Display time | `Asia/Ho_Chi_Minh`, with UTC on hover (`title`). The DB stays UTC. |
| Access | SSH tunnel only. Cloudflare Access is Phase 3. There is no public admin domain. |
| Polar refund dialog | It is unknown whether it can also cancel the subscription. **Keep** the automatic "refunded but subscription still running" item (§10.2). |
| `subscription.revoked` | Revoke the update channel only if more than **48 h** of the period remain (§10.2). |
| Ops email | Keep it **alongside** the UI queue and Telegram. |
| F4 (ops email not loaded in prod) | **Confirmed.** The deploy bootstrap and L-secret allow-lists now include `FLUXFILES_OPS_EMAIL` and `FLUXFILES_RENEWAL_URL`. |
| F1 (`/redeliver` mails manual keys) | **Fixed** on `feat/license-refresh-server`: `undelivered()` now requires `gateway != 'manual'`. |
| New scope | Statistics and growth (§7) and alerts and operations (§8) are approved. Phasing is in §15. |

---

## 1. Problem and who pays

**Persona.** The single FluxFiles owner, acting as the vendor operator. They are technical, read Vietnamese first,
and have a few to a few hundred customers. Today every admin task means SSH plus the root-only CLI `fluxfiles-license`
(`list`, `issue`, `revoke`, `redeliver`, `status`, `backup`). Polar remains the billing admin for orders, refunds,
subscription cancellation and discounts.

**Free or paid.** Neither. This is internal vendor tooling. It is not a FluxFiles module, not a `ModuleRegistry`
entry and not core, and no customer ever runs it.

| # | Pain point | Root cause | Fix |
|---|---|---|---|
| P1 | A refund revokes the licence, but the subscription must still be cancelled in Polar by hand | `order.refunded` → `setStatusCascade`. Nothing tracks subscriptions | A "subscription still running" ops item with a Polar deep link. `subscription.*` webhooks close it on their own (§10.2) |
| P2 | Monthly plans mint a new key every cycle | Each cycle is a new `order.paid` | **Mostly handled** by `POST /refresh` (feat/license-refresh-server): installs fetch the newest key of their chain. The UI groups cycles by `subscription_id` and shows refresh activity |
| P3 | Webhook failures appear only in `error_log` | Nothing is stored | `webhook_events`, a dashboard card, a Telegram alert and replay |
| P4 | Renewal and cascade notices reach only the ops mailbox | `sendRenewalUnmatched()` / `reportCascade()` | The `ops_items` queue plus Telegram. The ops mail stays |
| P5 | Test licences pile up | No notion of a test row | Sandbox runs on a separate server. `is_test` / `archived_at` handle manual rows |
| P6 | Nobody sees backup age | The root cron writes to a root-only directory | `backup-status.json`, a dashboard card, and a Telegram alert after 26 h |
| P7 | No business numbers | Order amounts are not stored | §7: orders, revenue, installs and versions |

**Findings from reading the code**

| ID | Finding | Status |
|---|---|---|
| F1 | `/issue` never mails, and `undelivered()` picked up manual rows, so `/redeliver` mailed comp and test keys | **Fixed** on feat/license-refresh-server. The UI issue form still offers an explicit "email now" or "mark delivered" choice (§6.2) so `mailed_at` is meaningful |
| F2 | `GET /licenses` returns every row's `license_key` | Open. The new `/admin/*` reads never return keys. The CLI `list` moves to `/admin/licences`. The legacy route stays and is audited as `key.bulk_read` |
| F3 | `server.php` builds `new LicenseSigner()` (loading k1) before routing, so `/update-status` and `/health` workers hold k1 | Open. Build the signer lazily, only for `/issue`, the webhook and `/refresh`. `/refresh` verification should eventually use the **public** key only (`FLUXFILES_LICENSE_K1_PUB`), so it needs no secret |
| F4 | `FLUXFILES_OPS_EMAIL` / `FLUXFILES_RENEWAL_URL` were not loaded in prod | **Fixed in the deploy** (allow-lists updated) |

---

## 2. Access and security model

### 2.1 Threat model

k1 (`license-signing.key`) is the critical asset. Anyone holding it can mint keys that every FluxFiles install accepts
offline, forever, and the only cure is a new key id in core plus re-keying every customer. Next come the
admin powers: issue, revoke, reveal and customer PII. Then the Telegram token, which can only post to the
owner's chat. The VPS is **shared** with other vhosts and pools, so loopback is reachable by every local process.
**Goal:** add no new path to k1, and no path to admin powers that does not already require SSH.

### 2.2 Options (decided: (a))

| | (a) Loopback + `ssh -L` | (b) Cloudflare Tunnel + Access | (c) Public vhost with login, 2FA and IP allow-list |
|---|---|---|---|
| New internet surface | **None** | No inbound port, but `cloudflared` runs and Cloudflare terminates TLS | A login form on the box that holds k1 |
| Feasible on the free plan | n/a | Yes. Zero Trust Free covers up to 50 users and self-hosted apps, and Tunnel is free [1][2][3] | n/a |
| Failure mode | Fails closed | Fails **open** if the policy is removed or the hostname is proxied without an Access app | Fails open on an allow-list mistake |
| Trust added | none | Cloudflare sees revealed keys and cookies. The Cloudflare account becomes an admin credential | none, but brute-force and pre-auth bug exposure |
| Mobile | An SSH app with port forwarding (Termius, Blink, Termux) | best | breaks the allow-list |

**Owner decision: (a) only.** (b) is Phase 3. If it is ever added, the app keeps its own login and also validates
`Cf-Access-Jwt-Assertion` [3]. (c) is rejected.

### 2.3 Tunnel setup

- nginx `listen 127.0.0.1:9472` with `server_name localhost 127.0.0.1`, plus a `default_server` on the same
  listen that does `return 444;`. This is the **DNS-rebinding** defence: the forwarded port lives on the owner's laptop,
  where a hostile page can rebind its own name to 127.0.0.1. PHP enforces the same allow-list (`FLUXFILES_ADMIN_HOSTS`, port ignored).
- Laptop `~/.ssh/config`:
  ```
  Host fxlic
    HostName <licence-host>
    LocalForward 127.0.0.1:8471 127.0.0.1:9472
    ExitOnForwardFailure yes
  ```
  Run `ssh -N fxlic`, then open `http://localhost:8471`. That is a secure context in Chromium and Firefox, so the Clipboard API works.

### 2.4 Processes, users and pools

```
internet                                               loopback only
Polar  ─► public vhost ─► [public pool, 3]  ───────► update server ─► [status pool, 2]  /update-status
Resend ─►  /webhook/polar /webhook/resend     (php8.5)                   user fluxfiles-license
buyer  ─►  /claim /pro-download /refresh                                 writes install_pings (cheap, after response)
           user fluxfiles-license (k1: yes)

owner ─ssh -L─► :9472 ─► [ui pool, 2] ──UI token──►  :9471 ─► [admin-api pool, 2]  user fluxfiles-license
                         user fluxfiles-license-admin                  k1 + DB; sends mail (Resend/SMTP)
                         k1: NO, DB: NO                  ▲
root CLI ─────────────────────────────────admin token────┤
ops worker (systemd timer, 1/min) ─────────ops token─────┘   ─► api.telegram.org (sendMessage/getUpdates)
  user fluxfiles-license-ops; k1: NO, DB: NO                  ─► health targets, public counter APIs
```

| Pool / process | Unix user | Serves / does | k1 | DB | Calls out to |
|---|---|---|---|---|---|
| `fluxfiles-license` (public, unchanged) | fluxfiles-license | webhooks, claim, pro-download, refresh, health. **Enqueues** alerts (one INSERT) | yes | yes | update server, Resend |
| `fluxfiles-license-internal` (status, **narrowed**) | fluxfiles-license | `/update-status`, `/health`. Records install pings after the response | key env removed (F3) | yes | none |
| `fluxfiles-license-admin-api` (**new**) | fluxfiles-license | `/issue /licenses /revoke /redeliver /admin/*` | yes (for `/issue` only) | yes | Resend/SMTP |
| `fluxfiles-license-admin` (**new**, UI) | **fluxfiles-license-admin** | UI pages | **no** | **no** | `127.0.0.1:9471` only |
| ops worker (**new**, systemd oneshot) | **fluxfiles-license-ops** | Telegram send and poll, health, TLS, disk, public counters, inbox ingest, weekly-report trigger | **no** | **no** | `127.0.0.1:9471`, Telegram, health targets, public APIs |

Rules this layout guarantees:

- **Nothing calls the ui pool, the admin-api pool or the worker except a human, the CLI or the timer**, so no request waits on a pool
  that is waiting on it. The UI never touches the public pool, so it cannot starve the Polar webhook.
- **Telegram is never contacted from php-fpm.** A slow or failing Telegram affects only the worker.
- Today the CLI's `/redeliver` (up to 60 s) shares the 2-worker internal pool with `/update-status`. The split also fixes that.
- **Timeouts:** UI→API connect 2 s, total 30 s. UI-triggered redeliver is capped at `limit=10`. Worker→API total 20 s.
  Worker→Telegram 10 s. One worker run is capped at 55 s, and systemd will not start a second run while one is active.
- **Double barrier:** `/etc/fluxfiles-license` is 0750 root:fluxfiles-license and `/var/lib/fluxfiles-license` is 0700.
  The UI and ops users are in neither group, and their `open_basedir` excludes both paths. Code under `admin/`, `ops/` and `client/` never
  `require`s `LicenseSigner`, `LicenseStore` or `LicenseIssuer`. A test enforces this (§14).

### 2.5 Human authentication

- **Enrolment is CLI only:** `fluxfiles-license admin-user add <name>`. It prompts for a password, generates a 160-bit TOTP secret and prints the
  `otpauth://` URI. Subcommands: `passwd`, `totp-reset`, `disable`, `logout-all`. **Recovery** is SSH plus `totp-reset`, with no recovery codes.
- **Password:** `password_hash(PASSWORD_ARGON2ID)` when available, else bcrypt. Unknown users are verified against a dummy hash.
- **TOTP:** RFC 6238, SHA-1, 6 digits, 30 s steps, ±1 step, about 40 lines of PHP. **Replay is refused:** `totp_last_step` is stored and any code at or below it is rejected.
- **One login form:** user, password and code, with one generic error ("Thông tin đăng nhập không đúng").
- **Throttle** (in `admin.sqlite`; the IP is always 127.0.0.1): 5 failures per 15 min per username and 20 per hour overall,
  then a 15 min lockout. Every attempt is audited.
- **Session:** cookie `fxadm` holds 32 random bytes and is `HttpOnly; SameSite=Strict; Path=/`, plus `Secure` when served over https.
  Only the sha256 is stored. Idle timeout 30 min, absolute 12 h. The id is rotated at login. Logout deletes the row.
- **Step-up:** reveal, issue, restore, archive, replay, back up now, CSV export, the security-notice send, and
  advisory or settings edits need a TOTP entered within the last 10 min (a fresh code otherwise). Revoke needs typed confirmation instead, so it stays fast.

### 2.6 Machine tokens and attribution

There are four distinct bearer tokens. Install refuses if any two are equal.

| Token | Holder | Scope on `:9471` | Audit actor |
|---|---|---|---|
| admin (existing) | root CLI | everything | `cli` |
| `FLUXFILES_LICENSE_UI_TOKEN` (new) | UI pool | everything; `X-FluxFiles-Actor: ui:<user>` honoured | `ui:<user>` |
| `FLUXFILES_LICENSE_OPS_TOKEN` (new) | ops worker | **only** `/admin/ops/*` (outbox, ingest, health, summary, weekly report, mail jobs) | `ops` |
| update-status (existing) | update server | `/update-status` only | — |

Each token exists as two copies of the same value: a 0400 copy owned by the licence user and a 0400 copy owned by the client's user. This is the same pattern
`update-status.token` already uses. **The API writes all audit rows**, so a compromised UI or worker cannot edit history.
UI login and logout events go to the append-only `POST /admin/audit`.

### 2.7 Browser hardening (as on the landing `/success` page, but stricter)

Every UI response, including errors and static files, carries:

```
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:;
  font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none';
  require-trusted-types-for 'script'
Cache-Control: no-store
Referrer-Policy: no-referrer
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
```

- **No inline `<script>`, no `<style>`, no `style=`, no `on*=`, no `javascript:` URLs.** JS uses `textContent` and DOM APIs only.
  The theme is applied server-side via `<html data-theme>`. Strings JS needs go in `data-*` attributes, the same pattern as `success.js`.
  Inline `<svg>` charts are markup, not script, and are styled only by classes (§7.10).
- **CSRF:** `SameSite=Strict`, plus a per-session token (`_csrf` field / `X-CSRF-Token` header from `<meta name="csrf">`),
  plus `Origin` equal to `http(s)://<allow-listed Host>` on every non-GET request, plus `Sec-Fetch-Site: same-origin` when present.
  **GET never mutates.**
- **XSS:** every value goes through `h()` = `htmlspecialchars(…, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')`. Checkout name and email, notes,
  webhook fields, product ids and versions reported by installs are **all hostile**. No JSON is embedded in HTML.
- **Logs:** emails are masked (`logmask.php` approach). nginx logs `$uri` only.

### 2.8 Licence keys

- Lists, detail pages, CSV, Telegram, reports and audit `meta` **never contain a key**. Pages show `jti` and a fingerprint
  (the first 10 hex characters of `sha256(license_key)`).
- **Reveal** is `POST /licences/{jti}/reveal` (fetch, CSRF, step-up). The `<dialog>` holds a read-only field and a Copy button.
  It is cleared on close or after 60 s. Each reveal writes the `key.reveal` audit row.
- **Find by pasted key** sends the key in a POST body (`key.lookup`), never in a URL.
- A shared `client/Mask.php` refuses to emit any string that matches the licence-key pattern
  (`[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}`). The outbox, Telegram and CSV writers all go through it.

---

## 3. Information architecture

Navigation: `Bảng điều khiển` · `Giấy phép` · `Khách hàng` · **`Thống kê`** (Revenue, Customers, Installs, Versions,
Downloads, Mail, Growth, Demo, Anomalies) · `Webhook` · `Thư` · `Hoạt động` · `Hệ thống` · `Nhật ký quản trị`

| Route (UI; every POST checks CSRF and redirects afterwards: post-redirect-get) | Purpose | Phase |
|---|---|---|
| `GET/POST /login`, `POST /logout` | auth | MVP |
| `GET /` | dashboard | MVP |
| `GET /licences?q=&plan=&status=&gateway=&test=&archived=&cursor=` | list | MVP |
| `GET /licences/{jti}`; `POST /licences/{jti}/{revoke,restore,resend,mark-test,unmark-test,note}`; `POST /licences/{jti}/reveal`; `POST /lookup-key`; `POST /licences/archive-test` | licence actions | MVP |
| `POST /subscriptions/{sid}/revoke` | revoke every key of one subscription (existing `/revoke {subscription_id}`) | MVP |
| `GET/POST /issue` | manual issue | MVP |
| `GET /events`, `GET /events/{id}`, `POST /events/{id}/resolve` | webhook log | MVP |
| `POST /events/{id}/replay` | replay | P2 |
| `GET /stats/revenue`, `GET /stats/installs`, `GET /stats/mail` | statistics | MVP |
| `GET /stats/{customers,versions,downloads,growth,demo}` | statistics | P2 |
| `GET /stats/anomalies` | statistics | P3 |
| `GET /customers?q=`, `GET /customers/{chash}`, `POST /customers/{chash}/note` | `chash` = 16 hex chars of `sha256(lower(email))` | P2 |
| `GET /export/orders.csv?from=&to=` (step-up) | accounting | P2 |
| `GET /mail`, `POST /mail/{id}/resend`, `POST /mail/redeliver` | mail log | P2 |
| `GET /activity` | claims, Pro downloads, refresh | P2 |
| `GET /system`, `POST /system/{backup,test-mail,test-telegram}` | sanity checks, health and backups | MVP (status and test-telegram) / P2 (full) |
| `GET /advisories`, `POST /advisories`, `GET /notices/new?advisory=`, `POST /notices/{id}/send` | security notices | P3 (advisory list P2 for A5) |
| `GET /audit` | audit log | MVP |
| `POST /pref/{lang,theme}` | toggles | MVP |

---

## 4. Admin API (loopback `:9471`, admin-api pool)

All routes require a Bearer token and return JSON. **Reads never include `license_key`** (except reveal). Mutations write
`admin_audit`, plus `license_history` when state changes. Error codes: `unauthorized`, `forbidden_scope`, `not_found`,
`invalid_input`, `invalid_status`, `archive_not_allowed`, `rate_limited`, `internal`, and on the UI side also `csrf`,
`bad_host`, `stepup_required` and `api_unreachable`. Each has a vi/en message. Core's "i18n × 16" rule does not apply.

**Existing routes, extended (backward compatible):**

| Route | Change |
|---|---|
| `POST /issue` | Optional `is_test`, `note`, `deliver: "mail"\|"manual"\|"none"` (default `none` = today's CLI behaviour; `manual` sets `mailed_at`). The response gains `mailed`. |
| `POST /revoke` | The response gains `cascaded:[{jti,plan}]`. `{subscription_id}` already exists on feat/license-refresh-server. |
| `POST /redeliver` | Optional `limit`. The response gains `remaining`. |
| `POST /update-status` | Optional telemetry fields (§7.4). **The response is unchanged** and old callers keep working. |

**New routes (admin or UI token):**

| Route | Request → response |
|---|---|
| `GET /admin/stats` | dashboard numbers (§6.1) |
| `GET /admin/licences` | `q, plan, status, gateway, test, archived, cursor, limit≤100` → `{rows, next_cursor}` (masked rows; `q` matches email prefix, jti prefix, order, checkout or subscription id) |
| `GET /admin/licences/{jti}` | `{licence, chain, timeline, polar:{order_id,subscription_id,customer_id}, install:{first_seen_at,last_seen_at,nets_30d,versions}}` |
| `GET /admin/licences/{jti}/cascade-preview?status=` | `{affected:[…], reactivates?}` (read-only walk) |
| `POST /admin/licences/{jti}/reveal` · `POST /admin/lookup-key` | `{license_key}` · `{jti}` (both audited) |
| `POST /admin/licences/{jti}/resend` · `/flags` · `POST /admin/licences/archive` | — |
| `GET /admin/events[/{id}]`, `POST /admin/events/{id}/{replay,resolve}` | bodies are never returned (`has_body` only) |
| `GET /admin/mail`, `POST /admin/mail/{id}/resend` | — |
| `GET /admin/stats/{revenue,customers,installs,versions,downloads,mail,growth,demo,anomalies}` | `from`, `to` (≤ 24 months), `currency?` → series and table rows (§7) |
| `GET /admin/export/orders.csv` | CSV stream (§8.4) |
| `GET/POST /admin/customers…`, `GET/POST /admin/advisories…`, `POST /admin/notices…` | §8 |
| `GET /admin/system` | §6.7; secrets are reported as booleans only |
| `POST /admin/backup` · `POST /admin/test-mail` · `POST /admin/test-telegram` | the last one enqueues a test alert |
| `GET/POST /admin/audit` | the POST is append-only, UI token only |
| `GET /admin/meta` | plans, plan map, statuses, tracked discounts |

**New routes for the ops token only (`/admin/ops/*`):**

| Route | Request → response |
|---|---|
| `POST /admin/ops/outbox/claim` | `{channel:"telegram", limit≤20}` → `{items:[{id,kind,severity,text,suppressed}]}`. Leases each row for 120 s |
| `POST /admin/ops/outbox/ack` | `{id, ok, error?, retry_after?}` → `{ok}` |
| `POST /admin/ops/alert` | `{kind, severity, dedup_key, text}` → `{queued\|suppressed}`. The worker's own health alerts go through the same dedup |
| `POST /admin/ops/health` | `{results:[{target,ok,ms,detail,tls_days?}]}` |
| `POST /admin/ops/ingest` | `{type:"public_counters"\|"demo_daily"\|"downloads_daily", day, rows:[…]}`. Strict per-type validation |
| `GET /admin/ops/summary?scope=today\|stats` | pre-masked text lines for bot replies |
| `POST /admin/ops/report/weekly` | `{week:"YYYY-Www"}`. Idempotent: renders and mails the weekly report to the ops email and enqueues its Telegram digest |
| `POST /admin/ops/mail-jobs` | `{limit≤10}`. The admin-api pool claims and sends up to 10 queued e-mail jobs (security notices) and returns the counts |
| `GET /admin/ops/backup-status` | the `backup-status.json` contents |

**New public route (public pool):** `POST /webhook/resend`, Resend delivery events, Svix-signed (§7.7).

---

## 5. Data model

All changes go through `LicenseStore::migrate()` in its existing style: `CREATE TABLE IF NOT EXISTS`, and guarded
`addColumn()`. Put the new block behind `PRAGMA user_version`: when it is below `2`, run the block and set it to 2. Later phases bump it again.
That keeps a dozen DDL statements off every request. The deploy takes a backup before the first migrating request.
**Times are unix integers (UTC). Amounts are integers in minor units (cents).**

### 5.1 `licenses`, new columns

`subscription_id` and `billing_reason` already exist on feat/license-refresh-server and are not re-added.

| Column | Type | Meaning |
|---|---|---|
| `is_test` | INTEGER NOT NULL DEFAULT 0 | Manual comp/test rows. Excluded from stats |
| `archived_at` | INTEGER NULL | Hidden by default. Only for test rows that are not active. **Rows are never deleted** |
| `note` | TEXT NULL (≤ 500) | Operator note |
| `product_id` | TEXT NULL | The Polar product that issued the row |
| `first_seen_at` / `last_seen_at` | INTEGER NULL | First and last `/update-status` ping (§7.4). `first_seen_at IS NULL` means never installed |

### 5.2 Core admin tables (MVP)

```sql
CREATE TABLE IF NOT EXISTS license_history (          -- the truthful timeline
  id INTEGER PRIMARY KEY AUTOINCREMENT, jti TEXT NOT NULL, at INTEGER NOT NULL,
  kind TEXT NOT NULL,                 -- issued|status|superseded|cascade|mailed|flag|archived
  from_status TEXT, to_status TEXT,
  actor TEXT NOT NULL,                -- polar:<webhook-id>|cli|ui:<user>|ops|cron:reminder|system
  ref TEXT);
CREATE INDEX IF NOT EXISTS idx_hist_jti ON license_history(jti, at);

CREATE TABLE IF NOT EXISTS webhook_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL DEFAULT 'polar',  -- polar|resend
  webhook_id TEXT, received_at INTEGER NOT NULL, last_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 1,
  type TEXT, verify TEXT NOT NULL,    -- ok|bad_signature|stale|missing_headers|no_secret
  outcome TEXT NOT NULL,              -- issued|reused|refunded|ignored|unmapped_product|renewal_unmatched
                                      -- |mail_failed|sub_updated|mail_event|error
  http_status INTEGER NOT NULL,
  order_id TEXT, checkout_id TEXT, subscription_id TEXT, customer_id TEXT, product_id TEXT, jti TEXT,
  error TEXT,                         -- <= 300 chars, emails masked
  body BLOB, body_expires_at INTEGER, -- gzcompress(raw), retryable failures only, 7 days
  resolved_at INTEGER, resolved_by TEXT);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_hook ON webhook_events(source, webhook_id) WHERE verify = 'ok';
CREATE INDEX IF NOT EXISTS idx_hook_time ON webhook_events(received_at);
CREATE INDEX IF NOT EXISTS idx_hook_order ON webhook_events(order_id);

CREATE TABLE IF NOT EXISTS mail_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL,
  kind TEXT NOT NULL,                 -- licence|support|reminder:<bucket>|renewal_unmatched|ops|report|notice|test
  jti TEXT, recipient TEXT NOT NULL, transport TEXT NOT NULL,
  ok INTEGER NOT NULL, error TEXT,    -- send-time result; never the body
  provider_id TEXT,                   -- Resend email id
  status TEXT NOT NULL,               -- failed|accepted|delivered|bounced|complained|delayed
  status_at INTEGER, actor TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_mail_time ON mail_log(at);
CREATE INDEX IF NOT EXISTS idx_mail_jti ON mail_log(jti);
CREATE INDEX IF NOT EXISTS idx_mail_provider ON mail_log(provider_id);

CREATE TABLE IF NOT EXISTS admin_audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL,
  actor TEXT NOT NULL, client TEXT NOT NULL,   -- ui|cli|ops
  action TEXT NOT NULL, target TEXT, result TEXT NOT NULL, reason TEXT, meta TEXT);
CREATE INDEX IF NOT EXISTS idx_audit_time ON admin_audit(at);
```

Rules for `webhook_events`:

- Verified events upsert on `(source, webhook_id)`, so retries collapse into one row.
- Unverified events never store a body and never trust a field. They aggregate into **one row per (hour, reason)**.
- A body is stored only for `unmapped_product`, `renewal_unmatched` and `error`. It is cleared on later success, on resolve, or after **7 days**.
- Recording sits in a wrapper around the webhook branch. **A failure to record never changes the HTTP status sent to Polar.**
  Polar disables an endpoint after 10 consecutive non-2xx responses.

`mail_log` hook: `LicenseMailer` (which on feat/license-mail-html funnels every send through `deliver()`) gains an
optional `?callable $onResult` constructor argument, called with `(kind, to, ok, error, providerId)`. Resend's send response `id` becomes
`provider_id`. Callers pass a closure that writes to the store. The mailer stays dependency-free.

Audit actions: `login.ok|login.fail|logout|key.reveal|key.lookup|key.bulk_read|licence.issue|licence.revoke|licence.restore|
subscription.revoke|licence.flag|licence.archive|mail.resend|mail.redeliver|webhook.replay|webhook.resolve|backup.run|mail.test|
telegram.test|export.orders|customer.note|advisory.edit|notice.send`.

### 5.3 Revenue and growth tables

```sql
CREATE TABLE IF NOT EXISTS orders (                   -- MVP (A1, A3): one row per Polar order
  order_id TEXT PRIMARY KEY, gateway TEXT NOT NULL DEFAULT 'polar',
  created_at INTEGER, paid_at INTEGER, refunded_at INTEGER,
  status TEXT NOT NULL,               -- paid|partially_refunded|refunded
  customer_id TEXT, email_lower TEXT, product_id TEXT, plan TEXT, billing_reason TEXT, subscription_id TEXT,
  currency TEXT NOT NULL,             -- lower-case ISO 4217, as Polar sends it
  subtotal_amount INTEGER, discount_amount INTEGER, net_amount INTEGER, tax_amount INTEGER, total_amount INTEGER,
  refunded_amount INTEGER NOT NULL DEFAULT 0, refunded_tax_amount INTEGER NOT NULL DEFAULT 0,
  discount_id TEXT, discount_code TEXT,
  jti TEXT,                           -- licence issued for it (NULL: unmapped/unmatched)
  updated_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_orders_paid ON orders(paid_at);
CREATE INDEX IF NOT EXISTS idx_orders_sub ON orders(subscription_id);
CREATE INDEX IF NOT EXISTS idx_orders_discount ON orders(discount_code);

CREATE TABLE IF NOT EXISTS install_pings (            -- MVP (A4): daily aggregate per licence/module/network
  day TEXT NOT NULL,                  -- YYYY-MM-DD, UTC
  jti TEXT NOT NULL, module TEXT NOT NULL,
  net TEXT NOT NULL DEFAULT '',       -- 16 hex of HMAC-SHA256(pepper, "/24 or /64 prefix"); '' if unknown
  version TEXT, core_version TEXT,    -- last value reported that day
  install INTEGER NOT NULL DEFAULT 0, -- 1 if any first-install request (install=1)
  active INTEGER NOT NULL,            -- last eligibility answer
  n INTEGER NOT NULL, first_at INTEGER NOT NULL, last_at INTEGER NOT NULL,
  PRIMARY KEY (day, jti, module, net)) WITHOUT ROWID;

CREATE TABLE IF NOT EXISTS ops_outbox (               -- MVP (C2) + P3 e-mail jobs (C5)
  id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER NOT NULL,
  channel TEXT NOT NULL,              -- telegram|email
  kind TEXT NOT NULL, severity TEXT NOT NULL,   -- info|warn|crit
  dedup_key TEXT NOT NULL, text TEXT NOT NULL,  -- already masked, <= 1000 chars
  payload TEXT,                       -- e-mail jobs only: {"notice_id":..,"jti":..}
  attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL, lease_until INTEGER,
  sent_at INTEGER, dropped_at INTEGER, last_error TEXT, suppressed INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_outbox_due ON ops_outbox(channel, sent_at, dropped_at, next_at);
CREATE INDEX IF NOT EXISTS idx_outbox_dedup ON ops_outbox(dedup_key, created_at);

CREATE TABLE IF NOT EXISTS ops_state (key TEXT PRIMARY KEY, value TEXT, updated_at INTEGER);   -- MVP: idempotency (weekly report…)
CREATE TABLE IF NOT EXISTS health_checks (            -- MVP (C2 subset) / P2 screen (C3)
  target TEXT NOT NULL, at INTEGER NOT NULL, ok INTEGER NOT NULL, ms INTEGER, tls_days INTEGER, detail TEXT,
  PRIMARY KEY (target, at)) WITHOUT ROWID;
```

**Phase 2 and 3 tables:**

```sql
CREATE TABLE IF NOT EXISTS subscriptions (            -- P2: follows Polar (§10.2)
  subscription_id TEXT PRIMARY KEY, customer_id TEXT, product_id TEXT,
  status TEXT,                        -- active|canceled|revoked|past_due
  cancel_at_period_end INTEGER, current_period_end INTEGER, canceled_at INTEGER, revoked_at INTEGER,
  updated_at INTEGER NOT NULL, last_webhook_id TEXT);
CREATE TABLE IF NOT EXISTS ops_items (                -- P2: "needs attention" queue
  id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL,
  kind TEXT NOT NULL,                 -- unmapped_product|renewal_unmatched|cascade|mail_failed|refund_sub_active|never_installed
  ref_jti TEXT, ref_order TEXT, ref_event INTEGER, summary TEXT NOT NULL, resolved_at INTEGER, resolved_by TEXT);
CREATE TABLE IF NOT EXISTS activity_daily (           -- P2: claim/pro-download/refresh counts, NO IPs
  day TEXT NOT NULL, jti TEXT NOT NULL, kind TEXT NOT NULL, result TEXT NOT NULL,   -- jti '' = unknown key/checkout
  n INTEGER NOT NULL, last_at INTEGER NOT NULL, PRIMARY KEY (day, jti, kind, result)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS downloads_daily (          -- P2 (A6)
  day TEXT NOT NULL, module TEXT NOT NULL, version TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL,               -- zip_log|pro_link_claim|pro_link_download
  n INTEGER NOT NULL, PRIMARY KEY (day, module, version, source)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS public_counters (          -- P2 (B2)
  day TEXT NOT NULL, source TEXT NOT NULL, subject TEXT NOT NULL, metric TEXT NOT NULL, value INTEGER NOT NULL,
  PRIMARY KEY (day, source, subject, metric)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS demo_daily (               -- P2 (B3)
  day TEXT PRIMARY KEY, sandboxes_created INTEGER, sandbox_limit_hits INTEGER, page_limit_hits INTEGER,
  sandboxes_live INTEGER, uploads_bytes INTEGER);
CREATE TABLE IF NOT EXISTS customer_notes (           -- P2 (C4)
  email_lower TEXT PRIMARY KEY, note TEXT NOT NULL, updated_at INTEGER NOT NULL, updated_by TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS advisories (               -- P2 (A5) / P3 (C5)
  id TEXT PRIMARY KEY,                -- e.g. FXSA-2026-001
  component TEXT NOT NULL,            -- core | <module id> | fluxfiles-pro
  affected TEXT NOT NULL,             -- constraint, e.g. ">=1.0.0 <1.1.9 || <0.2.90"
  fixed_in TEXT, severity TEXT NOT NULL, title TEXT NOT NULL, url TEXT, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notices (                  -- P3 (C5)
  id INTEGER PRIMARY KEY AUTOINCREMENT, advisory_id TEXT NOT NULL, created_at INTEGER NOT NULL, created_by TEXT NOT NULL,
  subject TEXT NOT NULL, body_text TEXT NOT NULL, recipients INTEGER NOT NULL, sent INTEGER NOT NULL DEFAULT 0,
  failed INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL);      -- draft|sending|done
```

### 5.4 UI-owned `admin.sqlite` (`/var/lib/fluxfiles-license-admin/`, 0600)

`users(id, name UNIQUE, pass_hash, totp_secret, totp_last_step, disabled, created_at)`,
`sessions(id_hash PK, user_id, created_at, last_seen, stepup_at, csrf, lang, theme)`, `login_hits(bucket, win, n)`.
This DB is **not backed up**. Losing it means re-enrolling with the CLI.

### 5.5 Retention

Pruning happens on write with a 1-in-50 chance, the same way `rate_hits` already works. The ops worker also calls the prune once a day.

| Data | Kept |
|---|---|
| `licenses`, `license_history`, `orders` | forever (record of truth and accounting) |
| `webhook_events` metadata / body | 180 days / **7 days** or until resolved |
| `mail_log` | 365 days |
| `admin_audit` | 2 years |
| `install_pings`, `activity_daily` | **90 days** |
| `downloads_daily`, `demo_daily`, `public_counters` | 2 years (tiny) |
| `health_checks` | 30 days |
| `ops_outbox` (sent or dropped) | 30 days |
| `ops_items` (resolved) | 180 days |
| DB backups | 30 days (unchanged) |

Constants live in `LicenseStore` and are not env vars.

---

## 6. Admin screens

**Conventions:** 50 rows per page with keyset paging. Times use `Asia/Ho_Chi_Minh` with relative age, and the UTC ISO time in `title`.
Ids are monospace and truncated in the middle, with copy. Status pills show **text and colour**: active = green, superseded = slate,
revoked = red, refunded = amber, `THỬ` (test) = violet, archived = dimmed.

**States:**
- **Loading:** server-rendered pages have none. Buttons that trigger JS (reveal, preview) show a spinner and are disabled.
- **Error:** an unreachable API shows the banner "Không kết nối được API giấy phép (127.0.0.1:9471)" with a retry link. Other cards still render.
- **Empty:** one explanatory line plus the next step.

### 6.1 Dashboard

| Card | Definition |
|---|---|
| Cần xử lý (Needs attention) | **MVP:** unresolved webhook failures and undelivered licences. **P2:** all of `ops_items` |
| Doanh thu tháng này | net revenue this month per currency, versus last month (§7.1) |
| MRR | §7.1 |
| FOUNDING40 | redemptions out of the cap, plus revenue (§7.3) |
| Đang dùng / chưa cài | active installs over 30 days, and bought-but-never-installed (§7.4) |
| Đang hoạt động theo gói | `status='active' AND is_test=0 AND archived_at IS NULL`, grouped by plan |
| Mới 7 ngày | new paid orders, split into purchase, cycle and renewal |
| Sắp hết hạn ≤ 30 ngày | split into perpetual ("updates end") and subscription ("stops after grace") |
| Hoàn tiền 30 ngày | count and amount |
| Webhook lỗi 7 ngày, Thư lỗi/bị trả | from `webhook_events` and `mail_log` |
| Sao lưu | age of the last OK backup: amber after 26 h, red after 50 h |
| Telegram | last successful send, plus queue depth |

```
+---------------------------------------------------------------------------------+
| FluxFiles · Quản trị giấy phép                 [VI|EN] [◐]  owner ▾  [Đăng xuất]|
+---------------+-----------------------------------------------------------------+
| > Bảng đ.khiển| ! CẦN XỬ LÝ (2)                                                 |
|   Giấy phép   |   Sản phẩm chưa ánh xạ prod_…9f2 · 2 lần thử              [Xem] |
|   Khách hàng  |   Hoàn tiền ord_…88 nhưng gói tháng còn chạy        [Mở Polar ↗]|
|   Thống kê    +------------+------------+-------------+------------+------------+
|   Webhook     | Doanh thu  | MRR        | FOUNDING40  | Đang dùng  | Sao lưu    |
|   Thư         | tháng này  | (USD)      | 23 / 50     | 31 / 40    | 5 giờ trước|
|   Hoạt động   | ▲ so th.trc| ▲          |             | 6 chưa cài | ✓ ok       |
|   Hệ thống    +------------+------------+-------------+------------+------------+
|   Nhật ký     | Theo gói: pro 21 · pro-monthly 9 · studio 8 …   Telegram ✓ 2'   |
+---------------+-----------------------------------------------------------------+
```

The numbers are illustrative.

### 6.2 Licences

**List.** Search box (`/` focuses it). "Tìm theo khóa" (find by key) opens a dialog that POSTs the key.
Filters: plan, status, gateway, test, archived, and "gộp chu kỳ" (collapse cycles, on by default: one row per `subscription_id` with a `×N` badge).
Columns: created · email · plan · status · expires · gateway · jti · fingerprint · last seen (§7.4) · flags.

```
+-----------------------------------------------------------------------------------+
| Giấy phép    [/ Tìm email, jti, mã đơn…        ] [Tìm theo khóa] [+ Cấp thủ công] |
| Gói[tất cả▾] Trạng thái[hoạt động▾] Cổng[tất cả▾] Thử[ẩn▾] [x] Gộp chu kỳ         |
+------------+----------------------+-------------+-----------+--------+------------+
| Ngày       | Email                | Gói         | Trạng thái| Hết hạn| Thấy lần cuối|
+------------+----------------------+-------------+-----------+--------+------------+
| 2026-11-06 | an.nguyen@…          | pro-monthly | hoạt động | 25 ngày| 2 giờ   ×4 |
| 2026-11-02 | ops@acme.example     | studio      | hoạt động | 361 ng.| chưa cài   |
+------------+----------------------+-------------+-----------+--------+------------+
| j/k di chuyển · Enter mở · / tìm · x chọn                 [‹ Trước]  [Sau ›]       |
+-----------------------------------------------------------------------------------+
```

**Detail page.** The header shows identity, plan, modules, enforcement, status, dates, jti, fingerprint and an inline note.

**Panels:**
- **Polar:** order, checkout, subscription and customer ids, each with a deep link (§10.1).
- **Chain:** base → renewals, or the subscription cycles.
- **Installs:** first and last seen, networks over 30 days, and versions per module.

**Timeline** (newest first), merged from:
- `license_history`;
- `mail_log` (send and delivery state);
- `webhook_events` for the order or subscription;
- `admin_audit` for this jti;
- `install_pings`, collapsed per day ("update-status ×12 · share 1.1.9 · core 1.1.9");
- `activity_daily` (P2: claims, Pro downloads, refresh).

| Action | Allowed when | Confirmation | API |
|---|---|---|---|
| Thu hồi / Đánh dấu hoàn tiền | active, superseded | a **cascade preview** and typing the last 6 characters of the jti | `/revoke` |
| Thu hồi cả thuê bao | the row has a `subscription_id` | preview of the row count, plus typed confirmation | `/revoke {subscription_id}` |
| Khôi phục | revoked, refunded | step-up, plus the note "renewals cancelled by a cascade are not revived" | `/revoke status=active` |
| Gửi lại email | not revoked | shows the recipient | `/admin/…/resend` |
| Hiện khóa / Sao chép | any | step-up and an optional reason | `/admin/…/reveal` |
| Đánh dấu thử / bỏ | any | a toast with Undo | `/admin/…/flags` |
| Lưu trữ | `is_test=1`, not active | confirm | `/admin/licences/archive` |

**Manual issue.**
- **Fields:** email*, plan* (from `/admin/meta`), customer, order id (default `manual-…`), note, `[x] Giấy phép thử` (test licence).
- **Delivery** is a **required** choice: "Gửi email ngay" (`deliver=mail`) or "Tôi tự gửi (đánh dấu đã giao)" (`deliver=manual`).
- Requires step-up.
- The result page offers [Hiện khóa] and does not show the key automatically. API errors appear inline.

### 6.3 Customers (P2)

Customers are grouped by `lower(email)`, with the distinct `customer_id`s shown. Two Polar customers on one email is the renewal-mismatch signal, so it is flagged.
- **Columns:** email · licences (active/total) · plans · first and last purchase · lifetime net revenue · last seen · note.
- **Detail page:** licences, orders, timeline, the **note** (C4, editable and audited) and a [Polar customer ↗] link.

### 6.4 Webhook events

- **Columns:** received · source · type · verify · outcome · HTTP · order · product · attempts · resolved.
- **Default filter:** failures.
- Unverified events show as aggregate rows ("chữ ký sai ×14 trong giờ này").
- **Detail page:** metadata, the error, the licence, [Giải quyết] (resolve), and in P2 [Phát lại] (replay, step-up).
- **Replay** re-runs the stored verified body through `handlePolarEvent(array $body, string $source)`, which the webhook branch is refactored into.
  It is safe because issuance is idempotent on `(gateway, order_id)`.
- When no body was stored, the page says "dùng Redeliver trong Polar" (use Redeliver in Polar).

```
+--------------------------------------------------------------------------------+
| Webhook   Nguồn[polar▾] Kết quả[lỗi▾] Loại[tất cả▾] Từ[2026-11-01]             |
+------------+--------------+--------+------------------+-----+---------+--------+
| Nhận lúc   | Loại         | Ký     | Kết quả          | HTTP| Đơn     | Lần    |
+------------+--------------+--------+------------------+-----+---------+--------+
| 11-08 09:12| order.paid   | ok     | unmapped_product | 503 | ord_…9a | 3      |
| 11-07 03:00| —            | sai ×14| —                | 401 | —       | 14     |
+------------+--------------+--------+------------------+-----+---------+--------+
```

### 6.5 Mail log (P2)

- **Columns:** time · kind · recipient · licence · transport · send result · delivery status · actor.
- **Filters:** failures and bounces only, kind, and date.
- **Actions:** **Gửi lại** (resend) and **Gửi bù tất cả** (send the backlog with `limit=10`, repeated until `remaining=0`).

### 6.6 Activity (P2)

Daily tables of `/claim` (ok / expired / not found), `/pro-download` (ok / denied / rate-limited, plus unknown keys) and `/refresh`
(current / refreshed / refused / rate-limited). A spike in `jti=''` means someone is guessing keys.

### 6.7 System

| Check | Shown | Phase |
|---|---|---|
| Plan map vs `Plans::all()`, plus products seen in webhooks but unmapped | ✓ or ✗ per product | MVP |
| Signing key: the admin-api pool derives the public key and fingerprint from k1 and compares them with `FLUXFILES_LICENSE_K1_PUB` | "khớp với core" (matches core) ✓ or ✗ | P2 |
| Secrets present (booleans only; the four tokens are distinct; webhook secrets; mail; Telegram configured on the worker side, as reported by its last heartbeat) | ✓ or ✗ | MVP |
| Mail: transport, from address, a TLS connect to `api.resend.com:443`, [Gửi thư thử] (send test mail) | | MVP |
| Telegram: last send, queue depth, last error, [Gửi tin thử] (send test message) | | MVP |
| Landing origin, update URL (`ProDownload::configured()` + HEAD). **Never** anything that triggers `/update-status` | | P2 |
| Health (C3): uptime per target over 30 days, TLS days left, disk | | P2 (alerts are MVP) |
| DB: size, WAL, row counts, `user_version`, build (`app/SOURCE`) | | MVP |
| Backups (`backup-status.json`), [Sao lưu ngay] (back up now) | | MVP status / P2 button |

**DB copies are never downloadable over HTTP.**

### 6.8 Admin audit

- **Columns:** time · actor · client · action · target · result · reason.
- **Filters:** actor, action, jti and date, plus "chỉ truy cập khóa" (key access only: `key.*`).
- Read-only.

### 6.9 Login and mobile

```
+-------------------------------+        +---------------------------+
|  FluxFiles · Quản trị         |        | ☰ Bảng điều khiển    ◐ VI |
|  Tên đăng nhập [__________]   |        +---------------------------+
|  Mật khẩu      [__________]   |        | ! Cần xử lý (1)        ›  |
|  Mã 6 số       [______]       |        | Doanh thu tháng    ▲      |
|  [ Đăng nhập ]                |        | MRR                ▲      |
|  Cần đường hầm SSH: localhost |        | Đang dùng 31 · chưa cài 6 |
+-------------------------------+        | Sao lưu   5 giờ trước ✓   |
                                         +---------------------------+
```

- **Narrow screens:** below 640 px, tables become stacked cards, actions sit in a sticky bottom bar, and touch targets are at least 44 px.
- **On a phone** the target tasks are quick checks: the dashboard, statistics and licence lookup. Telegram covers anything urgent.

---

## 7. Statistics and growth

Every statistic is computed **on read** from the tables in §5, by SQL in `LicenseStore` (or `Stats.php`), and exposed by
`GET /admin/stats/*`. Nothing is pre-aggregated except the `*_daily` tables. Test rows (`is_test=1`) and manual
orders are excluded everywhere. Production holds no sandbox data. **Currencies are never summed together:**
each card and chart is per currency, with a currency selector when more than one exists.

### 7.1 A1 Revenue (MVP)

**Source.** Every **verified** `order.paid` upserts `orders`, whatever the issuance outcome, so unmapped and unmatched
orders still count as money received. Every `order.refunded` updates `refunded_amount`, `refunded_tax_amount`, `refunded_at` and `status`.
That includes partial refunds, which do not touch the licence (unchanged behaviour).

**Fields** are taken from the Polar Order object [5]: `currency`, `subtotal_amount`, `discount_amount`, `net_amount`
(after discount, before tax), `tax_amount`, `total_amount`, `refunded_amount`, `refunded_tax_amount`, `discount_id`,
`discount.code`, `billing_reason`, `subscription_id`, `created_at`. The parser is `PolarWebhook::orderFacts(array $body)`:
strict ints, ids cleaned the way `cleanId()` does, unknown values left NULL. The upsert sits in a try/catch and **never blocks issuance**.

**Backfill.** `backfill-orders.php` follows the `backfill-subscription-ids.php` pattern: CLI only, dry-run by default,
`--apply`, the token from env or `--token-file`, never stored. It calls `GET /v1/orders/{id}` for every Polar licence row with no `orders` row,
or lists orders when `--all`.

**Definitions** (calendar month in `Asia/Ho_Chi_Minh`; the CSV also carries UTC):

| Metric | Definition |
|---|---|
| Net revenue (month) | Σ `net_amount` of orders with `paid_at` in the month, minus Σ refund-net of refunds with `refunded_at` in the month. Refund-net = `refunded_amount − refunded_tax_amount` |
| Gross / tax | Σ `total_amount` / Σ `tax_amount` (informational; tax is not revenue) |
| Per plan | the same, grouped by `orders.plan` (unmapped products appear as `(chưa ánh xạ)`) |
| Refunds | count and refund-net per month, and refund rate = refund-net / net |
| Discounts | Σ `discount_amount` by `discount_code` |
| MRR (MVP proxy) | For each `subscription_id` whose newest licence row is `active` and `expires > now`: that subscription's latest non-refunded order `net_amount` ÷ interval months. A plan with `ttlDays ≤ 31` is monthly; one with `ttlDays ≥ 365` is ÷ 12 |
| MRR (P2 exact) | Use the `subscriptions` table. Exclude `cancel_at_period_end=1`, and show `past_due` as a separate line |
| ARR | MRR × 12 |
| One-time revenue | orders with no `subscription_id` (lifetime, renewals). Never in MRR |

Polar platform fees and payouts are **not** in the order. The screen says so and links to Polar's finance page.

**Screen `/stats/revenue`:**
- KPI row: net this month, net last month, MRR, ARR, refunds over 30 days.
- A 12-month stacked bar of net per plan.
- A line of MRR at each month end, recomputed from historical orders as an estimate.
- A refunds table.
- [Xuất CSV] (export CSV) in P2.

### 7.2 A2 Customers (P2; needs the subscription webhooks)

The customer key is `customer_id`, or `lower(email)` when the customer id is absent.

| Metric | Definition |
|---|---|
| New | customers whose first paid order is in the month |
| Renewed | customers with a `subscription_cycle` order or a `*-renewal` plan order in the month |
| Churned | subscriptions `revoked` in the month (P2), plus one-time licences whose update window ended in the month with no renewal ("hết hạn cập nhật", update window ended, shown separately) |
| Retention by purchase month | rows = cohort month of first purchase; columns = months 0…12 since purchase; cell = % of the cohort with an active subscription or an unexpired update window at that month end. Shown as a table with `.heat-0…5` classes |

### 7.3 A3 FOUNDING40 (MVP)

Config `FLUXFILES_ADMIN_TRACKED_DISCOUNTS="FOUNDING40:50"` is a list of `CODE:CAP` pairs. It is matched case-insensitively on
`orders.discount_code`, and on `discount_id` once one is known.
- **Card:** paid redemptions out of the cap, refunded redemptions (shown separately), net revenue from those orders, last redemption, and a progress bar.
- **Alert:** the Telegram alert `discount.cap` fires at 80 % and at 100 % of the cap.
- **Open question (§16):** do refunded redemptions free a slot in Polar?

### 7.4 A4 Active installs (MVP)

**Source.** On every paid-module `fluxfiles update` and every WordPress Pro update check, the update server already calls
`POST /update-status` with the licence's `jti`. **The update server is extended** (in the deploy copy and in
`docs/update-server.example.php`) to send what it already knows:

```json
{"jti":"<24 hex>","module":"share","current":"1.1.8","core_version":"1.1.9","install":false,"net":"203.0.113.0/24"}
```

- `module`: from the URL path. `current`: the `?current=` query parameter. `core_version`: its `CoreVersion::normalize()` result.
  `install`: whether the request had `install=1`.
- `net`: the caller's `REMOTE_ADDR` reduced to its /24 for IPv4 or /64 for IPv6. The full address is never sent. It travels only over loopback and is **never logged**.
  The update vhost must keep real client IPs (grey-cloud DNS, as the licence host). If it is ever proxied, use nginx `real_ip` from `CF-Connecting-IP`.
- **Every field is optional.** An old update server sending only `jti` still works.

**Status pool handling** (keep it cheap; it is the revocation check):

1. Validate the fields strictly:
   - `module ^[a-z0-9-]{1,64}$`;
   - versions `^[0-9]{1,4}(\.[0-9]{1,4}){1,3}([.+-][0-9A-Za-z.-]{1,20})?$`;
   - `net` a valid v4 /24 or v6 /64 prefix.

   An invalid optional field is **dropped**, never answered with 400, so a sloppy caller can't break updates.
2. Compute the answer (`isUpdateEligible`) and **send the response first**: `echo` it, then call `fastcgi_finish_request()`
   when that function exists (php-fpm). Under `php -S` the recording runs inline.
3. Record with `PRAGMA busy_timeout=250`, inside a try/catch:
   - one `INSERT … ON CONFLICT(day,jti,module,net) DO UPDATE SET n=n+1, last_at=?, version=?, core_version=?, active=?, install=max(install,?)`;
   - one `UPDATE licenses SET last_seen_at=?, first_seen_at=COALESCE(first_seen_at,?) WHERE jti=? AND (last_seen_at IS NULL OR last_seen_at < ?)`,
     with the last `?` being the start of today (UTC), so it writes at most once per licence per day.

   On `SQLITE_BUSY` the ping is **dropped**. Telemetry is lossy by design.
4. Hash the network as `net = substr(hash_hmac('sha256', $prefix, $pepper), 0, 16)`. The pepper is a 0400 file
   (`FLUXFILES_LICENSE_NET_PEPPER_FILE`, 32 random bytes) readable by the licence user only. No pepper means `net=''`, which fails private.
   Deleting the pepper makes every stored hash unlinkable.

**Definitions.**

| Metric | Definition |
|---|---|
| Active installs (30 days) | distinct `jti` with `last_seen_at ≥ now − 30 d`, among active non-test licences that have modules |
| Bought, never installed | active non-test licences with modules, `first_seen_at IS NULL`, created more than 7 days ago |
| Gone quiet | `first_seen_at` set but `last_seen_at < now − 30 d` (possible churn) |
| Networks per licence | distinct `net` over 30 days. This is an *approximate* site count, and never an enforcement signal (plans are unlimited-site) |

Support-only plans (`modules=''`) never ping and are excluded. Licences that never run the updater also look "never installed".
The screen states both caveats.

**Screen `/stats/installs`:**
- KPI row: active over 30 days, never installed, gone quiet.
- A daily line of distinct active licences.
- A table of never-installed licences with [Gửi lại email] (resend email), plus a P2 ops item `never_installed`.
- A table of gone-quiet licences.

**Privacy.** Add **one line to the landing Terms** (landing repo, `src/sections/pages/TermsBody.astro` and its localized strings):
*"The update server records installed versions to support you and send security notices."*
That change is in the landing repo. It is listed in §17.6 and is not part of this repo's handoff.

### 7.5 A5 Field versions (P2)

**Source:** `install_pings`, taking the latest `(version, core_version)` per `(jti, module)` over the last 30 days.

**Screen `/stats/versions`:**
- A horizontal bar per component (core and each module) showing installs per version.
- An "Advisories" panel listing each `advisories` row with its affected count. Each install's version is matched against `affected`
  by a small `VersionRange::matches(string $v, string $constraint)`: space = AND, `||` = OR, operators `< <= > >= = !=`,
  and comparison via `version_compare()` after stripping a leading `v`.
- Installs with an unknown version (`core_version` is NULL for cores older than 1.0.6) are counted as **"không rõ — có thể bị ảnh hưởng"** (unknown, possibly affected).

Advisories are created and edited in the UI (step-up, audited).

### 7.6 A6 Downloads (P2)

There are three sources, kept distinct on screen:

| Source | Counts |
|---|---|
| `pro_link_claim` / `pro_link_download` | FluxFiles Pro (WordPress) links handed out by `/claim` and `/pro-download`. Incremented in the public pool by one UPSERT in `downloads_daily` |
| `zip_log` | actual zip fetches (`GET /dl/m/<zip>` and `/dl/free/<zip>` with status 200 or 206) from the update host's nginx log, counted by the root collector (§8.6) and ingested by the worker |
| update checks | manifest issuance. These are **checks, not downloads**, and are taken from `install_pings.n` |

Screen: a daily stacked bar per module, plus a table per version.

### 7.7 A7 Mail deliverability (MVP)

`mail_log` gives the send-time result: `failed`, or `accepted` with the Resend `provider_id`.
**Delivery events** arrive through a new public route `POST /webhook/resend` on the public pool:

- Resend signs with Svix headers (`svix-id`, `svix-timestamp`, `svix-signature`), which is the **same algorithm as Standard Webhooks** [9].
  Reuse `PolarWebhook::verify()` with the headers mapped. The secret is `FLUXFILES_RESEND_WEBHOOK_SECRET` (file).
- Handle `email.delivered`, `email.bounced`, `email.complained` and `email.delivery_delayed`. Update the `mail_log` row with that `provider_id`
  (status and `status_at`), recorded in `webhook_events` with `source='resend'`. Unknown ids are ACKed and ignored.
  ACK 200 unless the DB itself fails.
- nginx: `location = /webhook/resend` with `limit_except POST`, `client_max_body_size 64k`, and the same rate zone as the Polar hook.
- A bounce of a `licence` mail creates an ops item (P2) and a Telegram alert (MVP).

If no Resend webhook is configured, the screen shows send results only, with "chưa bật webhook Resend" (Resend webhook not enabled).

**Screen `/stats/mail`:**
- Per kind over 30 and 90 days: sent, delivered, bounced, complained, failed.
- Delivery rate = delivered / accepted. Bounce rate = bounced / accepted.
- A table of recent bounces with masked recipients and a link to the licence.

### 7.8 A8 Anomalies (P3)

| Signal | Rule (thresholds are constants, shown on screen) |
|---|---|
| Key spread | a jti with more than 20 distinct `net` values over 30 days. "Có thể lộ khóa" (key may be leaked). Informational only: **never auto-revoke** |
| Refresh hammering | `activity_daily kind=refresh result=rate_limited` above 3 per day for a jti, or many `jti=''` refusals per day |
| Pro-download guessing | `pro_download` `jti=''` failures above 20 per day |
| Bad webhook signatures | more than 10 per hour (also a Telegram alert in MVP) |

### 7.9 B Growth

- **B1 Landing analytics (P2, link only).** Use **Cloudflare Web Analytics** (free, privacy-first) [10] on the landing site and
  only link to it from the Growth screen. If the beacon is used, the landing CSP must allow `static.cloudflareinsights.com`
  (script) and `cloudflareinsights.com` (connect) [11]. **Never on `/success` or `/download`**, which show or accept keys and
  keep their strict CSP. One third-party review reports the beacon may set a cookie [12], so check that before claiming "cookieless" in the Terms.
- **B2 Public counters (P2).** Once a day the ops worker fetches public, keyless APIs (5 s timeout each, at most 20 requests) and ingests
  them into `public_counters`. Subjects come from `FLUXFILES_OPS_PUBLIC_COUNTERS` (JSON).

  | Source | Endpoint | Metrics |
  |---|---|---|
  | WordPress.org | `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=<slug>` [13] | `active_installs`, `downloaded` |
  | GitHub | `https://api.github.com/repos/<owner>/<repo>` | `stargazers_count`, `forks_count` |
  | Packagist | `https://packagist.org/packages/<vendor>/<pkg>/stats.json` | `downloads.total`, `downloads.monthly` |
  | npm | `https://api.npmjs.org/downloads/point/last-week/<pkg>` | `downloads` |

  Verify each endpoint's response shape with a fixture. A failed fetch keeps the previous value and records the error in `health_checks`
  (target `counter:<source>`). Screen `/stats/growth`: a sparkline per subject over 12 months.
- **B3 Demo usage (P2).** Read from a **counts-only JSON** written by a root collector (§8.6) that parses the demo
  host's nginx log and the demo cleaner's summary line. Neither the worker nor any pool ever reads a log file.
  Fields go into `demo_daily`. Screen `/stats/demo`: sandboxes per day, limit hits per day, and the uploads gauge against the budget.

### 7.10 Charts

- `admin/src/Svg.php` renders `bar()`, `stackedBar()`, `line()`, `sparkline()` and `heatTable()` as **inline `<svg>`**:
  `viewBox`, `class="chart"`, `role="img"` and `aria-labelledby` pointing at a `<title>`/`<desc>`.
- Bars and points carry a `<title>` child for hover, and every label goes through `h()`.
- **Styling uses classes only** (`.chart .s1…s6 { fill: var(--series-1) … }`, `.axis`, `.grid`, `.heat-0…5`). There are no `style=` attributes
  and no `<style>` element, so the CSP holds. Presentation attributes (`x`, `y`, `width`, `height`, `d`) are allowed. Both themes are covered by CSS variables.
- **Every chart has a table fallback:** `<details><summary>Bảng số liệu</summary><table>…</table></details>` with the same numbers. It is also what tests assert on.
- Amounts are formatted from minor units using a currency-exponent table (`usd, eur → 2`; `vnd, jpy → 0`; default 2), with `tabular-nums`.

```
+--------------------------------------------------------------------------------+
| Thống kê › Doanh thu     Tiền tệ[USD▾]  Khoảng[12 tháng▾]           [Xuất CSV] |
| Thuần tháng này | Tháng trước | MRR | ARR | Hoàn tiền 30n                       |
| ▇▇ ▇▇ ▇▇▇ ▇▇ ▇▇▇▇ ▇▇▇ ▇▇▇▇ ▇▇▇▇▇ ▇▇▇▇ ▇▇▇▇▇ ▇▇▇▇▇▇ ▇▇▇▇▇  (stacked by plan)    |
| ▸ Bảng số liệu                                                                 |
+--------------------------------------------------------------------------------+
```

---

## 8. Alerts, reports and operations

### 8.1 C2 Telegram alerts (MVP)

**Outbound only.** The worker calls `POST https://api.telegram.org/bot<token>/sendMessage` with
`{chat_id, text, link_preview_options:{is_disabled:true}}` and **no `parse_mode`**. Plain text means a buyer-controlled name
cannot inject markup or links. There is no public webhook endpoint, and the worker never calls `setWebhook`.

**Secrets and config:**
- The bot token lives in `/etc/fluxfiles-license-ops/telegram.token` (0400 fluxfiles-license-ops), read via
  `FLUXFILES_TELEGRAM_BOT_TOKEN_FILE`. The token sits in the URL path, so **no URL or raw curl error is ever logged**. Error strings are
  scrubbed of `bot[0-9]+:[A-Za-z0-9_-]+`.
- `FLUXFILES_TELEGRAM_CHAT_ID` goes in the worker's config. Both unset means Telegram is off: the outbox still fills and still prunes.
- `FLUXFILES_TELEGRAM_THREAD_ID` (optional) is a forum-topic id. When it is set, every `sendMessage` also carries `message_thread_id`.
- `FLUXFILES_TELEGRAM_TAG` (default `FluxFiles`) is prefixed to every message as `[FluxFiles] …`.

**One bot per product (owner decision).** FluxFiles has its own bot (for example `@fluxfiles_ops_bot`). Other products
(FluxDocs, FluxGantt, …) each get their own bot and token. They never share a token. Reasons:
- **Isolation.** A token leaked from one product's server affects only that product's bot. Rotating it (BotFather
  `/revoke`) touches one server.
- **Commands stay simple.** Each bot has exactly one `getUpdates` consumer, so every product may enable its own read-only
  commands without fighting over update offsets. The worker never calls `getUpdates` while `COMMANDS` is 0, and a test covers this.
- **Notifications per bot.** The owner can mute one product's bot without touching the others.

**Where messages go.** This can be a private chat with the bot, or a private ops supergroup with Topics enabled, one topic per
product, with each product's bot added only to that group and posting to its own `THREAD_ID`. In a group, every bot sees
only commands addressed to it (`/stats@fluxfiles_ops_bot`), with privacy mode on.

**Hardening.**
- After adding the bot to its chat or group, set BotFather `/setjoingroups` to Disable and keep `/setprivacy` enabled.
- In a group, commands are accepted only when `message.from.id` equals `FLUXFILES_TELEGRAM_ADMIN_USER_ID`. In a private chat,
  the chat id check below is enough.
- The System screen shows the token's fingerprint (sha256 prefix), never the token.

**Flow:**

1. **Enqueue** happens in whichever process handles the cause, using one `LicenseStore::enqueueAlert(kind, severity, dedupKey, text, windowSec)`:
   - If a row with the same `dedup_key` exists within `windowSec`, do `suppressed = suppressed + 1` and insert nothing.
   - Otherwise insert, with `next_at = now`.
   - `text` is built from `client/Mask.php` helpers: email → `a***@domain`, ids truncated in the middle, **never** a key, **never** a
     `checkout_id` (it is the `/claim` handle), never a full email. Amounts are allowed.
   - Enqueuing is a single INSERT or UPDATE. **It never contacts Telegram, so the Polar webhook pool can't be slowed down by it.**
2. **Send** happens in the ops worker only. Each run calls `outbox/claim` (≤ 20 rows, 120 s lease) and sends at most 1 message per 1.1 s.
   - Budget: at most 20 per run and 60 per hour. Beyond that, the remaining rows are collapsed into one digest ("… và 37 cảnh báo khác — mở trang quản trị").
   - On HTTP 429 the worker honours `parameters.retry_after`. Other errors back off exponentially (1, 2, 4 … 60 min).
   - After 10 attempts the row is dropped, and `last_error` is shown on System.
   - A row whose `suppressed > 0` is suffixed "(+N lần tương tự)" (+N similar).
3. **Defence in depth:** the worker refuses to send any text matching the licence-key regex or a full email address regex,
   and drops it with `last_error='masking'`. A test covers this.

| Alert kind | Raised by | Severity | Dedup key / window |
|---|---|---|---|
| `order.new` | public pool, after a successful issue | info | `order:<id>` / forever |
| `order.refund` | public pool, `order.refunded` | warn | `refund:<id>` / forever |
| `webhook.failure` | public pool (unmapped, unmatched renewal, error) | crit | `hook:<outcome>:<product\|type>` / 1 h |
| `webhook.bad_signature` | public pool, from the 10th in an hour | warn | `badsig:<hour>` / 1 h |
| `mail.failed` | mailer `onResult` with ok=0 | warn | `mailfail:<transport>` / 1 h |
| `mail.bounced` | `/webhook/resend` | warn | `bounce:<provider_id>` / forever (plus the budget collapse) |
| `discount.cap` | public pool (§7.3) | info | `cap:<code>:<80\|100>` / forever |
| `backup.stale` | worker, when age > 26 h (via `/admin/ops/backup-status`) | crit | `backup` / 24 h |
| `tls.expiring` | worker, when < 14 days | warn (crit < 3 days) | `tls:<host>` / 24 h |
| `disk.low` | worker, free < 10 % (warn) or < 5 % (crit) | warn/crit | `disk:<path>:<level>` / 6 h |
| `health.down` / `health.up` | worker, after 2 consecutive failures / on recovery | crit / info | `down:<target>` / 1 h |
| `ops.item` (P2) | renewal unmatched, cascade, refund with the subscription still running | warn | `item:<id>` / forever |

Example (vi): `[ĐƠN MỚI] pro-monthly · a***@acme.example · 19.00 USD (thuần) · ord_…9a3f`.

**Read-only bot commands (optional, `FLUXFILES_TELEGRAM_COMMANDS=1`):**
- At the end of each run, the worker does `getUpdates?offset=<saved+1>&timeout=<remaining run budget, ≤ 25 s>&allowed_updates=["message"]` [14].
  The offset is kept in `/var/lib/fluxfiles-license-ops/state.json`.
- It accepts a message **only** if `message.chat.id == FLUXFILES_TELEGRAM_CHAT_ID`, and either `chat.type == "private"`,
  or the chat is the configured supergroup and `message.from.id == FLUXFILES_TELEGRAM_ADMIN_USER_ID`. When `THREAD_ID` is set,
  the message must also be in that topic. Everything else is consumed (the offset advances) and ignored without a reply.
  In a group, the command must be addressed to this bot (`/stats` in a private chat, `/stats@<bot>` in a group).
- **Commands:** `/stats` (this month: revenue, MRR, active installs) and `/today` (orders, refunds, failures and alerts today). The text comes from
  `GET /admin/ops/summary`, and `/help` lists the commands.
- **No command can act** (no revoke, no issue, no reveal), and none is ever added. The ops token cannot do those things anyway.
- Telegram keeps unfetched updates for only 24 h [14], which is fine for these commands.

### 8.2 C1 Weekly report (P2)

- **Trigger:** at the first worker run on or after Monday 08:00 `Asia/Ho_Chi_Minh`, the worker calls `POST /admin/ops/report/weekly {week}`.
  The admin-api pool skips it when `ops_state['weekly_report']` already equals that week.
- **E-mail:** sent to `FLUXFILES_OPS_EMAIL`, using the HTML+text mail template from feat/license-mail-html, and logged as `mail_log kind=report`.
- **Contents:** net revenue for the week and the month to date per currency, MRR and its change, new customers, refunds,
  FOUNDING40 progress, active versus never-installed installs, open "needs attention" items (masked), webhook and mail failures, backup and TLS status.
- A 5-line digest goes to Telegram.

### 8.3 C3 Health (alerts MVP, screen P2)

The worker runs health checks every 5 minutes (tracked in its state file) and posts the results to `/admin/ops/health`:

- **Uptime:** HTTPS GET with a 5 s timeout and no redirects, against each `FLUXFILES_OPS_HEALTH_TARGETS` entry (`[{name,url,expect_status}]`).
  Defaults: licence `/health`, update `/health`, demo `/`, landing `/`.
- **TLS:** `stream_socket_client('ssl://host:443')` with SNI and `capture_peer_cert`, then `openssl_x509_parse()['validTo_time_t']`.
- **Disk:** `disk_free_space()` and `disk_total_space()` for each path in `FLUXFILES_OPS_DISK_PATHS` (default `/`; use mount points the worker user can traverse).
- **Backup age:** from `/admin/ops/backup-status`.
- **Heartbeat:** the worker's own last run time is stored in `ops_state`. The dashboard shows "worker im lặng" (worker silent) if it is more than 5 minutes old.

### 8.4 C4 Notes and CSV export (P2)

- **Notes:** per-customer notes (`customer_notes`) on Customers and on licence detail pages. Audited.
- **Export:** `GET /export/orders.csv?from=&to=` (step-up, audit `export.orders`) streams UTF-8 with a BOM, so Excel shows Vietnamese.
  - **Columns:** `paid_at_local, paid_at_utc, order_id, status, plan, product_id, billing_reason, subscription_id, customer_email, customer_name, currency, subtotal, discount, discount_code, net, tax, total, refunded, refunded_tax, refunded_at_local, jti`.
  - Amounts are in major units.
  - **Formula-injection guard:** any cell starting with `= + - @ TAB CR` gets a leading `'`.
  - **No key columns, ever.** `Cache-Control: no-store`, `Content-Disposition: attachment`.

### 8.5 C5 Security notice (P3)

1. Pick an advisory (§7.5).
2. The affected installs are the jti list from A5, plus optionally "không rõ" (unknown version).
3. Map them to licence emails, deduplicated per email, active and non-test only.
4. **Preview:** recipient count, 5 masked samples and the rendered subject and body, with `{edition}`, `{version}`, `{fixed_in}` and `{url}` placeholders.
5. Step-up, then type the advisory id to confirm. This writes `notices` plus one `ops_outbox` row per recipient
   (`channel=email`, `payload={notice_id,jti}`), and audits `notice.send` with the count.
6. The worker calls `POST /admin/ops/mail-jobs {limit:10}` each run until the queue is drained. The admin-api pool, which holds the mail credentials, sends,
   logs `mail_log kind=notice` and updates the `notices` counters.

The notice is transactional (a security fix for software they licensed). The body says "reply to stop receiving these".

### 8.6 Log collector (deploy, root, for A6 and B3)

`fluxfiles-ops-collect` (root cron, daily at 00:10 local) reads **yesterday's** logs:

- the update vhost's access log: counts of `/dl/m/<zip>` and `/dl/free/<zip>` with status 200 or 206, per zip;
- the demo vhost's access log: sandbox-mint requests and 429s;
- the demo cleaner's summary line.

It writes **one counts-only JSON** per day to `/var/lib/fluxfiles-license-ops/inbox/<day>.json`
(0640 root:fluxfiles-license-ops):

```json
{"day":"2026-11-07","downloads":[{"module":"share","version":"1.1.9","n":4}],
 "demo":{"sandboxes_created":12,"sandbox_limit_hits":3,"page_limit_hits":0,"sandboxes_live":9,"uploads_bytes":19448}}
```

The worker validates the file, POSTs it to `/admin/ops/ingest` and deletes it. **No licence, UI or ops user ever reads a raw log**, and the collector writes
no IPs. Its exact log patterns depend on the private deploy's log formats (§17.6).

---

## 9. UX and visual direction

- **Look:** dense and operator-focused. 14 px base, 32 px rows, `tabular-nums`, monospace ids, system font stack
  (Vietnamese diacritics, no webfont). Neutral greys with one accent. Red only for destructive actions and failures.
- **Dark mode:** CSS variables, `prefers-color-scheme` by default, and a light/dark/auto override rendered server-side as `<html data-theme>`, so dark mode never flashes light first.
  WCAG AA contrast, with chart series colours checked in both themes.
- **Language:** `admin/lang/vi.php` and `en.php` hold flat arrays. `t()` falls back to en and then to the key. vi is the default, with a per-session toggle.
  Status values are translated, with the raw value in `title`. Plan ids, event types and error codes are not translated.
- **Keyboard:** `/` search, `j`/`k` rows, `Enter` open, `x` select, `g d|l|s|w|a` sections, `?` help, `Esc` close. Rows work as plain links without JS.
- **Confirmations:** native `<dialog>`. Revoke shows the cascade preview and needs the typed jti suffix. Step-up uses a code field in the same dialog.
  Without JS, a server-rendered confirm page does the same job.
- **Toasts:** server flash messages appear in an `aria-live="polite"` region and auto-hide after 5 s. Undo exists only for the test flag.
- **Mobile:** stacked cards, a sticky action bar and 44 px targets. Phone use means quick checks, and Telegram handles urgency.

---

## 10. Polar integration

### 10.1 Deep links (MVP)

URL templates on the UI side: `FLUXFILES_ADMIN_POLAR_{ORDER,SUBSCRIPTION,CUSTOMER}_URL` with `{org}` and `{id}`, plus
`FLUXFILES_ADMIN_POLAR_ORG`. Proposed defaults: `https://polar.sh/dashboard/{org}/sales/{id}`, `…/sales/subscriptions/{id}`
and `…/customers/{id}`. Polar documents only the `/dashboard/{org_slug}/…` prefix [6], so **confirm the defaults against the live dashboard**.
Links use `rel="noreferrer noopener" target="_blank"`.

### 10.2 `subscription.*` webhooks (P2)

Subscribe the endpoint to `subscription.canceled`, `subscription.uncanceled`, `subscription.revoked` and
`subscription.past_due`. Semantics [4][7]: `canceled` usually means `cancel_at_period_end` (the period stays paid), and
`revoked` means billing stops and benefits end. An immediate cancel sends `updated`, `canceled` and `revoked` together.

| Event | Effect | Licence status |
|---|---|---|
| `canceled` | Upsert `subscriptions`. Suppress the "renews soon" reminders for its rows. Resolve `refund_sub_active` | none |
| `uncanceled` | Clear the cancel and re-enable reminders | none |
| `revoked` | Mark revoked | **Owner rule:** if the newest active row has `expires − now > 48 h`, call `/revoke {subscription_id}` semantics (`setStatusForSubscription(…,'revoked')`, actor `polar:<id>`). Otherwise none: the key lapses on its own |
| `past_due` | Mark past_due and show it on the dashboard | none |

Everything ACKs 200, including unknown subscriptions (outcome `ignored`). **P1 loop:** an `order.refunded` for an order whose subscription is
not cancelled creates `ops_item refund_sub_active` and a Telegram alert with [Hủy trong Polar ↗]. The `subscription.canceled` that follows resolves it automatically.
The owner keeps this item, because it is unknown whether Polar's refund dialog cancels the subscription too.

### 10.3 Polar API token: not stored

- **Against storing it:** `subscriptions:write` [8] lets whoever holds it cancel every customer's subscription, and order reads expose PII.
  Storing it would add a long-lived secret on the box that holds k1.
- **What to do instead:** use deep links and webhooks. Backfills (`backfill-orders.php`, `backfill-subscription-ids.php`) take a read token at run time only.
- **When to reconsider:** in Phase 3, only if refunds exceed about 10 a month. The token would be scoped, button-only, require step-up, and be audited.

---

## 11. Backups

- `fluxfiles-license-backup` also writes `/var/lib/fluxfiles-license/backup-status.json`, atomically, 0640 root:fluxfiles-license:
  `{"last_ok_at":…, "file":"…", "bytes":…, "integrity":"ok", "kept":30, "oldest_at":…, "last_error":null}`.
- **Back up now** (P2, step-up): the admin-api pool runs `VACUUM INTO /var/lib/fluxfiles-license/backup-stage/manual-<ts>.sqlite` and `integrity_check`.
  A new root cron line, `*/10 * * * * fluxfiles-license-backup --sweep`, moves staged files into place and refreshes the status file.
- New tables are included automatically. Backups now also hold up to 7 days of failed-webhook bodies and the order amounts.
  The protection is the same 0600 root mode.
- Take a backup before deploying the migration.

---

## 12. Package layout

```
services/license-server/
  server.php             MOD  lazy signer (F3); token scopes + actor; /issue deliver/is_test/note; /revoke cascade list;
                              /redeliver limit; handlePolarEvent() + event/order recording + alerts; /update-status telemetry
                              (respond, finish, record); POST /webhook/resend; dispatch /admin/* -> AdminApi
  AdminApi.php           NEW  /admin/* and /admin/ops/* routes (§4)
  Stats.php              NEW  statistics SQL (§7), pure functions over a PDO from LicenseStore
  VersionRange.php       NEW  constraint matcher (§7.5)
  LicenseStore.php       MOD  migrations (§5), setActor(), history writes, search/stats/outbox/ingest/prune helpers,
                              enqueueAlert(), recordInstallPing(), upsertOrder(), cascade preview
  PolarWebhook.php       MOD  orderFacts(); subscriptionEvent() (P2); verify() reused for Resend (header map)
  LicenseMailer.php      MOD  ?callable $onResult; kinds report/notice/test; provider id from Resend
  send-renewal-reminders.php  MOD  log to mail_log; skip cancelled subscriptions (P2)
  backfill-orders.php    NEW  one-off, mirrors backfill-subscription-ids.php
  client/                NEW  k1-free shared code: ApiClient.php, Mask.php   (may be required by server-side code too)
  admin/                 NEW  UI docroot + code (requires only client/)
    public/index.php  public/assets/{admin.css,admin.js}
    src/{App,Router,Auth,Totp,Csrf,Session,View,I18n,Svg}.php
    templates/*.php  lang/{vi,en}.php  cli/admin-user.php
  ops/                   NEW  ops worker (requires only client/)
    ops-worker.php  src/{Telegram,Health,Counters,Inbox,Worker}.php
  tests/                 NEW  test-admin-ui-unit.php, test-admin-api-http.php, test-admin-ui-http.php,
                              test-stats-http.php, test-ops-worker-http.php   (+ fixtures/polar/*.json, fixtures/resend/*.json)
  README.md, .env.example MOD admin UI, statistics, ops worker, new env vars
docs/update-server.example.php   MOD  send the optional telemetry fields (§7.4) in its /update-status call
```

---

## 13. Configuration (licence-server env, not JWT claims)

**Licence pools** (via `config.env`, with secrets via `_FILE`):

| Var | Default | Notes |
|---|---|---|
| `FLUXFILES_LICENSE_UI_TOKEN` (`_FILE`) | unset (UI disabled) | admin-equivalent, actor-attributed |
| `FLUXFILES_LICENSE_OPS_TOKEN` (`_FILE`) | unset (worker disabled) | `/admin/ops/*` only |
| `FLUXFILES_LICENSE_NET_PEPPER_FILE` | unset (`net=''`) | 32 random bytes; HMAC key for network prefixes |
| `FLUXFILES_RESEND_WEBHOOK_SECRET` (`_FILE`) | unset (`/webhook/resend` answers 401) | Svix `whsec_…` |
| `FLUXFILES_LICENSE_K1_PUB` | unset | core's k1 public key (public value), for the System check and F3 |
| `FLUXFILES_LICENSE_BACKUP_STATUS` | `/var/lib/fluxfiles-license/backup-status.json` | |
| `FLUXFILES_ADMIN_TRACKED_DISCOUNTS` | unset | `CODE:CAP[,CODE:CAP]`, e.g. `FOUNDING40:50` |
| `FLUXFILES_ADMIN_TZ` | `Asia/Ho_Chi_Minh` | month boundaries and the weekly report |

**UI pool:**

| Var | Default |
|---|---|
| `FLUXFILES_ADMIN_API` | `http://127.0.0.1:9471` (must be http loopback, checked at boot) |
| `FLUXFILES_ADMIN_UI_TOKEN` (`_FILE`) | — |
| `FLUXFILES_ADMIN_DB` | `/var/lib/fluxfiles-license-admin/admin.sqlite` |
| `FLUXFILES_ADMIN_HOSTS` | `localhost,127.0.0.1` |
| `FLUXFILES_ADMIN_LANG` / `FLUXFILES_ADMIN_TZ` | `vi` / `Asia/Ho_Chi_Minh` |
| `FLUXFILES_ADMIN_SESSION_IDLE` / `_ABSOLUTE` | `1800` / `43200` s |
| `FLUXFILES_ADMIN_POLAR_ORG`, `FLUXFILES_ADMIN_POLAR_{ORDER,SUBSCRIPTION,CUSTOMER}_URL` | §10.1 |

**Ops worker:**

| Var | Default |
|---|---|
| `FLUXFILES_ADMIN_API`, `FLUXFILES_LICENSE_OPS_TOKEN_FILE` | as above |
| `FLUXFILES_TELEGRAM_BOT_TOKEN_FILE` | unset (Telegram off) |
| `FLUXFILES_TELEGRAM_CHAT_ID` | unset |
| `FLUXFILES_TELEGRAM_COMMANDS` | `0` |
| `FLUXFILES_TELEGRAM_THREAD_ID` | unset (no forum topic) |
| `FLUXFILES_TELEGRAM_TAG` | `FluxFiles` |
| `FLUXFILES_TELEGRAM_ADMIN_USER_ID` | unset (needed only for commands in a group) |
| `FLUXFILES_OPS_HEALTH_TARGETS` | JSON list; defaults to the four hosts from §8.3 (configured at deploy) |
| `FLUXFILES_OPS_DISK_PATHS` | `/` |
| `FLUXFILES_OPS_PUBLIC_COUNTERS` | JSON `{"wporg":[slug],"github":["owner/repo"],"packagist":["v/p"],"npm":["@s/p"]}` |
| `FLUXFILES_OPS_STATE_DIR` | `/var/lib/fluxfiles-license-ops` (state.json, inbox/) |

---

## 14. Test plan

Tests are self-booting, like `test-pro-download-http.php` and `test-refresh-http.php`: `php -S` on ephemeral ports, a throwaway Ed25519 key,
and a temp DB dir.

**`test-admin-ui-unit.php`**
- TOTP against the RFC 6238 Appendix B SHA-1 vectors, the ±1 window and replay refusal.
- `h()` escaping table; Host allow-list; CSRF compare.
- `VersionRange` truth table, including `||`, pre-releases and garbage input.
- `Mask` (email, ids; the key and email regexes are refused).
- Currency formatting.
- `Svg` output has no `style=` and escapes labels.
- **i18n parity:** identical key sets in `vi.php` and `en.php`, and every `t('…')` in templates exists in both.
- **No-inline guard:** scan `admin/templates/**` and `admin/public/**` for `<script>` without `src`, `<style`, `style=`, `on\w+=`, `javascript:` and `innerHTML`.
- **k1 boundary guard:** nothing under `admin/`, `ops/` or `client/` references `LicenseSigner`, `LicenseStore`, `LicenseIssuer`,
  `PRIVATE_KEY`, `licenses.sqlite` or `require … '../`.

**`test-admin-api-http.php`** (servers: public, status, admin-api)
- **Token scopes:** UI token on admin routes works; on `/update-status` it gets 401. The ops token is refused on everything outside `/admin/ops/*`
  (`forbidden_scope`), including reveal, issue, revoke and licences. The status token is refused on admin routes. `X-FluxFiles-Actor` sent with the admin token is ignored.
- **Issue and redeliver:** `/issue deliver=manual` sets `mailed_at`. `deliver=mail` writes `mail_log`. `is_test` and `note` persist.
  `/redeliver` never mails manual rows (F1 regression).
- **No keys in reads:** no `/admin/*` read response matches the key pattern. Reveal is audited. `lookup-key` is POST-only.
- **Cascade:** the preview equals the actual `/revoke` result. `license_history` has the right actors.
- **Archive:** active and non-test rows are refused. Archived rows still answer `/update-status`.
- **Webhook recording:** signed `order.paid` → outcome `issued`. The same `webhook-id` gives `attempts=2`. An unmapped product gives 503 and a stored body.
  A bad signature stores no body and aggregates. A failure to record doesn't change the status code. Bodies are pruned after 7 days.
- **Orders:** `order.paid` fixtures (one-time, subscription create, cycle, FOUNDING40 discount) produce `orders` rows with exact amounts.
  `order.refunded`, both partial and full, updates them. An unmapped product still produces an orders row. `backfill-orders.php` dry-run writes nothing.
  It runs against a fake Polar on loopback (`--api-base`) with `--apply` and is idempotent.
- **Telemetry:**
  - `/update-status` with the full body records one `install_pings` row; repeated calls increment `n`; it sets `first_seen_at` and `last_seen_at` once per day.
  - The response body is **byte-identical** with and without telemetry.
  - Invalid optional fields are dropped with a 200 response.
  - The `net` stored is the HMAC, never the prefix: grep the DB file for the prefix string.
  - No pepper means `net=''`.
  - With a concurrent writer holding a lock, `/update-status` still answers within 1 s, and the ping is dropped.
- **Resend:** a Svix-signed `email.bounced` updates the `mail_log` row by `provider_id` and enqueues `mail.bounced`. A bad signature gets 401.
- **Outbox:**
  - Dedup: the same key within the window increments `suppressed`.
  - Claim leases rows, and a second claim within the lease gets nothing.
  - `ack` with `retry_after` sets `next_at`.
  - Rows are dropped after 10 attempts.
- **Subscriptions (P2) with captured sandbox fixtures:** canceled suppresses reminders. A revoke with more than 48 h left revokes the subscription's rows; one with 48 h or less leaves them.
  Refund then cancel opens the ops item and then resolves it automatically.
- **Pool isolation:** with admin-api occupied by two slow `/redeliver` calls (a stalling fake SMTP), `/update-status` on the status server answers in under 1 s,
  and `/claim` → update server → status still returns a link.
- All existing tests pass unchanged.

**`test-admin-ui-http.php`** (servers: admin-api, plus a UI server started **without** any k1 or DB env)
- **Auth:** an unauthenticated request redirects to `/login`. Wrong password; wrong code; replayed code; lockout after 5; idle and absolute expiry
  (the clock seam is honoured only under `cli-server` together with a test env flag); logout; `logout-all`.
- **CSRF:** missing token → 403; foreign `Origin` → 403; missing `Origin` on POST → 403; `Sec-Fetch-Site: cross-site` → 403; GET on an action → 405.
- **Host:** `Host: evil.example:8471` → `bad_host`.
- **Headers:** every response class (login, page, JSON, 404, 500, asset, CSV) carries the exact CSP and `no-store`.
- **XSS:** payloads in the customer name, email local part, note, webhook error, product id and **reported versions or modules from install pings**
  (`"><img src=x onerror=alert(1)>`, `</script><script>`, `javascript:`) render only as escaped text, in tables **and** in SVG `<title>`/`<text>`.
- **No keys in output:** no page or CSV matches the key pattern, and no `href` or `action` contains a key.
- **Step-up:** reveal, export and issue without a fresh code → `stepup_required`.
- **Issue form:** submitting without a delivery choice is rejected.
- **CSV:** cells starting with `=` are prefixed with `'`. The BOM is present. No key columns.
- **Stats screens:** the numbers in the table fallback equal the API values from seeded fixtures (§7 definitions, including multiple currencies kept separate).

**`test-ops-worker-http.php`** (servers: admin-api, a fake Telegram on loopback, and fake health targets; the worker runs as a CLI process with
`FLUXFILES_TELEGRAM_API_BASE` pointing at the fake. That override is honoured only for an http-loopback URL, so the token can't be sent anywhere else.)
- Enqueued alerts are delivered as plain text with no `parse_mode`. The masking guard drops texts containing a key or a full email.
- The budget collapses 50 alerts into at most 20 messages plus a digest. Fake 429 `retry_after` responses are honoured.
- `getUpdates`: a message from another chat is ignored with no reply. `/stats` from the configured chat gets a reply. An `/revoke` text does nothing.
  The offset persists.
- **Health:** a down target alerts after 2 failures, then recovers once. TLS days come from a self-signed loopback cert. A backup older than 26 h alerts.
- **Inbox:** a valid file is ingested and deleted. A malformed file is quarantined (renamed `.bad`) and not ingested.
- The weekly report is idempotent per week.
- **Secrets in output:** the token never appears in any worker output or in `last_error`. Make the fake return errors that echo the URL.

**Deploy smoke** (listed for the deploy owner):
- The UI and ops users get EACCES on `license-signing.key` and on `licenses.sqlite`.
- `:9472` answers on loopback only.
- `/update-status` goes to the internal socket and `/admin/*` to the admin-api socket.
- The UI issue writes an audit row with `ui:<name>`.
- The test Telegram message arrives.

---

## 15. Phasing

| Phase | Scope |
|---|---|
| **MVP (about 3 days)** | **Access and auth:** loopback UI vhost, UI user and pool, admin-api pool split, four-token model, password and TOTP, step-up, CSRF, CSP, CLI `admin-user`. **Data:** §5.1 columns, `license_history`, `webhook_events`, `mail_log`, `admin_audit`, `orders`, `install_pings`, `ops_outbox`, `ops_state`, `health_checks`. **Screens:** Login, Dashboard, Licences (list, detail, all actions, subscription revoke, manual issue, reveal, lookup), Webhook events (read and resolve), Audit, System (basic), Statistics: **Revenue (A1), FOUNDING40 card (A3), Installs (A4), Mail (A7)**. **Ops:** Resend delivery webhook; update-server telemetry; **Telegram (C2)** with the worker, timer, outbox, alert catalogue, and backup/TLS/disk/health alerts. F3 lazy signer; backup-status JSON; `backfill-orders.php` |
| **Phase 2** | Subscription webhooks plus the `subscriptions` and `ops_items` tables (closes P1 and P4); replay; Mail log, Customers with **notes and CSV (C4)**, Activity; **A2** customers and retention, **A5** versions and advisories, **A6** downloads, **B1** link, **B2** public counters, **B3** demo; **C1** weekly report; **C3** health screen; System extras (k1 fingerprint, back up now, landing and update checks); reminders that respect cancellations; optional Playwright smoke |
| **Phase 3** | **A8** anomalies, **C5** security notices, Cloudflare Access mode, an optional scoped Polar token, a `viewer` role |

---

## 16. Open questions (remaining)

1. **Polar dashboard URL paths** for orders, subscriptions and customers. The templates are configurable, but the defaults need confirming.
2. **Polar refund dialog:** whether it also cancels the subscription is unknown. The automatic item stays either way.
3. **FOUNDING40:** do refunded redemptions free a slot in Polar's redemption limit? This decides whether the card shows "paid" or "paid + refunded" against the cap.
4. **Revenue month boundary:** `Asia/Ho_Chi_Minh` (proposed) or UTC for accounting? The CSV carries both either way.
5. **Telegram quiet hours:** should `info` alerts (new orders) be held overnight, for example 23:00–07:00, and sent as one morning digest? The proposal is no for MVP.
6. **Public counter subjects:** which WordPress.org slug, GitHub repo, Packagist packages and npm packages to track.
7. **Cloudflare Web Analytics** on the landing site: confirm the cookie behaviour before writing "cookieless" anywhere [12].

---

## 17. Implementation handoff (for a coding agent)

### 17.1 Base branch

Branch **from master after** `feat/update-server-pro-entitlement`, `feat/license-mail-html` and
`feat/license-refresh-server` have merged. The last of these stacks on the other two. The suggested branch is `feat/license-admin-ui`.

Split the work into PRs that each pass the tests:
1. data and admin API;
2. pools and UI;
3. statistics;
4. ops worker and Telegram.

Things already on those branches that this spec builds on rather than re-adding:
- `licenses.subscription_id` and `billing_reason`;
- `POST /refresh`;
- `/revoke {subscription_id}` and `setStatusForSubscription()`;
- `undelivered()` excluding manual rows (F1);
- `LicenseMailer::deliver()` with HTML;
- `PolarWebhook::cleanId()`.

### 17.2 File layout

As in §12. Rules:

- `admin/`, `ops/` and `client/` are **self-contained**. They may `require` only files inside themselves and `client/`.
- Server-side code may `require` `client/` (for example `Mask.php`).
- Every new class uses namespace `FluxFiles\LicenseServer` (server side), `FluxFiles\LicenseServer\Admin`, `…\Ops` or `…\Client`.
- The deploy's `build-front.sh` adds one namespace line to `server.php`. Keep `server.php` global-namespace code with no
  top-level `function` that would clash. Put new logic in classes.

### 17.3 Implementation order

1. **Migrations** (§5): `LicenseStore::migrate()` behind `user_version`. Include `setActor()` and the history writes in existing state changes.
2. **Recording:** the `handlePolarEvent()` refactor with `webhook_events`, `orders` and alert enqueue; the `/update-status` telemetry; `/webhook/resend`; the mailer hook.
3. **Admin API routes** (§4) in `AdminApi.php`, with token scopes. Also the F3 lazy signer.
4. **Pools and nginx:** this is deploy-side (§17.6). For local tests, run separate `php -S` servers in place of pools.
5. **UI:** auth, CSRF and headers first, then the licences screens, then the dashboard.
6. **Statistics:** `Stats.php`, `Svg.php`, the screens, and `backfill-orders.php`.
7. **Telegram and ops worker:** outbox API, worker, health, inbox.
8. **Tests** (§14) alongside each step. They are not a final step: each PR includes its tests.
9. **Docs:** update the README and `.env.example`.

### 17.4 Hard constraints

- **Plain PHP ≥ 8.1** (production runs 8.4). No composer dependencies, no framework, no build step, no CDN, and no inline JS or CSS (§2.7).
  Required extensions only: `pdo_sqlite`, `sodium`, `curl`, `mbstring`, `openssl`, `json`, `zlib`. Do not use PHP 8.2+ syntax
  (`readonly class`, DNF types, standalone `true`/`null`/`false` types).
- **k1 boundary** (§2.4): the UI and the worker never get the key env, the DB path, or a `require` of the signer, store or issuer.
  Issuing goes only through `POST /issue` → `LicenseIssuer`.
- **Never log, display, enqueue or export a licence key**, a `checkout_id` in alerts, a full IP, a token, or the Telegram URL.
  Mask emails in logs and alerts.
- **The Polar webhook path must stay fast and must never return 5xx because of the new code:** recording, the orders upsert and alert enqueue
  are all wrapped in try/catch. Nothing on the request path contacts Telegram.
- **`/update-status` must stay cheap:** respond first, record afterwards with a short busy timeout, and drop pings on contention.
- **The deploy is a plain directory copy:** relative `require_once __DIR__ …` only, no generated files, and no writes inside the code tree.
- **Follow the existing services/license-server style:** `declare(strict_types=1)`, `final` classes, prepared statements only,
  guarded migrations, comments explaining *why*, and the test harness of `tests/test-*-http.php`.

### 17.5 Test commands

```bash
# all licence-server tests (from the repo root)
for f in services/license-server/tests/*.php; do /usr/local/opt/php@8.2/bin/php "$f" || echo "FAIL $f"; done
# one file
/usr/local/opt/php@8.2/bin/php services/license-server/tests/test-admin-api-http.php
```

**How the self-boot HTTP tests work** (copy `test-pro-download-http.php` / `test-refresh-http.php`):
- Each file creates a temp dir and a throwaway Ed25519 keypair (`sodium_crypto_sign_keypair()`; it skips when sodium is missing).
- It picks free ports by binding `tcp://127.0.0.1:0`.
- It starts `PHP_BINARY -S 127.0.0.1:<port> server.php` with `proc_open`. The env array passes `FLUXFILES_LICENSE_PRIVATE_KEY`, `FLUXFILES_LICENSE_DB`, the tokens
  and `PHP_CLI_SERVER_WORKERS`, so separate servers stand in for the public, status and admin-api pools.
- It waits for the socket and talks to it with curl.
- It uses the local `test()`/`eq()`/`ok()` helpers and cleans up in `register_shutdown_function`.
- UI tests start a second server with `admin/public/index.php` as the router and **no** key or DB env.
- Fake Polar, Telegram, health and update servers are tiny PHP scripts written into the temp dir and served by `php -S`.
- No test reaches the internet.
- Run `php -l` on every new file with PHP 8.1 if it is available.

### 17.6 What NOT to touch, and deploy changes to list in the PR

**Do not modify:**
- `packages/*` (core, adapters, SDKs);
- any paid module (`packages/<module>/`, gitignored);
- `docs/reference/CONFIG.md` (there are no new claims);
- `.env` files and any secret;
- the private deploy tree.

The only file outside `services/license-server/` that may change is `docs/update-server.example.php` (§7.4 telemetry fields).

**List these deploy changes in the PR description** for the owner to apply to the private deploy:
1. Users `fluxfiles-license-admin` and `fluxfiles-license-ops`, both nologin and in neither licence group.
2. Directories:
   - `/srv/fluxfiles-license-admin` (code, root 0755/0644);
   - `/var/lib/fluxfiles-license-admin` (0700 UI user);
   - `/etc/fluxfiles-license-admin` (0750 root:UI group);
   - `/var/lib/fluxfiles-license-ops/{,inbox}` (0700 / 0750 root:ops);
   - `/etc/fluxfiles-license-ops` (0750 root:ops).
   The ops worker code can live in the licence code tree (`/srv/fluxfiles-license/ops`, read-only).
3. Secrets: generate `ui.token`, `ops.token` and `install-net.pepper`, each with a licence-user copy and a client copy. Install the Resend webhook secret and the Telegram token.
   Make the uniqueness check across all four tokens fail the install.
4. `public/bootstrap.php` allow-lists:
   - secret files: `FLUXFILES_LICENSE_UI_TOKEN`, `FLUXFILES_LICENSE_OPS_TOKEN`, `FLUXFILES_RESEND_WEBHOOK_SECRET`;
   - config keys: `FLUXFILES_LICENSE_NET_PEPPER_FILE`, `FLUXFILES_LICENSE_K1_PUB`, `FLUXFILES_LICENSE_BACKUP_STATUS`, `FLUXFILES_ADMIN_TRACKED_DISCOUNTS`, `FLUXFILES_ADMIN_TZ`.
5. Pools:
   - narrow `fluxfiles-license-internal` to the status role;
   - add `fluxfiles-license-admin-api` (licence user, key env);
   - add `fluxfiles-license-admin` (UI user, `max_children=2`, `open_basedir` limited to its three dirs, no key or DB env, the same `disable_functions`, `allow_url_fopen=off`, `session.save_path=/nonexistent`).
   - Status pool: keep the key env until F3 lands, then remove it.
6. nginx:
   - loopback `:9471`: route `/update-status` to the internal socket, and the admin routes plus `/admin/` to the admin-api socket;
   - add the `:9472` UI vhost (§2.3; `return 444` default server; `/assets/` static with the same headers; `limit_req` on `/login`);
   - public vhost: add `location = /webhook/resend`;
   - update vhost: no change, but keep the `$uri`-only log format so the collector never sees secure-link query strings.
7. Update server: send the telemetry fields in its `/update-status` POST (§7.4) and never log `net`.
8. systemd:
   - `fluxfiles-license-ops.service`: `Type=oneshot`, `User=fluxfiles-license-ops`, `TimeoutStartSec=55`, `NoNewPrivileges`, `ProtectSystem=strict`,
     `ReadWritePaths=/var/lib/fluxfiles-license-ops`, `PrivateTmp`, `ProtectHome`, `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX`;
     `ExecStart=/usr/bin/php8.4 -d open_basedir=… /srv/fluxfiles-license/ops/ops-worker.php run`.
   - `fluxfiles-license-ops.timer` (`OnCalendar=*-*-* *:*:00`).
9. cron: the `fluxfiles-license-backup --sweep` line; `fluxfiles-ops-collect` daily at 00:10 (§8.6).
10. CLI `fluxfiles-license`:
    - `admin-user …`, `ui-token rotate`, `ops-token rotate`;
    - `tunnel` (prints the ssh config);
    - `telegram-setup` (one `getUpdates` call to print the chat id after the owner messages the bot);
    - `list` via `/admin/licences`; `backup` writes the status JSON.
11. Polar: subscribe the webhook to the `subscription.*` events (P2). Resend: create a webhook to `/webhook/resend` for the delivery events.
12. Landing repo (separate): add the Terms line from §7.4. The Cloudflare Web Analytics CSP change applies only if B1 uses the beacon.

### 17.7 Owner must provide

- **The Telegram bot token**, from @BotFather (`/newbot`), installed as the 0400 file. Then send `/start` to the bot and
  run `fluxfiles-license telegram-setup` to read the **chat_id** into the worker config.
- The **Resend webhook signing secret**, from the Resend dashboard after creating the webhook.
- A **one-off Polar read token** for `backfill-orders.php` and `backfill-subscription-ids.php`. It is passed at run time and not stored.
- The **k1 public key** value (public) for `FLUXFILES_LICENSE_K1_PUB`.
- Answers to §16: Polar URL paths, the FOUNDING40 slot rule, the counter subjects and quiet hours.
- The initial **admin user** password and TOTP enrolment, done personally on the server.

### 17.8 Done criteria

- [ ] All existing `services/license-server/tests/*.php` pass unchanged, and every new test in §14 passes.
- [ ] `grep` over `admin/ ops/ client/` finds no `LicenseSigner|LicenseStore|LicenseIssuer|PRIVATE_KEY` (the test enforces it).
- [ ] No template or asset contains inline script, `<style>` or `style=` (the test enforces it). Every response carries the §2.7 headers.
- [ ] No API read, page, CSV, alert, log line or worker output contains a licence key (tests grep for the key pattern).
- [ ] `/update-status` returns the same bytes with or without telemetry, and stays under 1 s with a locked DB.
- [ ] The Polar webhook returns the same status codes as before for every existing test case, even with recording forced to fail.
- [ ] The ops token can't reach any non-`/admin/ops/*` route. The UI token can't reach `/update-status`.
- [ ] A Telegram alert flood collapses within the budget. Bot commands are read-only and limited to the configured chat.
- [ ] Revenue, FOUNDING40, installs and mail numbers match hand-computed fixture values, with currencies kept separate.
- [ ] The UI is fully usable in vi and en and in light and dark, the keyboard shortcuts work, and the mobile layout is usable at 375 px.
- [ ] The README and `.env.example` document every env var in §13. The PR description lists every deploy change in §17.6.

---

## Sources

1. [Cloudflare Zero Trust free plan overview (third-party; confirm in the dashboard)](https://costbench.com/software/business-vpn/cloudflare-zero-trust/free-plan/)
2. [The complete guide to Cloudflare Tunnel](https://flaviocopes.com/cloudflare-tunnel/)
3. [Cloudflare docs: self-hosted public app with Access, and validating the Access token](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-public-app/)
4. [Polar: webhook events](https://polar.sh/docs/integrate/webhooks/events)
5. [Polar API: Order object (amounts, discount, `billing_reason`, `subscription_id`)](https://docs.polar.sh/api-reference/orders/get)
6. [Polar guide referencing `https://polar.sh/dashboard/${org_slug}/settings`](https://polar.sh/docs/guides/allow-multiple-subscriptions-per-customer)
7. [Hookdeck: guide to Polar webhooks](https://hookdeck.com/webhooks/platforms/guide-to-polar-webhooks-features-and-best-practices)
8. [Polar: manage subscriptions](https://polar.sh/docs/features/subscriptions/manage), and [the revoke API (`subscriptions:write`)](https://docs.polar.sh/api-reference/subscriptions/revoke)
9. [Resend: verify webhook requests (Svix)](https://resend.com/docs/webhooks/verify-webhooks-requests), and [Hookdeck: Resend webhooks](https://hookdeck.com/webhooks/platforms/guide-to-resend-webhooks-features-and-best-practices)
10. [Cloudflare Web Analytics reference notes](https://cdn.jsdelivr.net/npm/claude-code-skills@0.5.0/data/skills/cloudflare-deploy/references/web-analytics/README.md)
11. [Example: allowing the Cloudflare Web Analytics beacon through a CSP](https://github.com/dvcdsys/code-index/pull/202)
12. [ctrl.blog: technical and privacy review of Cloudflare Web Analytics](https://www.ctrl.blog/entry/review-cloudflare-analytics/)
13. [WordPress.org plugin info API fields (community OpenAPI notes)](https://gist.github.com/baruchiro/3ed57844cb1da421094539d803379e7d)
14. [Telegram Bot API (`getUpdates`, `sendMessage`)](https://core.telegram.org/bots/api)
