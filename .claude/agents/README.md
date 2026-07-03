# FluxFiles subagents

Project-scoped Claude Code subagents. Each is a specialist with its own tools + a
system prompt preloaded with FluxFiles' conventions (stateless/JWT-claims grain, the
paid-module gate, i18n/config-doc guards, security pitfalls). Claude Code auto-discovers
these from `.claude/agents/`.

| Agent | Role | Tools | Edits code? |
|---|---|---|---|
| **spec-writer** | Turn an idea into a concrete design/spec doc (API, claims, storage, security, test plan) that fits the grain — before coding. | Read/Grep/Glob/Write/Web | writes docs only |
| **coder** | Implement a feature/fix following the exact repo patterns (module gate, claims, i18n, hooks). | Read/Edit/Write/Bash/Grep/Glob | yes |
| **reviewer** | Review a change for FluxFiles-specific security/correctness pitfalls + guard/leak checks. | Read/Grep/Glob/Bash | no (reports) |
| **tester** | Write + run tests in the repo's patterns until the suite is green. | Read/Write/Edit/Bash/Grep/Glob | tests only |

## Suggested workflow for a non-trivial feature

```
spec-writer → coder → tester → reviewer → (you) commit + release
```

1. **spec-writer** produces the design (claims, endpoints, storage, security, test plan).
2. **coder** implements it (free/core + gitignored private module + adapters + i18n + docs).
3. **tester** writes/locks tests and gets the whole suite green.
4. **reviewer** does a read-only pass (owner_only, SSRF, the module gate, private-module
   leakage, i18n/config-doc guards) and reports blocking vs non-blocking findings.
5. You (main session) do the commit/release — subagents never commit or release.

## How to invoke

- **Slash commands** (`.claude/commands/`) — the quick way:
  | Command | Runs |
  |---|---|
  | `/spec <idea>` | spec-writer → design doc |
  | `/build <what>` | coder → implement |
  | `/test [what]` | tester → write + run tests green |
  | `/review [what]` | reviewer → read-only checklist review |
  | `/feature <idea>` | the full spec → build → test → review pipeline |
- Explicitly: ask "use the **reviewer** subagent to review the webhooks change".
- Automatically: Claude Code may delegate based on each agent's `description`.

Each subagent starts fresh (no shared memory) and loads context by reading
`.claude/CLAUDE.md`, `.claude/api-map.md`, and `docs/CONFIG.md` — keep those current.

> Note: these agents deliberately **do not commit, tag, or push**. Release stays a
> human-triggered step in the main session (per-package tags, ≤3 tags per push).
