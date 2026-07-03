---
description: Write + run tests for the current change (or $ARGUMENTS) with the tester subagent until the suite is green.
argument-hint: [what to test, e.g. "the versioning module"]
---
Use the **tester** subagent to write and run tests for $ARGUMENTS.

If $ARGUMENTS is empty, cover the current uncommitted change.

Follow the patterns in `.claude/agents/tester.md`: plain-PHP `test()`/`assertEqual`/
`expectApi` files; module tests require the core autoload then `require_once` the
module `src/` directly; endpoints get a self-booting `php -S` e2e. Cover the happy path
+ every guard/error branch + security regressions (owner_only, SSRF, path/system-path,
extension immutability, caps) + storage-resident state. Run the new test(s), the guards
(`test-i18n.php`, `test-config-doc.php`), and the full core suite until green. Report
the tests added + final pass counts. Do not commit.
