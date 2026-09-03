# SSH ControlMaster (connection reuse) — Security + Performance Review

Status: **Pre-implementation review.** No code has been written for this
feature. `docs/ROADMAP.md` line 240 currently documents the SFTP driver as
"connect per request, no pool" — a deliberate choice, restated as a
principle in `docs/GIT-DEPLOY-SECURITY-REVIEW.md` F8 ("Do not add connection
pooling ... it would be new persistent state this codebase has deliberately
avoided everywhere"). This doc is the dedicated review that principle
implies must exist before that line is crossed. It does not replace a design
spec — if the recommendation below is accepted, a follow-up spec (exact
claim/config names, socket path layout, the OpenSSH flag set) is still
needed before coding, per this repo's usual spec-writer → coder → tester
flow.

**Recommendation up front: conditional go, narrowly scoped.** ControlMaster
is a real fix for a real problem (SSH terminal / Git deploy currently pay a
fresh TCP+SSH handshake on every single command, unlike a human's multiplexed
Terminal session), but it is a categorically different kind of change than
Git deploy was. Git deploy's review (§1 there) found it *reused* a privilege
FluxFiles already granted (`allow_terminal`). ControlMaster instead
*introduces new persistent state* — an OS process and a Unix domain socket
file living on the app server between requests — into a codebase whose
threat model was built entirely around "nothing survives past the request
that created it." §4 gives the constraints that keep that new state narrow
and correctly scoped instead of becoming a second, weaker credential store.

---

## 1. Key finding: this is not a Git-deploy-shaped problem

Every hardening feature this repo has shipped for SFTP so far —
`host_fingerprint` pinning, `require_host_key`, `strict_algorithms`
(commit 817ad56) — assumes exactly one code path builds the connection:
`DiskManager::buildSftpProvider()`, feeding phpseclib3's
`SftpConnectionProvider`. That assumption is why those features could be
implemented once and apply everywhere (Flysystem browsing, `SshTerminal`,
`GitDeploy`, chmod) for free.

SSH ControlMaster/ControlPersist is an **OpenSSH-client-specific** feature.
phpseclib3 is a pure-PHP re-implementation of the SSH protocol and has no
concept of it — there is no way to get multiplexing without shelling out to
the real `ssh`/`sftp` binary via `proc_open`. That means adopting it doesn't
extend the existing hardening path, it **forks** it: FluxFiles would run two
independent SSH client stacks side by side, each with its own trust store,
its own algorithm negotiation, and its own credential-handling surface. Every
finding below traces back to that fork, not to a flaw in ControlMaster
itself (which is a mature, widely-trusted OpenSSH feature — this review is
about bolting it onto FluxFiles' specific multi-tenant, BYOB, claim-scoped
model, not about ControlMaster being unsafe in general).

**What's actually being protected:** the same target VPS/host `/api/fm/terminal`
and `/api/fm/git-deploy` already expose when granted, plus — newly, because
ControlMaster's state lives in the app server's own filesystem/process
table — the **app server's local trust boundary between tenants**, which
today is trivially maintained by every SSH session being fully independent
and short-lived.

## 2. Threat model

**Actors:**
- The **operator** — configures disks (including BYOB), decides which
  claims to grant. Same trust tier as everywhere else in this codebase.
- A **token holder** — may hold a JWT scoped to one BYOB SFTP disk among
  many hosted by the same FluxFiles installation (e.g. a SaaS operator
  running FluxFiles once, serving many end customers' own VPSes via BYOB).
  This is the actor multi-tenancy findings below are about.
- **Any other local process on the app server** — a consideration that is
  new to this feature specifically. Every other SFTP hardening feature only
  had to reason about network attackers and JWT holders; a Unix domain
  socket file is a *local* filesystem object, so a co-located process
  (another app on a shared host, a different worker, a compromised
  lower-privilege process) becomes a relevant actor for the first time.

**What's actually being protected:** (a) the target SFTP/SSH hosts, same as
today; (b) **isolation between BYOB tenants sharing one FluxFiles
installation** — today guaranteed structurally (every connection is built
fresh, per-request, from that request's own decrypted credentials, so two
tenants' sessions can never touch); ControlMaster's entire value
proposition is reusing a session across requests, which only stays safe if
reuse is scoped at least as precisely as "the exact same decrypted
credential," never merely "the same host."

## 3. Findings

| # | Severity | Finding | Scenario |
|---|---|---|---|
| F1 | **Critical** | If a reused control socket is keyed on anything looser than the actual credential material — e.g. `host:port:username` alone — two different BYOB tenants (or a BYOB tenant and the operator's own static config) that happen to share a host/port/username end up **sharing one another's authenticated SSH session**. `CredentialEncryptor::validate()` doesn't restrict who can configure a given host/username pair, so this isn't a contrived edge case. | Two customers of the same SaaS operator both point their BYOB config at the same reseller-hosting IP with the same generic `username` (common on cheap shared VPS panels) but different passwords/keys. Tenant B's terminal command runs inside tenant A's already-open session. |
| F2 | **High** | The control socket is a Unix domain socket **file on the app server's local disk**. Any local process that can connect to that socket path can issue commands through the multiplexed session with no further authentication (that's the entire point of ControlMaster — the handshake already happened). If the socket directory or file permissions are looser than "only this PHP-FPM worker's UID," a different local process gains the same access as a valid, authenticated request. | The socket lives under a shared, world-readable temp directory (or a directory whose permissions weren't deliberately set) on a host running more than one application; another local process connects to the socket and rides the open session. |
| F3 | **High** | `ControlPersist` deliberately keeps the underlying authenticated session alive for a fixed duration **after** the connection that opened it closes — that is its whole mechanism. Every other claim-based gate in this codebase (`allow_terminal`, `allow_sftp`, a BYOB credential rotation, a short-TTL JWT expiring) takes effect on the *next request* today, because there is no lingering session to still trust. ControlPersist reopens exactly the window every other gate is designed to close instantly: a revoked claim or rotated credential doesn't actually cut off access until the persist timeout elapses. | An operator rotates a compromised SFTP password in `disks.php`/BYOB config, or a token's `allow_terminal` claim is pulled from the next-minted JWT. An already-open control socket (e.g. `ControlPersist=10m`) keeps accepting commands under the old, supposedly-revoked credential for up to that long. |
| F4 | **Medium** | Password-based SFTP auth has no interactive terminal to type into under `proc_open`, so shelling out to real `ssh`/`sftp` with a password requires either a PTY-emulation wrapper or a helper like `sshpass -p<password>` — which places the **plaintext password directly in that process's argv**, visible to any local user via `ps aux` / `/proc/<pid>/cmdline` for the process's lifetime. phpseclib's current password handling never touches argv or a separate OS process at all — this is a strictly worse exposure than what exists today. | A shared-hosting box runs FluxFiles alongside other tenants' processes; any of them can run `ps aux` and read a live SFTP password out of the `sshpass` invocation. |
| F5 | **Medium** | `require_host_key`/`strict_algorithms` (817ad56) are implemented entirely against phpseclib's `SftpConnectionProvider` — a `hostFingerprint` check and a `preferredAlgorithms` allowlist that only that code path consults. A ControlMaster path shells to the **system** `ssh` binary, which has its own, entirely separate trust store (`~/.ssh/known_hosts` or an explicit `-o UserKnownHostsFile=`) and its own algorithm negotiation (`-o KexAlgorithms=/Ciphers=/MACs=` or `ssh_config`). Nothing connects the two: a disk configured with `require_host_key: true, strict_algorithms: true` gets neither guarantee on the ControlMaster path unless each is independently re-implemented for OpenSSH's flag syntax and kept in sync by hand going forward. | An operator enables `strict_algorithms` expecting the legacy-cipher exclusion documented in `.env.example`; the ControlMaster path (if it doesn't independently enforce the same allowlist) negotiates whatever the system `ssh`'s own defaults allow, silently reintroducing the exact legacy algorithms 817ad56 was written to exclude. |
| F6 | **Medium** | A control socket is a live OS process (`ssh -M`) plus an open file descriptor, held for `ControlPersist`'s duration, per distinct cached credential (per F1's fix). An installation serving many BYOB tenants — or a single malicious/compromised token able to mint or trigger connections against many distinct configured hosts within its rate limit — causes unbounded growth of lingering processes and socket files on the app server, a resource-exhaustion path that today's per-request phpseclib connections structurally cannot have (they terminate the moment the request finishes, nothing lingers). | Many distinct BYOB SFTP configs (one per end-customer) are each used once; each opens and persists its own control socket for the full `ControlPersist` window, and the app server's process table / open-fd count climbs with tenant count rather than with concurrent request count. |
| F7 | **Low (architecture)** | This is precisely the new persistent, process-lifetime server state `docs/GIT-DEPLOY-SECURITY-REVIEW.md` F8 flagged as a line not to cross, and the repo's own Working Rules restate directly ("Do not add new stateful server dependencies unless the task explicitly changes the stateless/BYOB direction"). ControlMaster doesn't avoid that by being "OS-managed instead of PHP-managed" — it's still a cache with a lifetime that outlives the request, held by the app server, keyed on tenant-supplied credentials. Every finding above is a direct consequence of introducing that cache; none of them exist in the current connect-per-request model. | N/A — an architectural note: any implementation that treats this as "just a performance tweak" rather than "a new, first stateful credential cache" will under-scope the review of F1–F6. |

## 4. Required design constraints (if built)

These are what keep the new cache narrow and correctly scoped instead of
becoming a second, weaker credential store, addressing F1–F7 in order:

1. **Cache/socket key = a hash of the full resolved credential material,
   never `host:port:username` alone.** Derive the key as e.g.
   `hash('sha256', $host.":".$port.":".$username.":".$password_or_privateKey.":".$passphrase)`
   — the same fields `CredentialEncryptor` already decrypts — so two
   configs that differ in *any* secret material can never collide on the
   same socket, regardless of how many tenants share a host/username.
   Closes F1.
2. **Socket directory mode 0700, owned by the PHP-FPM worker's UID, under a
   FluxFiles-private runtime path** (alongside the existing
   `FLUXFILES_STORAGE_PATH` state, not a shared world of `/tmp`), and the
   socket filename itself must be the credential hash from #1 (or a further
   hash of it) — never a predictable `host-username` string — so its
   existence doesn't leak which hosts/users are configured to another local
   process that can list the directory. Closes F2.
3. **`ControlPersist` capped short (seconds, not minutes — e.g. 30–60s) and
   explicitly torn down (`ssh -O exit -S <socket>`) whenever the disk config
   that produced it changes.** FluxFiles has no push-based revocation
   channel today (JWT validity is exp-based, not centrally revocable), so
   this doesn't eliminate F3's window — it bounds it to roughly the same
   order of magnitude as an already-accepted short-lived-token assumption,
   and it must be **documented as an accepted, bounded trade-off** in the
   eventual design spec, the same way Git deploy's F5 (non-atomic deploy)
   was documented rather than solved. Loosening this default in a future
   change reopens F3 and should come back through this review.
4. **Key-based auth only on the ControlMaster path.** If a disk config has
   only a password (no `private_key`), the ControlMaster path must refuse to
   engage and the connection falls back to the existing per-request
   phpseclib path unchanged. This closes F4 outright rather than mitigating
   it — no `sshpass`, no wrapper, no plaintext password ever reaches a
   process's argv or environment.
5. **Port `require_host_key` and `strict_algorithms` to the OpenSSH flag
   syntax as a single source of truth, not a second hand-maintained list.**
   `require_host_key` → `-o StrictHostKeyChecking=yes -o UserKnownHostsFile=<a
   per-disk file populated only from the disk's own pinned `host_fingerprint`,
   never the ambient system-wide `~/.ssh/known_hosts`>`. `strict_algorithms`
   → `-o KexAlgorithms=... -o Ciphers=... -o MACs=... -o HostKeyAlgorithms=...`
   built by translating the exact same allowlist
   `DiskManager::modernSshAlgorithms()` already produces for phpseclib, in
   one place, so a future change to the allowlist can't update one stack and
   forget the other. Add a test asserting both translations stay derived
   from the same source list. Closes F5.
6. **Cap total concurrently-open control sockets server-wide** (an LRU: the
   oldest/least-recently-used socket is torn down via `ssh -O exit` before a
   new distinct credential-hash would exceed the cap) **in addition to**
   the short `ControlPersist` from #3, so idle sockets both self-expire and
   are bounded in count regardless of tenant volume. Closes F6.
7. **Scope this to exactly the interactive/multi-call-per-session paths —
   `SshTerminal`/`GitDeploy`'s raw `exec()` usage — and explicitly do NOT
   extend it to the Flysystem SFTP adapter used for browsing/upload/download.**
   That path is the highest-request-volume one, phpseclib already performs
   adequately there (each call is a single round trip, not a session of many
   sequential commands the way an interactive terminal is), and it's exactly
   where getting F1's cache key wrong would have the largest blast radius.
   Treat this constraint as the explicit, reviewed exception to "no new
   stateful server dependencies" — not a precedent for pooling elsewhere.
   Closes/bounds F7 by keeping the new state surface as small as the actual
   performance problem requires.

## 5. Performance summary (as asked)

- **What this actually fixes**: the SSH/SFTP handshake cost (typically
  100–300ms+ on a healthy link, more over higher latency or with a slow KEX)
  currently paid on **every single** `SshTerminal`/`GitDeploy` call, because
  each is its own fresh `DiskManager::sftpConnection()`. A multi-command
  terminal session today re-pays that cost per command; ControlMaster (per
  §4.7's scope) collapses it to once per `ControlPersist` window, which is
  the actual "macOS Terminal feels instant" behavior being asked for.
- **What this does not touch**: file browsing/list/upload/download via the
  Flysystem SFTP adapter is explicitly out of scope (§4.7) — that path stays
  on the existing per-request phpseclib model, unchanged in behavior or
  risk profile.
- **New fixed costs**: spawning `ssh -M` the first time in a window is not
  faster than today's phpseclib connect — the win only appears on the 2nd+
  call within the same `ControlPersist` window. A workload that only ever
  issues one command per session (many git-deploy triggers, for instance)
  sees roughly no benefit and pays 100% of the new state's risk for it —
  worth weighing when deciding whether `GitDeploy` (mostly one exec per
  trigger) actually needs this versus `SshTerminal` (many execs per
  interactive session, the actual motivating case).
- **This is new persistent state**, unlike every other "stays v1-sync"
  feature in this repo (Git deploy, URL import, Optimize) that runs to
  completion and leaves nothing behind — the honest performance framing is
  "trade zero lingering state for lower per-command latency," not "a free
  speedup."

## 6. Recommendation

**Conditional go, narrowly scoped**: key-based auth only, credential-hashed
socket keys, short capped `ControlPersist` with an LRU cap on open sockets,
both existing hardening flags ported to their OpenSSH equivalents from one
source list, and limited to `SshTerminal` (the actual multi-command-per-session
case) — `GitDeploy` should be evaluated separately once real usage data
shows whether its mostly-single-exec pattern benefits enough to justify the
same new state.

If a future implementation is tempted to loosen any of §4's constraints —
password auth over the shelled-out path, a longer `ControlPersist`, a
host/user-only cache key "for simplicity," or extending this to the
Flysystem browsing path "since it's already built" — that reopens the exact
findings above and should come back through this review before shipping.

**Before coding**: write the actual design spec (exact config/claim names,
the socket-path layout, the precise OpenSSH flag set per §4.5, the LRU
eviction policy) via the normal spec-writer flow, using §4 as its constraint
list — matching how `docs/GIT-DEPLOY-SECURITY-REVIEW.md` was used for Git
deploy.
