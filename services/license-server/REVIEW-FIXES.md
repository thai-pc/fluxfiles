# PR #245 review fixes

Review baseline: `dc2ffaf`. Source: the owner's `PR245-FIX-LIST.md`.
All changes stay on `feat/license-admin-ui`. No merge, tag or deployment.

| Review | Resolution | Regression evidence |
|---|---|---|
| A1 | Independent worker stages; heartbeat precedes immediate outbox; stage names in heartbeat/CLI; skip invalid individual targets | `test-review-ops-http.php`: broken JSON, invalid target, >20 targets, continued valid probe, stalled stage |
| A2 | Polar `modified_at`, then envelope `timestamp`, then receipt time; stable first refund date; cumulative snapshots and per-increment ledger | `test-review-data.php`, `test-review-http.php`: historical refunded backfill, duplicate/replay, partials across months/out-of-order |
| B1 | Explicit `prod.localhost`/`sbx.localhost` allowlists and host-only cookies; login TOTP never grants step-up | `test-review-ui.php`, HTTP hostname guard, browser hostname cookie isolation |
| B2 | CSV redacts key-shaped cells and audits redaction count without aborting export | CSV poisoning HTTP regression |
| B3 | API reveal rate limit/audit/burst alert; matching dummy password algorithm/cost; same-origin CSV GET; bounded JWKS reader; Telegram proxy off; configured session GC; Secure logout; null role read-only | review UI and HTTP security tests; viewer/auth suites |
| B4 | Version-aware SQLite UDF registration; replace no-op/deprecated curl close and reflection accessibility calls | PHP 8.5 with E_ALL/display_errors, copied migration and public-response contracts |
| B5 | Migration observation timestamp; seven-day grace; exclude manual never-installed rows and resolve stale items | review data grace/sweep test |
| B6 | Signed Polar receive limit 1 MiB; retained/replayed bodies remain 64 KiB | large paid/unmapped/oversize webhook tests |
| B7 | Subscription revocation is monotonic even when stale | stale revoke / subsequent uncancel regression |
| B8 | Distinct initial discount redemptions, excluding cycle orders from cap usage | discount cycle fixture; existing accounting suite |
| B9 | Masked, truncated diagnostic detail retained; SMTP/provider tokens and keys redacted | diagnostic regression and mail fallback/sandbox suites |
| B10 | Install/version identity follows subscription or renewal root across key rotations | monthly and lifetime renewal fixtures |
| B11 | Expired webhook bodies cleared on every event and reminder run | event-write and reminder CLI regressions |
| B12 | Subscription timeline association; paid-period end for 48-hour rule; UTF-8-safe current version; per-month refund increments; local install-day boundaries; bounded dedup receipts; indexed signature lookup; normalized short versions; early temp-copy cleanup; explicit imports | review data/HTTP tests and existing telemetry/backfill suite |
| B13 | Persist global Telegram blocked-until and block all sends/polls across runs | 429 two-row + commands regression |
| B14 | Expire unread outbox rows even with Telegram off; expire notice rows consistently with their counters | retention regression |
| B15 | Preparation lease, failed receipt on exceptions, sending only around transport, exponential retry time | report preparation/transport failure regression |
| B16 | Resume skipped health targets without advancing schedule; disk path in alert; stage-only CLI errors; single bot-name comparison; discard overlong physical log lines, anchored log parser | cursor, timeout, bot/thread, collector tests |
| B17 | Normal word wrapping and nonwrapping table headers | browser computed-style/header check and desktop/mobile captures |
| B18 | Translated dynamic facts/timeline/Polar/chart headers; count label; nested facts replace raw JSON blobs | dynamic-label and chart fallback regression |
| B19 | Section-specific empty guidance and next steps | empty-state unit/browser coverage |
| B20 | Visible chart titles, series legend, y-axis, correct refund-period heading, per-plan/discount headings and major-unit tables | chart/table unit and browser regression |
| B21 | Vietnamese wording corrected; auto theme distinguished from System; duplicate uptime key removed | catalogue key/duplicate tests |
| B22 | Shared ops catalogue, Vietnamese default with explicit English option, including report/digest/suppression | catalogue tests and on-wire worker/report fixtures |
| B23 | Correct collapse label/replay wording; separate note labels and theme-save label; full-height sidebar; mobile action bar; 44px controls | source/renderer and browser checks |
| B24 | Formatting is a separate commit after functional fixes | PHP lint, semantics checks and full suites after formatting |
| B25 | Configurable PHP/module/output paths; install/run instructions; dedicated PHP-matrix/browser CI | portable browser run and `.github/workflows/license-admin.yml` |
| C | Loud token uniqueness/loopback exposure checks; short-expiry organisation-wide Polar token guidance | README and operator checklist |

The new root collector smoke runs only on a disposable CI runner, with generated
root-private log input and worker-owned output. It verifies actual privilege drop,
0640 output and symlink refusal. Local non-root CLI tests cover output, hostile
lines and symlink refusal; no privileged local service is touched.

Historical limitation: an order-only backfill exposes the cumulative refunded
amount and the order's latest modified time, not the earlier partial-refund event
history. Original retained webhook snapshots can refine the per-increment ledger;
missing historical events are not fabricated.

Final local verification: 19 scripts / 288 checks each on PHP 8.2.35, 8.3.36,
8.4.27 and 8.5.12, with no warnings/deprecations in captured output. HTTP fixture
servers also enable E_ALL/display_errors. Chromium and 96 service PHP lints pass.
The clock-boundary replay test captures the exact login OTP instead of generating
a possibly new OTP after hashing. CI adds PHP 8.1 and the privileged collector smoke.

Commits: `622b81a` contains behavior fixes; `ca04a66` is formatting only. The final
validation/empty-guidance follow-up and CI results are listed in the PR reply.

The first Linux CI run exposed two additional portability issues: trait constants
(required PHP 8.2) were moved into the consuming class, and the telemetry test now
inspects a consistent SQLite snapshot. Opening/closing the live DB file outside
SQLite can release POSIX locks; the test also checks database integrity. These
were fixed before the final CI rerun.

The CSRF HTTP fixture also replaces its default Origin header when a test supplies
an override, instead of sending two Origin headers. PHP 8.1's development server
selects the first duplicate header; the test now sends the intended single hostile
Origin consistently and still requires a 403 response.


## Round 2 (re-review of d5791e2)

| Item | Change | Regression evidence |
|---|---|---|
| R1 | Shared authenticated-role reveal bucket alongside actor bucket; refusal audit and burst alert | `test-review-http.php`: ten rotated actors allowed, next thirty refused, one suppressed burst alert |
| R2 | Revoke fallback uses licence expiry, never immediate `ended_at` | `test-review-r2.php`: null/absent period-end payloads with 24h/72h licences |
| R3 | Exact templates translate before opaque arguments; storage never retranslates messages; worker follows API language | Unit target/product/masked-email/placeholder cases; weekly-provider wire; worker mismatched-language wire |
| R4 | Cached core Composer install in PHP matrix; missing vendor fails both legacy suites under CI | Subprocess missing-vendor fixtures; actual 119 + 9 suites in local/full CI runs |
| R5 | Root collector/parser formatting only, separate commit | PHP semantic AST comparison; collector parser/CLI/root smoke suites |
| R6 | Sandbox System warns on shared localhost/IP; explicit isolated hostname removes warning | Authenticated HTTP checks on localhost, 127.0.0.1 and sbx.localhost |
| R7 | Mask provider/Bearer tokens, viewer fallback upstream, disable Polar proxy inheritance | Diagnostic unit, anonymous-login audit HTTP, cancel HTTP with hostile proxy env |
| R8 | Detail/action/empty labels, System guidance, list fit/dates/links, MRR labels, mobile menu/header/sticky list and dashboard errors | Renderer/axis units plus Chromium 1440/375px checks and screenshots |
| R9 | Explain systemd failed state, journal stage names, recovery and dashboard status | Real worker API-503 fixture asserts exit 1, stage-only CLI diagnostic and retained delivery/heartbeat |
| Untested follow-up | API 5xx stage isolation; cleaner parsing | Fake-API 503 worker fixture; JSON/legacy cleaner counts with wrong-day, negative and overlong hostile input |

Round-2 test totals: 20 scripts, 300 named checks. Local PHP uses E_ALL; CI includes
PHP 8.1–8.5, core dependencies, privileged collector smoke and Chromium. The PR
reply records the final run and commit list. No merge, tag or deployment.
