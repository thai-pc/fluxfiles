---
description: Apply the reviewer's blocking findings with the coder subagent, then re-run the guards.
argument-hint: [findings file or what to fix — defaults to the newest .claude/work/review-*.md]
---
Use the **coder** subagent to fix the review findings in: $ARGUMENTS

If $ARGUMENTS is empty, use the most recent `.claude/work/review-*.md`.

Fix **every Blocking finding** (`B1`, `B2`, …). Judge each Non-blocking note on its
merits — apply the cheap correct ones, skip the rest; don't refactor beyond the
findings. Keep the diff tight and follow `.claude/agents/coder.md` (stateless/no-DB;
error_code → i18n ×16; new claim → `docs/CONFIG.md` + `Claims` + forwarded; never stage
private-module files).

Then `php -l` the edited files and re-run the guards + the tests covering the change
(`test-i18n.php`, `test-config-doc.php`, `test-modules.php`, plus the relevant
unit/integration files) until green.

Report back **by finding number** — fixed / skipped (with why) / not applicable — plus
the final test counts. Do NOT commit, tag, or push.
