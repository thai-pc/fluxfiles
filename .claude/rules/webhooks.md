---
paths:
  - "packages/webhooks/**"
---

**Webhooks is shipped and intentionally at-most-once.** `docs/ROADMAP.md`'s DROP table once listed Webhooks as out-of-scope ("needs a persisted outbox + retries"); `COMMERCIAL-STRATEGY.md` §6b (2026-06-28) reversed that — event-driven **is** stateless when the webhook fires on the request that caused the event. `WebhooksModule::dispatch` is post-response, synchronous, best-effort: a dead endpoint loses the event (`error_log` only). Only the *guaranteed-delivery* variant (outbox + retries) stays dropped. Don't "fix" the missing retries by adding a queue.
