---
paths:
  - "packages/core/api/Mail/**"
  - "packages/core/api/NotifyEndpoint.php"
  - "packages/core/api/NotifyProviderInterface.php"
  - "packages/core/api/NotifyScheduler.php"
  - "packages/intake/**"
  - "packages/share/**"
---

**E-mail notices** (design: `fluxfiles-private-docs: design/INTAKE-SHARE-NOTIFICATIONS-DESIGN.md`). Core ships only an inert mail layer (`api/Mail/*`), `NotifySupport` (what both modules share: create-time recipient rules, the sealed config, seal/open, send caps, text helpers), `PublicContext::mailer()` (host-injected transport → env `FLUXFILES_MAIL_*` → null; WordPress injects `FluxFilesMailTransport` (wp_mail; filter `fluxfiles_mail_transport`), Laravel `LaravelMailTransport` (Mail::raw); an explicit FLUXFILES_MAIL_TRANSPORT wins; the WP build drops SmtpTransport). The proxies pass `PublicContext` a resolver **Closure**: it runs only when a notice is sent and its answer is final, so a null from it means off, never "fall back to env", `NotifyProviderInterface` and the operator routes in `NotifyEndpoint` (`/notify/test|run|mute`, shared by index.php and both proxies). The events, digest, seal and sweep live in the `intake`/`share` modules. Rules that are load-bearing:
- **Mail credentials are env-only, never claims** (a JWT is signed, not encrypted). Provider hosts are fixed in code (no SSRF); the SMTP host is trusted operator env; `none` + AUTH only to loopback.
- **Plain text only.** Filenames come from strangers; no HTML, no attachments, no file URLs, no fetched logo. `MailMessage` refuses CR/LF in every header and validates every address.
- **Recipients are sealed into the record** (AES-GCM, AAD-bound) at create time; a tampered block sends nothing and never falls back to claims. Mute lives outside the seal (it can only reduce mail).
- **At-most-once, post-response, never blocks an upload/download** — same posture as Webhooks. Don't add retries or a queue.
- A `MailException` message must never contain a credential: build it with `MailException::scrubbed()`.

**Document requests** (Intake module, private package): the agent notes for the module internals (sealed requestee, once-only reminder flags, client-mail caps, test seams) are in `fluxfiles-private-docs: rules/intake-document-requests.md`, next to the design spec. Core's side is only what is above plus the scheduler below: the client address and portal URL live in the sealed `notify` block, never in plaintext or claims.

**Schedulers** (`bin/fluxfiles notify:run`, `artisan fluxfiles:notify-run`, WP Pro cron; `NotifyScheduler::sweep()`): the sweep reads ONE store per prefix and mails only sealed recipients, so claims carry just gates/perms/disk/prefix (no `notify_*` env). A run covers exactly the prefixes given (`--prefix` repeatable, `--prefixes-file`, `--discover` = local disks only, depth <= 6, <= 10 000 stores, `_fluxfiles/trash` skipped); default is the empty prefix only. Exit 0 ok / 1 usage-config-gate / 2 storage or unexpected failure.
