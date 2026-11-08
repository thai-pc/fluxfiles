# Licence admin: implementation and review checklist

Scope: all phases in `docs/design/LICENSE-ADMIN-UI-DESIGN.md`, per the owner's
expanded request. Same branch and PR #245; no merge, tag, deployment, provider
configuration or real mail/Telegram send is authorized by this implementation.

- [x] v4 migrations: subscriptions, ops items, activity/download/public/demo counters,
      customer notes, advisories and notices; preserve existing licence rows.
- [x] Subscription webhooks, out-of-order handling, 48-hour revoke rule and reminder suppression.
- [x] Customers and per-customer notes; mail log/backlog; webhook replay; activity.
- [x] CSV API and UI, BOM, formula protection, no keys, step-up and audit.
- [x] A2 cohorts, A5 versions/advisories, A6 downloads, B1 analytics link,
      B2 public counters, B3 demo counts, A8 informational anomalies.
- [x] Backup staging/integrity, full backup-status schema, key fingerprint comparison.
- [x] C1 weekly report, worker mail jobs, counter polling and counts-only inbox.
- [x] Root-side collector reference; logs never enter the UI/API/worker.
- [x] C5 notice preview, stable approved recipient/advisory context, queue and mail logs.
- [x] Viewer role in CLI/UI/API and optional Access JWT verification with local JWKS.
- [x] Optional, default-off Polar action with a per-request scoped token, never stored.
- [x] Negative chart direction and heat tables; API-offline dashboard shell; IDs/copy/archive styling.
- [x] Retired diagnostic flag cannot expose keys or raw email addresses in logs.
- [x] New phase contract/HTTP/security tests and complete existing suite.
- [x] Browser checks for all new screens, viewer permissions and notices.
- [x] Refresh README, all env examples, deployment instructions and PR description.

The strict Origin + no-referrer requirement conflicts with Chromium native POST
navigation without JavaScript. The implementation retains strict Origin and its
fetch enhancement; it never accepts null/missing Origin. This security choice
must remain explicit in the review notes, rather than being presented as native
no-JS support.

Deployment-only items stay with the operator: users/ACLs/pools/nginx/timers,
provider secrets, Access/Tunnel policy and JWKS refresh, private log-format wiring,
root backup sweep, initial user/TOTP enrolment, real-instance smoke and Terms text.

Baseline validation before review: 15 PHP scripts / 249 checks, 88 service PHP lints, JavaScript syntax,
Chromium desktop/mobile + viewer/notices/CSV/two instances and collector CLI smoke.
Executed on PHP 8.2.35; other supported PHP versions still need CI/operator checks.

Review follow-up: all A/B/C items map to `REVIEW-FIXES.md`. The v4 migration adds
refund increments and observation grace without rewriting signed keys. Formatting
is committed separately; final matrix/browser results are recorded in the PR reply.

Round-2 validation: 20 scripts / 300 checks locally on PHP 8.2.35; 97 service PHP
lints; desktop/mobile Chromium, including 1440px list fit, mobile navigation/header,
44px links, sticky list actions and visible stage errors. Dedicated PHP 8.1–8.5
CI installs core dependencies, rejects missing-dependency skips and runs actual
root collector privilege-drop checks. Final CI results are linked in the PR reply.
