---
paths:
  - "packages/core/api/Mail/**"
  - "packages/core/api/NotifyEndpoint.php"
  - "packages/core/api/NotifyProviderInterface.php"
  - "packages/intake/**"
  - "packages/share/**"
---

**E-mail notices** (design: `docs/design/INTAKE-SHARE-NOTIFICATIONS-DESIGN.md`). Core ships only an inert mail layer (`api/Mail/*`), `NotifySupport` (what both modules share: create-time recipient rules, the sealed config, seal/open, send caps, text helpers), `PublicContext::mailer()` (host-injected transport → env `FLUXFILES_MAIL_*` → null; the proxies don't inject a host transport yet — wp_mail / Mail::raw are a follow-up), `NotifyProviderInterface` and the operator routes in `NotifyEndpoint` (`/notify/test|run|mute`, shared by index.php and both proxies). The events, digest, seal and sweep live in the `intake`/`share` modules. Rules that are load-bearing:
- **Mail credentials are env-only, never claims** (a JWT is signed, not encrypted). Provider hosts are fixed in code (no SSRF); the SMTP host is trusted operator env; `none` + AUTH only to loopback.
- **Plain text only.** Filenames come from strangers; no HTML, no attachments, no file URLs, no fetched logo. `MailMessage` refuses CR/LF in every header and validates every address.
- **Recipients are sealed into the record** (AES-GCM, AAD-bound) at create time; a tampered block sends nothing and never falls back to claims. Mute lives outside the seal (it can only reduce mail).
- **At-most-once, post-response, never blocks an upload/download** — same posture as Webhooks. Don't add retries or a queue.
- A `MailException` message must never contain a credential: build it with `MailException::scrubbed()`.
