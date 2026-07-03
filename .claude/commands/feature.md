---
description: Run the full spec → build → test → review pipeline for a feature idea using the subagents.
argument-hint: <feature idea>
---
Drive a new FluxFiles feature end-to-end for: $ARGUMENTS

Run the subagents in sequence, each building on the previous output:

1. **spec-writer** — produce the design (claims, endpoints, storage, security, test
   plan) that fits the stateless/module grain. Pause and show me the spec; if it raises
   a big open question (free vs paid, which OSS to embed), ask me before proceeding.
2. **coder** — implement the spec (free/core + gitignored private module if paid +
   adapters + i18n ×16 + `docs/CONFIG.md`). Keep the diff tight; lint + run relevant tests.
3. **tester** — write + run tests until the whole core suite + guards are green.
4. **reviewer** — read-only pass over the change (owner_only, SSRF, the module gate,
   private-module non-leakage, i18n/config-doc guards); report blocking vs non-blocking.

Then summarize for me: what shipped, test results, any blocking findings, and the
operational follow-ups (push the private package to its repo, add the module to the
license generator). **Do NOT commit, tag, or push** — I'll do the release step myself.
