# One-Click Git Deploy (SSH exec) — Security + Performance Review

Status: **Pre-implementation review.** No code has been written for this
feature. This doc is the "dedicated security review" `docs/ROADMAP.md`'s
`⏸️ LATER / conditional` section requires before anyone builds
`allow_ssh_exec` / one-click Git deploy. It does not replace a design spec —
if the recommendation below is accepted, a follow-up spec (API shape, JWT
claims, storage layout) is still needed before coding, per this repo's usual
spec-writer → coder → tester flow.

**Recommendation up front: conditional go**, but not as originally framed.
The roadmap describes this as introducing "a big new attack surface." That
premise is now **stale** — see §1. The real feature, once correctly scoped,
is a *narrower* capability than something FluxFiles already ships today
(`/api/fm/terminal`), not a categorically new one. §4 gives the constraints
that make it narrower rather than a second arbitrary-shell door.

---

## 1. Key finding: the "new attack surface" already shipped

`docs/ROADMAP.md`'s `⏸️ LATER` entry says:

> One-click Git deploy (SSH exec) — Runs arbitrary commands on the tenant's
> VPS — `allow_ssh_exec` is a big new attack surface — Do only after a
> dedicated security review; separate claim, never bundled with `allow_sftp`

That was written before the SSH terminal shipped. Today, `POST
/api/fm/terminal` (`packages/core/api/index.php:915-956`,
`SshTerminal::run()`) already grants **fully arbitrary shell exec** on any
SFTP disk, gated by:

- `FLUXFILES_TERMINAL_DISABLED` (server kill-switch),
- `allow_terminal` claim,
- `write` permission,
- the disk being SFTP,
- a double-confirm for a short deny-list of catastrophic patterns
  (`SshTerminal::isDangerous()` — an accident guardrail, explicitly **not** a
  security boundary per its own docblock at `SshTerminal.php:18-23`).

`git pull` (or `git fetch && git reset --hard origin/main`, or literally
anything else) is just an unremarkable string to that endpoint today. Any
operator who has already decided to grant `allow_terminal` to a token has
already granted git-deploy-or-worse. **There is no new privilege class to
invent here** — the actual design question is narrower:

> Should FluxFiles ship a *dedicated, constrained* deploy action that a
> token can be granted **without** also granting `allow_terminal`'s full
> arbitrary-shell power — and if so, what specifically must be constrained
> so that it's a subset of terminal's risk, not a second copy of it?

That reframing drives every recommendation below. (Action: `docs/ROADMAP.md`
should be updated to note this once a decision is made — it currently
misdescribes the baseline risk.)

## 2. Threat model

**Actors:**
- The **operator** — mints tokens, decides which claims to grant. Trusted to
  configure the feature correctly, not trusted to never make a mistake (a
  leaked or over-scoped token is the normal failure mode this repo designs
  against everywhere else — see `owner_only`, path-prefix scoping, BYOB SSRF
  checks).
- A **token holder** — whoever ends up holding a JWT with the new claim,
  possibly not the operator (embedded in a CMS plugin, a CI pipeline, a
  client-facing dashboard).
- **Whoever controls the Git remote** — for a self-hosted git server or a
  fork-based workflow this may not be the operator. This is the actor the
  current terminal feature doesn't have to think about (a shell command has
  no separate "remote content" dimension); Git deploy does.

**What's actually being protected:** the target VPS the SFTP disk points at
— the same asset `/api/fm/terminal` already exposes when granted. Adding
Git deploy must not make it *easier* to compromise that VPS than granting
`allow_terminal` already does; ideally it should require strictly less
trust.

## 3. Findings

| # | Severity | Finding | Scenario |
|---|---|---|---|
| F1 | **Critical** | If the deploy command accepts an operator- or request-supplied **remote URL** at request time, a malicious or compromised remote can achieve RCE via crafted `--upload-pack=`/`ext::` transport strings or a malicious `.git/hooks/post-merge` script that `git pull`/`merge` executes automatically on the target host. This is a known class (`git clone --upload-pack='sh -c "…"'`, CVE-2017-1000117-style argument injection when a URL is attacker-influenced). | A token with the new claim is tricked (or is itself malicious) into pointing the "deploy" action at an attacker-controlled or attacker-writable remote. |
| F2 | **High** | `git pull`/`merge` runs the target repo's `post-merge` hook (and `pre-merge-commit`, etc.) **unconditionally** — arbitrary code execution under the SSH user's OS permissions, independent of any command-injection bug in FluxFiles' own code. This is inherent to Git, not a FluxFiles bug, but must be explicitly neutralized or explicitly accepted. | A contributor with push access to the deployed repo (not necessarily anyone with a FluxFiles token) commits a hostile hook; the next one-click deploy executes it on the VPS. |
| F3 | **High** | Reusing string-concatenation for the git command (the same pattern `SshTerminal::run()` uses deliberately, since arbitrary shell *is* the point there) is wrong for a "constrained" action — any request-supplied branch/ref/path that isn't shell-escaped is a straight command-injection vector, and this feature is precisely the one where the design intent is "not arbitrary shell." | `branch` field in the request body contains `` main; rm -rf / `` or a backtick/`$()` payload. |
| F4 | **Medium** | Git-remote credentials (deploy key or PAT) are a **separate secret** from the SFTP disk's own credentials (the SFTP disk authenticates to the *target VPS*; the git remote authenticates to *GitHub/GitLab/etc.*). If this secret is threaded through a JWT claim the way BYOB SFTP credentials are, it inherits BYOB's "decrypt only at runtime, never logged" posture — but if a future implementation takes a shortcut (e.g., accepting a PAT in the request body for convenience), it lands in `audit.jsonl`'s `$auditDetail` (§ index.php:429-430 logs the terminal command verbatim) or a rate-limiter bucket key, both of which are far less guarded than a JWT. | A PAT passed as a request param gets written into the audit log's free-form detail field, which `audit-export` (a *paid, separate* module) can dump wholesale. |
| F5 | **Medium** | No atomicity: `git pull`/`reset --hard` on a **live webroot** is not an atomic swap. PHP-FPM/nginx can serve a request mid-pull and see a half-updated tree (missing files, a partially-written file, or a broken include chain) — a real availability blip, not just a style nit. Naive git-based deployers hit this; the standard fix is a symlink-swap release directory (Capistrano/Deployer-style), which is out of scope for a v1 "click a button, run one command" feature. | A deploy lands while traffic is live; a handful of requests 500 or serve a half-written PHP file during the pull window. |
| F6 | **Medium** | No serialization: two overlapping deploy triggers (double-click, two tokens, a retry after a client-side timeout) can run concurrent `git` processes against the same working tree — index-lock contention at best, a corrupted merge at worst. | Two admins click "Deploy" within the same few seconds. |
| F7 | **Low** | Unbounded duration/output: a slow network, a huge repo, or Git LFS content can make `git pull` run far longer than a typical shell command, and a verbose/conflicted pull can produce megabytes of output — same shape of problem `SshTerminal::MAX_OUTPUT` (2 MB) and its per-command timeout already solve for the general terminal, but a *deploy-specific* timeout default needs its own number (terminal's 30s default is almost certainly too short for a `git pull` cold-cloning submodules). | A `git pull` against a repo with LFS assets takes 90 seconds; the request times out client-side while the process keeps running server-side (same class of "did it actually happen" ambiguity `import-url` and `optimize` already have to document). |
| F8 | **Low (performance)** | SFTP/SSH connections are **per-request, no pool** by deliberate design (`DiskManager.php:186-187`, shared by list/upload/chmod/terminal today). A deploy action is low-frequency by nature, so this cost is a non-issue for the action itself — flagged only so nobody "fixes" it by adding a persistent SSH connection pool, which would be new process-lifetime state this repo has consistently avoided (see `RateLimiterFileStorage`, `_fluxfiles/` sidecars — everything is stateless-per-request or storage-resident, never an in-memory pool). | N/A — this is a "don't regress the architecture" note, not a bug. |

## 4. Required design constraints (if built)

These are what turn "a second arbitrary-shell door" into "a genuinely
narrower action," addressing F1–F7 in order:

1. **Fixed target, operator-configured, never client-supplied.** The repo
   path *and* the git remote are baked into the JWT claim at mint time —
   exactly the `path_prefix`/`allowedDisks` pattern already used everywhere
   else in this codebase — never accepted from the request body. The
   request body may carry at most a `disk` selector (already scoped) and
   nothing else identifying *where* or *what* to pull. This closes F1 and
   most of F3: there is no attacker-reachable input that chooses the remote.
2. **One fixed command shape, not a command string.** The server assembles
   `git -C <configured-path> fetch --prune origin && git -C <configured-path>
   reset --hard origin/<configured-branch>` (or `pull --ff-only`, TBD in the
   follow-up spec) from claim-supplied, non-shell values only, using
   `escapeshellarg()` on each piece — never string-concatenating a
   client-supplied fragment into the command the way `SshTerminal::run()`
   deliberately does for the general terminal. No free-form `cmd` field at
   all. Closes F3.
3. **Neutralize hooks.** Run with `-c core.hooksPath=/dev/null` (or
   equivalent) so a hostile `post-merge` hook in the deployed repo cannot
   execute, unless an operator explicitly opts in via a separate claim
   (`allow_git_hooks`, default off) for the rare case they actually want
   hook-driven deploys (e.g., `composer install` on merge) and understand
   the trade-off. Document the default and the opt-out prominently — this
   is the one item here that is genuinely specific to Git deploy and has no
   analogue elsewhere in the codebase. Closes F2 by default.
4. **A dedicated claim, never bundled with `allow_sftp` or `allow_terminal`.**
   Matches the roadmap's own instinct. Concretely: `allow_git_deploy` (bool,
   default false) is independent — a token can have `allow_sftp` +
   `allow_terminal` without `allow_git_deploy`, or vice versa. This keeps
   the principle-of-least-privilege story intact: an operator who only wants
   "let this CI token redeploy the site" never has to also hand out a full
   shell.
5. **No secrets in the request/response/audit path.** If a deploy needs
   remote-auth (private repo), that credential lives in the **disk config**
   (server-side `.env`/`disks.php`, same trust tier as the SFTP
   password/private key already there), never in a claim payload that
   travels to a browser, never in a request body, and the audit-log detail
   field logs the branch/ref actually deployed — never a raw command string
   that could embed a credential. Closes F4.
6. **A lock file over the same SFTP connection**, e.g.
   `<repo-path>/.fluxfiles-deploy.lock` written before running and removed
   after (or a short-TTL marker with an age check) — a second concurrent
   trigger is rejected with a `deploy_in_progress` error rather than racing.
   This is storage-resident state, consistent with how the rest of the app
   avoids a DB (compare `_fluxfiles/trash.json`, `audit.jsonl`'s file lock).
   Closes F6.
7. **A deploy-specific timeout and output cap**, separate env vars from
   `FLUXFILES_TERMINAL_*` (e.g. `FLUXFILES_GIT_DEPLOY_TIMEOUT`, suggested
   default 120s not 30s; reuse `SshTerminal::MAX_OUTPUT`'s 2 MB truncation
   pattern for the response). Document plainly, the way `import-url` and
   `optimize` already do, that a client-side timeout doesn't guarantee the
   server-side `git` process stopped. Closes F7.
8. **Document, don't solve, F5 (non-atomic deploy).** A symlink-swap
   release model is a legitimate v2 idea but is a materially bigger feature
   (needs a "current release" pointer, a releases directory, cleanup of old
   releases) — out of scope for a v1 "click a button" action. State the
   limitation in the eventual design doc the same way the S3-share
   grant-vs-download-count limitation or Webhooks' at-most-once delivery are
   stated elsewhere: explicitly, so it's a documented trade-off and not a
   silently-discovered gap.
9. **Rate limit tighter than the general write bucket.** Reuse
   `RateLimiterFactory`, but with its own bucket (e.g. `git_deploy:<jti-or-
   userId>`) and a low default (suggest 3–5/min) — a live webroot being
   `reset --hard`'d ten times a minute is never legitimate traffic, unlike
   generic write operations.
10. **Keep the existing guardrails that already generalize**: server
    kill-switch (`FLUXFILES_GIT_DEPLOY_DISABLED`, mirroring
    `FLUXFILES_TERMINAL_DISABLED`), `write` permission required, SFTP-disk
    only, full audit-log entry (branch/ref, disk, exit code, duration).

## 5. Performance summary (as asked)

- **SFTP/SSH connection cost**: per-request connect/disconnect, no pooling —
  unchanged from the model `list()`/chmod/terminal already use today. For a
  low-frequency action like "click deploy," one SSH handshake's latency
  (typically well under a second) is immaterial. **Do not add connection
  pooling for this feature** — it would be new persistent state this
  codebase has deliberately avoided everywhere (see F8).
- **Command duration**: needs its own, longer timeout than the terminal's
  30s default (§4.7) — `git fetch`/`reset --hard` on a cold or LFS-heavy
  repo can legitimately take longer than an interactive shell command.
- **Output volume**: reuse the terminal's 2 MB truncation pattern; git
  output is normally small, but a merge-conflict dump or a misconfigured
  `-v` could otherwise balloon a response.
- **Concurrency**: no built-in serialization exists anywhere in this
  codebase for a "long-running SSH command" — the deploy lock file (§4.6) is
  new, minimal, storage-resident state, not a new architectural pattern.
- **This stays v1-sync**, matching every other "no-DB" feature in this repo
  (Zip/Extract, URL import, Optimize) — no job queue, no polling endpoint,
  one request in, one response out, explicitly per the roadmap's own
  DROP-list rationale for why an async model isn't attempted without a DB.

## 6. Recommendation

**Conditional go**, scoped exactly as §4 constrains it: a fixed-target,
fixed-command-shape, separately-claimed, hook-neutered, rate-limited,
lock-serialized action — not a general "run any git command" feature, and
not bundled with `allow_sftp`/`allow_terminal`. Under that scope, the net
new risk over what `/api/fm/terminal` already ships today is low: the
attacker-reachable surface (F1, F3) is closed by removing all
client-supplied targeting, and the Git-specific risk (F2) has a documented,
default-on mitigation.

If a future implementation is tempted to add flexibility back in — a
client-supplied branch, a client-supplied remote, a free-form command field
"for power users" — that reopens F1/F3 and the feature reverts to being
exactly the second arbitrary-shell door the roadmap originally worried
about. Any such request should come back through this review.

**Before coding**: write the actual design spec (claim names/shapes, the
exact assembled command, the lock-file format, the new env vars) via the
normal spec-writer flow, using §4 as its constraint list.

---

## 7. Implementation status — DONE (2026-09-03)

Implemented per §4, free/core (not a paid module — same tier as
`allow_terminal`/`allow_chmod`/`allow_code_edit`):

- `packages/core/api/GitDeploy.php` — `buildCommand()`/`run()`. Fixed command
  shape only, every variable piece `escapeshellarg()`'d (§4.2). Hooks
  neutered by default via `core.hooksPath=/dev/null` (§4.3). `mkdir`-based
  lock directory inside the repo path on the SFTP disk itself, staleness
  check via portable `find -mmin` (§4.6). 2 MB output cap, same pattern as
  `SshTerminal::MAX_OUTPUT` (§4.7 output half).
- `packages/core/api/Claims.php` — 4 new claims: `allow_git_deploy` (bool,
  default false, dedicated — never bundled with `allow_sftp`/`allow_terminal`,
  §4.4), `git_deploy_path` (string, operator-set, never request-supplied,
  §4.1), `git_deploy_branch` (string, restricted to `[A-Za-z0-9._/-]+` —
  anything else drops to empty before it ever reaches git), `git_deploy_hooks`
  (bool, default false, §4.3's opt-in).
- `packages/core/api/index.php` — `POST /api/fm/git-deploy` route: kill-switch
  `FLUXFILES_GIT_DEPLOY_DISABLED`, `allow_git_deploy` claim, `write` perm,
  SFTP-disk-only, dedicated rate bucket `FLUXFILES_GIT_DEPLOY_RATE_LIMIT`
  (default 5/min, §4.9), dedicated timeout `FLUXFILES_GIT_DEPLOY_TIMEOUT`
  (default 120s, §4.7 timeout half), full audit-log entry logging
  `path@branch` (§4.5, §4.10). Remote credentials are never touched by
  FluxFiles at all — the sync always uses the repo's own pre-configured
  `origin` already set up on the VPS (closes F1/F4 structurally rather than
  mitigating them, per §6).
- F5 (non-atomic deploy onto a live webroot) is documented, not solved, per
  §4.8 — no symlink-swap release model in this v1.
- Tests: `packages/core/tests/unit/test-git-deploy.php` (command shape,
  escaping, hook neutralization, lock scoping, claim decode/defaults, branch
  regex rejection). `docs/CONFIG.md` §2.2/§3 updated (required for
  `tests/unit/test-config-doc.php`); all 16 `lang/*.json` got the 4 new
  `error.git_deploy_*` keys (required for `tests/unit/test-i18n.php`).
- **Not done**: Laravel/WordPress proxy adapter parity (unlike `/terminal`,
  which both proxies already forward). Follow-up if operators need it there.
