# FluxFiles subagents

Project-scoped Claude Code subagents. Each is a specialist with its own tools + a
system prompt preloaded with FluxFiles' conventions (stateless/JWT-claims grain, the
paid-module gate, i18n/config-doc guards, security pitfalls). Claude Code auto-discovers
these from `.claude/agents/`.

| Agent | Role | Tools | Edits code? |
|---|---|---|---|
| **planner** | Decide *whether/what*: build-in-core vs paid module vs BYO-embed OSS vs don't-build, who pays, v1 scope. No API design. | Read/Grep/Glob/Write/Web | writes a plan doc |
| **spec-writer** | Turn an idea into a concrete design/spec doc (API, claims, storage, security, test plan) that fits the grain — before coding. | Read/Grep/Glob/Write/Web | writes docs only |
| **coder** | Implement a feature/fix following the exact repo patterns (module gate, claims, i18n, hooks). Also runs the post-review fix pass. | Read/Edit/Write/Bash/Grep/Glob | yes |
| **reviewer** | Review a change for FluxFiles-specific security/correctness pitfalls + guard/leak checks. | Read/Grep/Glob/Bash | no (reports) |
| **tester** | Write + run tests in the repo's patterns until the suite is green. | Read/Write/Edit/Bash/Grep/Glob | tests only |

## Suggested workflow for a non-trivial feature

```
planner → spec-writer → coder → tester → reviewer → coder (fix) → (you) commit + release
```

1. **planner** decides whether to build it at all, free/core vs paid vs BYO-embed, who
   pays, and the v1 scope → `.claude/work/plan-<slug>.md`. Stop here if the answer is
   don't-build or embed-instead.
2. **spec-writer** produces the design (claims, endpoints, storage, security, test plan).
3. **coder** implements it (free/core + gitignored private module + adapters + i18n + docs).
4. **tester** writes/locks tests and gets the whole suite green.
5. **reviewer** does a read-only pass (owner_only, SSRF, the module gate, private-module
   leakage, i18n/config-doc guards) and writes numbered blocking/non-blocking findings to
   `.claude/work/review-<slug>.md`.
6. **coder** (fix pass) applies the blocking findings from that file and re-runs the
   guards; reports back by finding number.
7. You (main session) do the commit/release — subagents never commit or release.

The steps run **sequentially, not in parallel**: the reviewer needs a green suite to
review against, and the fix pass needs the reviewer's findings. Working artifacts live in
`.claude/work/` (gitignored) so each step hands off a file instead of re-deriving the diff.

## How to invoke

- **Slash commands** (`.claude/commands/`) — the quick way:
  | Command | Runs |
  |---|---|
  | `/plan <idea>` | planner → build/embed/skip + free-vs-paid decision |
  | `/spec <idea>` | spec-writer → design doc |
  | `/build <what>` | coder → implement |
  | `/test [what]` | tester → write + run tests green |
  | `/review [what]` | reviewer → read-only checklist review + findings file |
  | `/fix [findings]` | coder → apply the blocking findings, re-run guards |
  | `/feature <idea>` | the full plan → spec → build → test → review → fix pipeline |
- Explicitly: ask "use the **reviewer** subagent to review the webhooks change".
- Automatically: Claude Code may delegate based on each agent's `description`.

Each subagent starts fresh (no shared memory) and loads context by reading
`.claude/CLAUDE.md`, `.claude/api-map.md`, and `docs/CONFIG.md` — keep those current.

> Note: these agents deliberately **do not commit, tag, or push**. Release stays a
> human-triggered step in the main session (per-package tags, ≤3 tags per push).
