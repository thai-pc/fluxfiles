---
description: Implement a feature/fix with the coder subagent, following the exact FluxFiles patterns.
argument-hint: <what to build, or a spec path>
---
Use the **coder** subagent to implement: $ARGUMENTS

Follow the FluxFiles rules in `.claude/agents/coder.md` exactly: stateless/no-DB;
every error_code gets i18n ×16; every new claim goes in `docs/CONFIG.md` + parsed in
`Claims` (+ `isAllowed` case for module claims) + forwarded in
embed/node(+dist)/laravel/wordpress; a paid feature is a **gitignored private module**
on the `ModuleRegistry` gate (never stage private-module files); public endpoints go
before the main auth block; SSRF-guard outbound fetches; never put the main JWT in a
URL. `php -l` / `node --check` edited files, run the relevant tests, and do NOT
commit or release. Report what changed + follow-ups.
