---
description: Run the full plan → spec → build → test → review → fix pipeline for a feature idea using the subagents.
argument-hint: <feature idea>
---
Drive a new FluxFiles feature end-to-end for: $ARGUMENTS

Run the subagents in sequence, each building on the previous output:

1. **planner** — decide *whether and what*: build-in-core vs paid module vs BYO-embed
   OSS vs don't-build, who pays, and the v1 scope + explicit out-of-scope. Writes
   `.claude/work/plan-<slug>.md`. **Pause and show me the decision.** If it says
   don't-build or embed-instead, stop there and let me choose — do not proceed to spec.
2. **spec-writer** — turn the approved plan into the design (claims, endpoints, storage,
   security, test plan) that fits the stateless/module grain. Pause and show me the spec;
   if it raises a big open question, ask me before proceeding.
3. **coder** — implement the spec (free/core + gitignored private module if paid +
   adapters + i18n ×16 + `docs/CONFIG.md`). Keep the diff tight; lint + run relevant tests.
4. **tester** — write + run tests until the whole core suite + guards are green.
5. **reviewer** — read-only pass over the change (owner_only, SSRF, the module gate,
   private-module non-leakage, i18n/config-doc guards). Writes numbered findings to
   `.claude/work/review-<slug>.md`.
6. **coder** (fix pass) — apply every **Blocking** finding from that file, plus the
   cheap correct non-blocking ones; re-run the guards + affected tests until green.
   Report by finding number. If it had to change behavior to fix something, send it
   back through **reviewer** once more.

Then summarize for me: the plan decision, what shipped, test results, any findings left
unfixed and why, and the operational follow-ups (push the private package to its repo,
add the module to the license generator). **Do NOT commit, tag, or push** — I'll do the
release step myself.
