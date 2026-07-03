---
description: Review the current change (or $ARGUMENTS) with the reviewer subagent — FluxFiles security/gate/guard checklist.
argument-hint: [what to review, e.g. "the webhooks change" or a path]
---
Use the **reviewer** subagent to review $ARGUMENTS.

If $ARGUMENTS is empty, review the current uncommitted change (`git status` / `git diff`).

The reviewer must run its FluxFiles checklist (see `.claude/agents/reviewer.md`):
owner_only on destructive ops, SSRF on outbound fetch, path/system-path scoping, the
paid-module 3-layer gate (`isAllowed` case + registry + `require` + 501 degrade),
**private-module non-leakage** (`git diff --cached --name-only | grep packages/<x>/`),
and the completeness guards (`test-i18n.php`, `test-config-doc.php`). It runs the tests
itself to confirm, and reports **blocking** (with `file:line` + fix) vs **non-blocking**
findings. It does not edit code.
