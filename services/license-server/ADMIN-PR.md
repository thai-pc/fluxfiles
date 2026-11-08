# Licence admin — MVP, Phase 2 and Phase 3

Implements all phases in `docs/design/LICENSE-ADMIN-UI-DESIGN.md` after the owner's
scope expansion. One branch and PR, independent production/sandbox deployments.
No merge, tag, deployment, real mail/Telegram or private configuration changes.

- §2–6/9: public/status/admin-api split, four machine scopes and trusted UI actors,
  guarded additive migrations through v4, licence history, strict password/TOTP,
  hashed sessions, throttling, step-up, CSRF/exact Origin/Host and CSP. All admin
  screens, customer notes, mail/backlog, event replay, activity and audit.
- §7: per-currency accounting/refunds and rate, current MRR respecting subscription
  state, historical estimates, discount caps, installs, cohorts, reported versions
  and advisories, separate download/check sources, public growth/demo counts and
  informational anomalies. SVG/table fallbacks, negative charts and heat tables.
- §8/10–11: bounded Telegram worker, daily public counters/counts-only inbox,
  root collector reference, health/TLS/disk/backup, staged integrity-checked
  backups, public-key fingerprint comparison, replay-safe subscription state,
  cancellation-aware reminders, CSV, weekly reports and security notice jobs.
- Phase 3: viewer enforced in UI and API, optional RS256 Access gate with local
  refreshed JWKS, and default-off audited scoped Polar cancel action with a
  per-request token. API-offline dashboard and always-masked mail logs.

Plain PHP 8.1-compatible source, no dependency/build/CDN, one JS/CSS, no inline
script/style, no `packages/` changes. UI/ops never import the signer/store/issuer,
read the signing key or open the licence database. Only API mail jobs send mail;
only the ops worker sends Telegram. Root collector never exposes raw logs.

## Validation

Run from repository root:

```text
for f in services/license-server/tests/*.php; do php "$f" || exit; done
node services/license-server/tests/browser-admin.cjs
```

After round 2, **all 20 PHP scripts run 300 checks**. The local run uses PHP
8.2.35 with E_ALL/display_errors in both tests and HTTP fixture servers. Dedicated
CI covers PHP 8.1–8.5, installs cached core Composer dependencies and fails if the
119-check licence suite or 9-check renewal suite would otherwise skip.

| Suite | Passed |
|---|---:|
| Existing licence / sandbox mail / fallback | 119 / 8 / 5 |
| Pro download / refresh / renewal / concurrent renewal | 8 / 22 / 9 / 6 |
| Admin API / UI HTTP / UI security unit | 12 / 12 / 8 |
| Statistics-backfill / ops-Telegram-health | 5 / 7 |
| Phase API / phase unit / operations HTTP | 15 / 7 / 6 |
| Review data / HTTP / worker / UI / R2 | 10 / 13 / 13 / 8 / 7 |

PHP lint passes for **97 service PHP files** plus the update example; JavaScript
syntax, workflow YAML parsing and `git diff --check` pass. Chromium passes desktop
and 375px screens, header wrapping, 44px controls, explicit empty guidance, chart
legend/axis/currency tables, fresh step-up, CSV BOM, notices, viewer, sandbox and
host-only cookie isolation. No page errors or viewport overflow.

Round-2 regressions cover actor rotation against the shared token cap, immediate
revocation without a paid-period end, opaque message arguments, API-authoritative
worker language, CI dependency guards, sandbox host warnings, provider token
redaction, proxy refusal, System empty guidance and distinct zero-MRR axis labels.
Chromium verifies a 1440px list without horizontal scrolling, unbroken dates,
collapsed mobile navigation, a single header row, 44px row links, sticky list
actions and dashboard stage errors. Root-run collector formatting is separate.

The review regressions cover historical/cumulative refunds, key-rotation install
identity, observation grace, stage isolation, global Telegram 429, host-only
cookies, fresh step-up, poisoned CSV, same-origin downloads, bounded JWKS,
report failure/backoff receipts, collector hostile lines/permissions, localization
on fake-provider wires and portable browser execution. See
[REVIEW-FIXES.md](REVIEW-FIXES.md) for the A/B/C mapping.

A dedicated workflow runs PHP 8.1–8.5 and Chromium and checks actual collector
privilege drop only on a disposable CI runner. Local non-root tests verify CLI
permissions, hostile lines and symlink refusal. No real provider, user database,
mail, Telegram account, server configuration, merge, tag or deployment was used.

## Ordered server changes (operator applies; repeat per instance)

1. **Back up and select the instance.** Stop its reminder/ops writers briefly and
   take a consistent SQLite backup with the existing backup CLI before any new
   request runs the v4 migration. Preserve the original code for rollback. Do not
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
   an ops-owned 0750 `inbox/` for counts-only files. The ops code
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
   `FLUXFILES_ADMIN_TZ`, `FLUXFILES_LICENSE_BACKUP_STAGE`,
   `FLUXFILES_ADMIN_ANALYTICS_URL`, `FLUXFILES_ADMIN_DEMO_BUDGET_BYTES` and the
   default-off `FLUXFILES_ADMIN_POLAR_ACTIONS`. Pass existing `FLUXFILES_OPS_EMAIL` and
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
   **127.0.0.1 only**, provide a default server returning 444, allow only the instance-specific prod.localhost
   or sbx.localhost Host, serve only `admin/public`, and route pages to its UI pool.
   Add a per-instance login limit_req zone and 16 KiB UI request cap. `/assets/`
   may serve static files, but must attach **all** App::headers() security headers
   with `always`, including CSP/no-store on errors. Other paths must never expose
   PHP source, state, config or the parent directory. Keep URI-only access logs
   (`$uri`, no query string or Authorization). Add public exact POST
   `/webhook/resend`, 64 KiB body cap and the Polar webhook rate zone. Set public `/webhook/polar` to a **1 MiB** body cap (only retained/replayed
   bodies stay 64 KiB). Preserve
   public `/refresh`, `/claim`, `/pro-download` and `/webhook/polar` routing.

7. **Migrate and verify isolation.** With new code installed, run a controlled
   admin API read to migrate. Confirm `user_version=4`, existing licence counts
   and keys unchanged, and all four nonempty machine tokens pairwise distinct (all eight across instances). Run access checks under
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

10. **Update backup/collector/cron and operator CLI.** Root's existing backup job
    writes status atomically as `{last_ok_at,file,bytes,integrity:"ok",kept,
    oldest_at,last_error}`; timestamps are integer Unix seconds. Keep the last
    successful time across failures. Status is readable only by the licence API.
    Configure its private 0700 backup-stage directory, retain 30 days and schedule
    the existing root backup `--sweep` for staged snapshots. No HTTP DB download.
    Restore the reminder cron with this code; it respects observed cancellations.

    Configure a root daily 00:10 collector job using `collect-ops.php` and the
    actual private log paths/formats and sandbox-mint path. Supply yesterday's
    explicit `<inbox>/<day>.json` output path (example in README). The reference
    parser handles combined/JSON nginx logs and cleaner counts. Root reads logs,
    then the CLI requires POSIX and drops permanently to the inbox owner before
    writing 0640 ops:ops-group JSON. API/UI/ops never receive raw-log access. The
    ops-owned 0750 inbox allows validation, quarantine and acknowledgement unlink.
    Inspect a sample output for counts-only fields before enabling the worker.

    Add private CLI wrappers for `admin-user` (exec PHP as UI user), `ui-token
    rotate`, `ops-token rotate`, `tunnel` (print SSH config), `telegram-setup`
    (one getUpdates, no token/URL output), `list` through `/admin/licences`, and
    backup status writing. Rotate token copies atomically and restart consumers.

11. **Configure providers and run one-off backfill.** In each Resend environment,
    create a delivery webhook to its `/webhook/resend` for delivered/bounced/
    complained/delivery_delayed events and install that signing secret. Keep
    Polar order.paid/order.refunded enabled and subscribe to
    subscription.canceled, subscription.uncanceled, subscription.revoked and
    subscription.past_due only after migration. Canceled is not an immediate
    revoke; verify the 48-hour revoked-event policy with sandbox purchases.
    Run backfill dry-run, inspect counts,
    then --apply with the matching production/sandbox one-off token; do not keep
    that token. Configure `FLUXFILES_OPS_PUBLIC_COUNTERS` with approved subjects
    (max 20/day); an empty object makes no counter calls. Confirm tracked cap
    and Polar deep-link paths. The conservative refunded-slot rule is explicit in the UI.

12. **Enroll and smoke-test personally.** Create separate admin accounts/TOTP in
    both UIs using the CLI over SSH; passwords and TOTP secrets never go into a
    deploy script. Open each tunnel, check banner/title/amber versus production,
    language/theme, a masked licence read, issue + delivery, reveal audit actor,
    revoke preview, stats, backup age and worker heartbeat. Send test email and
    queue a Telegram test; confirm the correct tagged message arrives. Enable
    bot commands only after checking private-chat or group-user/topic constraints.
    Do not merge/tag/deploy as an automated part of this PR.

13. **Optional viewer/Access/Polar activation.** Enroll independent users with
    `admin-user.php add <name> [admin|viewer]`; role changes invalidate sessions.
    If using Access, first configure a separately reviewed Cloudflare Tunnel and
    Access policy pointing only to the loopback UI, then set ACCESS_ENABLED,
    ACCESS_HOST/ISSUER/AUD/JWKS_FILE in the UI-specific config. Root runs
    `admin/cli/access-keys.php` initially and hourly; UI reads a 0640 local JWKS,
    refuses files older than 24 hours, and still requires password/TOTP. Forward
    assertion and HTTPS scheme only from the trusted loopback proxy. All assets
    must pass the gate. Keep SSH-only defaults until this is verified. No public
    nginx admin vhost is introduced here.

    Optional Polar actions remain off until the operator chooses to enable the
    API flag. An admin pastes a scoped `subscriptions:write` token for a single
    confirmed action, with step-up; the token is never stored. Use the matching
    production/sandbox provider and verify cancellation through its webhook.

## Deviations and explicit decisions

- The owner expanded MVP to all phases and requested one branch/PR, overriding
  the original MVP-only prompt and the design's staged-PR suggestion.
- Strict Origin plus `Referrer-Policy: no-referrer` causes Chromium native POSTs
  to send Origin null. Forms use same-origin CORS fetch; null/missing Origin is
  rejected. Login/mutations therefore require JS in affected browsers. Read-only
  pages are server-rendered. Key auto-clear also requires JS; no-JS reveal pages
  state their limitation. We keep the security boundary instead of silently
  claiming full no-JS support.
- Health targets, public counter subjects, live Polar links and private log
  formats require operator values. Nothing guesses production hostnames or reads
  real logs during development. Counter fixtures validate all four source shapes.
- The spec's root-owned 0750 inbox cannot allow the worker's required unlink.
  Use an ops-owned 0750 inbox. The root parser drops privileges before creating
  or chmodding files there, so counts files are ops-owned 0640 instead of root
  owned. Worker-writable paths cannot become privileged filesystem writes.
  POSIX is required only by this optional root deployment helper for irreversible
  privilege dropping; API/UI/ops keep the original extension requirements.
- Unknown discount refund rules conservatively consume slots, shown separately.
  Quiet hours stay off. Sandbox never consumes bot command updates when sharing
  the production bot. Machine destinations require literal loopback, not DNS.
- Weekly reports use the previous completed ISO week and current month-to-date.
  An ambiguous interrupted sending receipt requires operator inspection; Resend
  idempotency keys protect retries, SMTP cannot promise exactly once after crash.
- Notices snapshot approved recipient/version/advisory context and reject changed
  previews. Identical request receipts remain idempotent after later data changes.
  Current recipient eligibility is checked again before sending.
- Historical MRR and retention before collected history are estimates, not invented
  reconstruction. Nonpositive-net refund rates are undefined. Anomalies never
  revoke automatically. Optional Polar token is runtime-only, not persisted.
- Existing mail tests now assert mandatory masking rather than the retired
  `FLUXFILES_MAIL_LOG_FULL` bypass. Existing stats tests expect migration v4;
  other buyer contracts remain covered. Local validation covers PHP 8.2–8.5;
  PHP 8.1 is covered by the dedicated CI matrix.
- All Unix users, ACLs/nginx/FPM/systemd, bootstrap allow-lists, root timers,
  provider setup, initial user/TOTP enrolment, real-instance smokes and landing
  Terms remain operator deployment work listed above. No automated deploy.


## Review follow-up

All A/B/C review items are mapped to code and regressions in
[REVIEW-FIXES.md](REVIEW-FIXES.md). Formatting is a separate commit. Deploy with
`prod.localhost` and `sbx.localhost`, because ports do not isolate cookies. Login
TOTP never grants step-up. Use a separate fresh authenticator code for sensitive
actions. API and UI ports must remain loopback-only.

The optional Polar token is organisation-wide. Use short expiry and revoke it
after the confirmed action. The v4 refund ledger records per-increment dates;
backfilling a cumulative order cannot reconstruct missing earlier partial events.
Worker stage failures appear by name, without exception text, in heartbeat/System.
No merge, tag, provider configuration or deployment was performed.
