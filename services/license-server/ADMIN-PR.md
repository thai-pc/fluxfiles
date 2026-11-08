# Licence admin MVP

Implements the reviewed design's MVP (§15), with one isolated deployment per
production/sandbox instance (§0.3). No merge, tag, deploy or private server config
change is part of this PR.

- §2–5: role-restricted public/status/admin-api processes, four-token authorization,
  lazy signer, guarded v2 migrations, history, event and mail recording, append-only
  UI audit, dependency-free loopback client, and shared masking.
- §6/9: PHP-rendered login, dashboard, licences and every MVP action, webhook
  read/resolve, audit, basic System; password/TOTP, replay prevention, rolling
  throttles, hashed sessions, step-up, CSRF/Origin/Host checks and strict headers.
  Vietnamese/English, light/dark/auto, keyboard navigation, mobile cards and native
  dialogs. No CDN, inline JS/CSS, build step or composer dependency.
- §7.1/3/4/7: per-currency revenue/refunds and estimated MRR, tracked discount caps,
  active/never/quiet installs, send/delivery statistics, Svix Resend webhook,
  read-only dry-run order backfill, and optional update-server telemetry with
  HMAC network identifiers and unchanged status-response bytes.
- §8.1/3: leased/deduplicated Telegram outbox, bounded digest, retry_after/backoff,
  persistent budgets, worker-only network sends, optional read-only bot commands,
  health/TLS/disk/backup alerts, heartbeat and daily retention.

## Validation

Run from the repository root:

```text
for f in services/license-server/tests/*.php; do php "$f" || exit; done
```

All **12 test scripts passed**, with **219 named checks** on PHP **8.2.35**:

| Suite | Passed |
|---|---:|
| Existing licence-server unit/contract | 119 |
| Existing mail sandbox / fallback | 8 / 5 |
| Existing Pro download / refresh / renewal HTTP | 8 / 22 / 9 |
| Existing concurrent renewal checks | 6 |
| New admin API HTTP | 12 |
| New admin UI HTTP | 10 |
| New UI/security unit guards | 8 |
| New statistics/backfill HTTP | 5 |
| New ops/Telegram/health/TLS HTTP | 7 |

The original seven test files are unchanged. HTTP suites start only throwaway
loopback services with generated signing keys and fake Polar, Resend, Telegram and
health endpoints. No real account or deployed server was used. This includes the
integer-affinity regression for SQLite lease/retention comparisons, fresh outbox
retention and permanent dedup receipts, two-instance credentials, a trusted local
self-signed TLS fixture, and the sub-second status response under a writer lock.

PHP lint passed for **64 PHP files**, JavaScript syntax passed, and `git diff --check`
passed. Local Chromium QA passed login, desktop light/dark, 375 px dashboard/list
layouts, keyboard search/row navigation, reveal/clear, and a separate sandbox's
banner/title/amber dark palette with no page errors. PHP 8.1 and 8.3–8.5 were not
executed locally; source uses PHP 8.1-compatible syntax and no new dependencies.

## Ordered server changes (operator applies; repeat per instance)

1. **Back up and select the instance.** Stop its reminder/ops writers briefly and
   take a consistent SQLite backup with the existing backup CLI before any new
   request runs the v2 migration. Preserve the original code for rollback. Do not
   restore old code over the migrated schema without the reviewed rollback plan.
   Set `LIC_INSTANCE=production` or `sandbox` in the private deploy tooling; set
   `FLUXFILES_LICENSE_INSTANCE=sandbox` in every sandbox process.

   | Resource | Production | Sandbox |
   |---|---|---|
   | Licence Unix user | `fluxfiles-license` | `fluxfiles-license-sbx` |
   | New UI user | `fluxfiles-license-admin` | `fluxfiles-license-sbx-admin` |
   | New ops user | `fluxfiles-license-ops` | `fluxfiles-license-sbx-ops` |
   | Licence code | `/srv/fluxfiles-license` | `/srv/fluxfiles-license-sbx` |
   | UI code | `/srv/fluxfiles-license-admin` | `/srv/fluxfiles-license-sbx-admin` |
   | Licence state/secrets | `/var/lib/fluxfiles-license`, `/etc/fluxfiles-license` | Same paths with `-sbx` |
   | UI state/secrets | `/var/lib/fluxfiles-license-admin`, `/etc/fluxfiles-license-admin` | Same paths with `license-sbx-admin` |
   | Ops state/secrets | `/var/lib/fluxfiles-license-ops`, `/etc/fluxfiles-license-ops` | Same paths with `license-sbx-ops` |
   | API / UI | `127.0.0.1:9471` / `:9472` | `127.0.0.1:9473` / `:9474` |

2. **Create users and directories.** UI/ops users are nologin and belong to neither
   licence group. Licence state is 0700 licence-owned. UI code is root-owned
   0755 directories/0644 files; UI state is 0700 UI-owned; UI config is 0750
   root:UI-group. Ops config is 0750 root:ops; ops state is 0700 ops-owned, with
   a root:ops 0750 `inbox/` reserved for Phase 2 (no ingest in MVP). The ops code
   lives read-only under its own instance's licence code tree. The separately
   copied UI tree contains only `admin/` and `client/`, preserving their relative
   layout. Give UI/ops read access to their code and their own config only.

3. **Provision credentials.** Generate UI and ops bearer tokens (at least 32 random
   bytes) and a 32-byte raw network pepper. Install each token twice as 0400:
   licence-user copy plus its corresponding UI/ops-user copy. Retain distinct
   admin and update-status tokens. Fail installation if **any two of all eight
   tokens across both instances are equal**, or if one is empty. The pepper is
   licence-owned only; UI and ops must not receive a copy. Install the instance's
   Polar/Resend webhook secrets. Install the product's Telegram bot token as 0400
   ops-owned, configure the chat and optional topic/authorized group user ID.
   Sandbox Telegram is off unless `FLUXFILES_TELEGRAM_SANDBOX=1`; when enabled,
   only critical alerts are sent and the tag includes SANDBOX. Use one bot per
   product; this product's sandbox may share its bot/chat with production.

4. **Copy code and update the private bootstrap allow-lists.** Copy the complete
   service tree to its instance's licence tree and `admin/` + `client/` to its UI
   tree. Keep `templates/email.php`. The deploy's namespace rewrite for server.php
   remains supported. Allow secret values/files for `FLUXFILES_LICENSE_UI_TOKEN`,
   `FLUXFILES_LICENSE_OPS_TOKEN`, `FLUXFILES_RESEND_WEBHOOK_SECRET`, in addition to
   existing secrets. Allow `FLUXFILES_LICENSE_ROLE`, `FLUXFILES_LICENSE_INSTANCE`,
   `FLUXFILES_LICENSE_NET_PEPPER_FILE`, `FLUXFILES_LICENSE_K1_PUB`,
   `FLUXFILES_LICENSE_BACKUP_STATUS`, `FLUXFILES_ADMIN_TRACKED_DISCOUNTS`,
   `FLUXFILES_ADMIN_TZ`. Pass existing `FLUXFILES_OPS_EMAIL` and
   `FLUXFILES_RENEWAL_URL` to mail-sending pools. Bootstrap UI/ops from their own
   config allow-lists in README/.env.example; neither receives the signing-key
   env/path or licence DB env/path. Never enable the test clock or API-base
   overrides in production.

5. **Create and narrow FPM pools.** Keep the public pool at the licence user with
   `FLUXFILES_LICENSE_ROLE=public`. Narrow the internal pool to `status`, remove
   its signing-key env, and keep its DB/status token/pepper. Add a licence-user
   `admin-api` pool with signing-key access (lazy, for issue), DB and appropriate
   tokens, and a UI-user pool serving `admin/public/index.php`, `max_children=2`.
   UI `open_basedir` covers only its code, config and state dirs; neither licence
   dir is allowed. Retain the deploy's disable_functions/allow_url_fopen=off and
   `session.save_path=/nonexistent`; sessions use SQLite. Use distinct sockets
   with `-sbx` names for sandbox. Size public/status/admin-api independently so
   slow mail cannot occupy the status or webhook workers.

6. **Wire loopback nginx, never a public admin vhost.** On API :9471/:9473 route
   exact `/update-status` to the status socket, and `/issue`, `/licenses`, `/revoke`,
   `/redeliver`, `/admin/*` to the admin-api socket. On UI :9472/:9474 bind
   **127.0.0.1 only**, provide a default server returning 444, allow only localhost
   and 127.0.0.1 Hosts, serve only `admin/public`, and route pages to its UI pool.
   Add a per-instance login limit_req zone and 16 KiB UI request cap. `/assets/`
   may serve static files, but must attach **all** App::headers() security headers
   with `always`, including CSP/no-store on errors. Other paths must never expose
   PHP source, state, config or the parent directory. Keep URI-only access logs
   (`$uri`, no query string or Authorization). Add public exact POST
   `/webhook/resend`, 64 KiB body cap and the Polar webhook rate zone. Preserve
   public `/refresh`, `/claim`, `/pro-download` and `/webhook/polar` routing.

7. **Migrate and verify isolation.** With new code installed, run a controlled
   admin API read to migrate. Confirm `user_version=2`, existing licence counts
   and keys unchanged, and all four tokens distinct. Run access checks under
   UI/ops Unix users: opening the signing file, licence DB and licence-secret
   directory must fail EACCES. Check status works without signing-key env.
   Check UI/ops tokens from the other instance get 401. Confirm neither UI port
   is reachable on a public interface.

8. **Update the private update server and privacy text.** Send the optional
   telemetry fields as in `docs/update-server.example.php`; reduce REMOTE_ADDR
   to /24 or /64 before the loopback POST, and never log the payload/net. Use
   the correct instance's API/status token. Keep the update vhost's true client
   IP (grey-cloud DNS; configure trusted real_ip if proxied later). In the
   separate landing repo add the Terms sentence: “The update server records
   installed versions to support you and send security notices.” Update localized
   Terms strings there. No landing or core file is changed by this PR.

9. **Install the per-instance worker service/timer.** The service is oneshot under
   its ops user with `TimeoutStartSec=55`, `NoNewPrivileges`,
   `ProtectSystem=strict`, `PrivateTmp`, `ProtectHome`,
   `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX`, `ReadWritePaths` restricted
   to that ops state dir. Exec `/usr/bin/php8.4 -d open_basedir=<ops code,client,
   config,state plus required TLS CA paths> <instance-code>/ops/ops-worker.php run`.
   Load only its own env file. Timer `OnCalendar=*-*-* *:*:00`; systemd prevents
   overlapping oneshot runs, and the application also flocks. Add `-sbx` unit
   names. Configure the four HTTPS health targets and traversable disk mount
   points explicitly; empty health config performs only disk/backup checks.

10. **Update backup/cron and operator CLI.** The existing root backup job writes
    status atomically as `{"ok":true,"at":<unix seconds>}` after a successful,
    integrity-checked backup. Keep its last successful at across failures and
    mark ok=false; chmod the status file readable by the licence API only.
    Retain 30 days of backups. Schedule the private `fluxfiles-license-backup
    --sweep` command, restore daily reminders using this code, and keep cron/env
    per instance. Do not install the Phase 2 public-counter collector/inbox cron.
    Add private CLI wrappers for `admin-user` (exec the shipped PHP CLI as UI
    user), `ui-token rotate`, `ops-token rotate`, `tunnel` (print SSH config),
    `telegram-setup` (one getUpdates call, never print token/URL), `list` through
    `/admin/licences`, and backup status writing. Rotate both copies atomically;
    restart their pools/worker after rotation.

11. **Configure providers and run one-off backfill.** In each Resend environment,
    create a delivery webhook to its `/webhook/resend` for delivered/bounced/
    complained/delivery_delayed events and install that signing secret. Keep
    Polar order.paid/order.refunded enabled; subscription.* is **Phase 2** and
    should not be enabled for MVP behaviour. Run backfill dry-run, inspect counts,
    then --apply with the matching production/sandbox one-off token; do not keep
    that token. Confirm tracked cap and Polar deep-link paths. The conservative
    refunded-slot rule is explicit in the UI.

12. **Enroll and smoke-test personally.** Create separate admin accounts/TOTP in
    both UIs using the CLI over SSH; passwords and TOTP secrets never go into a
    deploy script. Open each tunnel, check banner/title/amber versus production,
    language/theme, a masked licence read, issue + delivery, reveal audit actor,
    revoke preview, stats, backup age and worker heartbeat. Send test email and
    queue a Telegram test; confirm the correct tagged message arrives. Enable
    bot commands only after checking private-chat or group-user/topic constraints.
    Do not merge/tag/deploy as an automated part of this PR.

## Deviations and explicit decisions

- The prompt requests **one branch/one PR**, overriding §17's staged-PR suggestion.
- §14 includes tests for features marked P2/P3 elsewhere. Those endpoints/tables
  (CSV, replay, version ranges, reports, inbox/public-counter ingest, subscription
  webhooks and notices) are intentionally absent. No dormant public endpoints
  were added for later phases.
- Health targets default to an empty list, since production/sandbox hostnames are
  deployment-specific; the operator configures the four targets. TLS probes
  require trusted certificates, including in tests; untrusted self-signed certs
  are not silently accepted. Session/Auth is one cohesive class rather than
  separate empty Router/Session abstractions.
- Loopback credential destinations accept literal IPs only, excluding DNS
  `localhost`; incoming UI Hosts still support localhost. Polar deep links are
  restricted to https://polar.sh. Unknown discount refund rules conservatively
  count refunded slots and show them separately. Quiet hours are off for MVP.
- The outbox rejects unsafe persisted text before returning it, and the worker
  applies the same masking refusal. Digest rows replace excess alerts within the
  budget; no extra unbudgeted digest is sent. Lifetime dedup receipts retain only
  a SHA-256 dedup-key hash after completed outbox retention.
- Chromium's native POST navigation uses `Origin: null` with the required
  `Referrer-Policy: no-referrer`. Forms therefore use same-origin fetch in CORS
  mode so the exact Origin check stays strict. Native server-rendered forms and
  links remain, but browsers with that behaviour require JS for login/mutations.
  We do not accept null/missing Origin or weaken the required header.
- No-JS reveals are explicit POST-only pages; automatic 60-second clearing depends
  on JS. The UI states this limitation and offers a return link. All other MVP
  actions have server-rendered forms and confirmation pages.
- The legacy standalone CLI mail-preview behaviour remains for unchanged existing
  mail tests: without an onResult hook, CLI log transport may show full addresses
  and its pre-existing `FLUXFILES_MAIL_LOG_FULL=1` flag may show fixture keys.
  HTTP and the instrumented reminder/admin/webhook paths always mask logs and
  ignore that key-preview flag. Do not use the legacy diagnostic flag with real
  customer records.
- Unix users, nginx/FPM/systemd, bootstrap allow-lists, backup status production,
  private CLI wrappers, provider setup and landing Terms are listed above and
  deliberately not changed in this repository.
