---
paths:
  - "packages/core/api/Mail/**"
  - "packages/core/api/NotifyEndpoint.php"
  - "packages/core/api/NotifyProviderInterface.php"
  - "packages/core/api/NotifyScheduler.php"
  - "packages/intake/**"
  - "packages/share/**"
---

**E-mail notices** (design: `docs/design/INTAKE-SHARE-NOTIFICATIONS-DESIGN.md`). Core ships only an inert mail layer (`api/Mail/*`), `NotifySupport` (what both modules share: create-time recipient rules, the sealed config, seal/open, send caps, text helpers), `PublicContext::mailer()` (host-injected transport → env `FLUXFILES_MAIL_*` → null; WordPress injects `FluxFilesMailTransport` (wp_mail; filter `fluxfiles_mail_transport`), Laravel `LaravelMailTransport` (Mail::raw); an explicit FLUXFILES_MAIL_TRANSPORT wins; the WP build drops SmtpTransport). The proxies pass `PublicContext` a resolver **Closure**: it runs only when a notice is sent and its answer is final, so a null from it means off, never "fall back to env", `NotifyProviderInterface` and the operator routes in `NotifyEndpoint` (`/notify/test|run|mute`, shared by index.php and both proxies). The events, digest, seal and sweep live in the `intake`/`share` modules. Rules that are load-bearing:
- **Mail credentials are env-only, never claims** (a JWT is signed, not encrypted). Provider hosts are fixed in code (no SSRF); the SMTP host is trusted operator env; `none` + AUTH only to loopback.
- **Plain text only.** Filenames come from strangers; no HTML, no attachments, no file URLs, no fetched logo. `MailMessage` refuses CR/LF in every header and validates every address.
- **Recipients are sealed into the record** (AES-GCM, AAD-bound) at create time; a tampered block sends nothing and never falls back to claims. Mute lives outside the seal (it can only reduce mail).
- **At-most-once, post-response, never blocks an upload/download** — same posture as Webhooks. Don't add retries or a queue.
- A `MailException` message must never contain a credential: build it with `MailException::scrubbed()`.

**Document requests** (step 2, design `docs/design/INTAKE-DOCUMENT-REQUESTS-DESIGN.md`; all in the Intake module's `IntakeRequests` trait + `IntakeNotify`). A portal may carry `request` (checklist, `due_at`, message) + `request_state` (per-item counts, `complete_at`), mutated only inside `withLock` and only on a successful upload. Load-bearing:
- The client (requestee) address and the portal URL (it holds the JWT) live ONLY in the sealed `notify` block (`requestee`, `url`; the seal stays `v: 1`, the keys are optional, so step-1 records open unchanged; a client-only record has an empty `to`, which `NotifySupport::open()` accepts only when a valid requestee is present). The plaintext `notify.req` flag is display-only. A requestee needs the address to pass `notify_to`/domains or the claim `intake_request_any_recipient`, and a configured `intake_base_url`.
- Every once-only mail (`reminder_3d`, `reminder_1d`, `overdue`, `complete`, `request_sent`) claims its `notified` flag under the lock BEFORE sending (at-most-once, no retries). A reminder past its window is skipped, never sent late. Nothing is sent after `complete_at` except the completion mails.
- Completion rides the digest (`complete: true`) so the owner gets ONE mail; `afterUpload($ctx, $payload, $filled, $completed)` flushes immediately and claims the client's thanks. A checklist with no required item never completes.
- Client mails go through `mailRequestee()`: 6 h gap (not for the thanks), 3/day per address, 6 per portal. The `item` field is validated against the record's ids; the subfolder is server-derived, never from the request.
- `IntakeModule::$clock` is the test seam for the sweep. Core, Laravel and WordPress all pass `$mailer`/`$audit` to `createPortal()` (a mailer only for `request.send_now`; the proxies hand over their host transport), so `send_now` works on every host. WordPress grants `intake_request_any_recipient` only to `manage_options` users, never to other roles with Intake.
- **Schedulers** (`bin/fluxfiles notify:run`, `artisan fluxfiles:notify-run`, WP Pro cron; `NotifyScheduler::sweep()`): the sweep reads ONE store per prefix and mails only sealed recipients, so claims carry just gates/perms/disk/prefix (no `notify_*` env). A run covers exactly the prefixes given (`--prefix` repeatable, `--prefixes-file`, `--discover` = local disks only, depth <= 6, <= 10 000 stores, `_fluxfiles/trash` skipped); default is the empty prefix only. Exit 0 ok / 1 usage-config-gate / 2 storage or unexpected failure.
