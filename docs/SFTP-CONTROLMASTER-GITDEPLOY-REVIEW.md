# SSH ControlMaster for GitDeploy — Review

Status: **Analysis only. No code written, none planned.** This is the
separate evaluation `docs/SFTP-CONTROLMASTER-SPEC.md` §19 and
`docs/SFTP-CONTROLMASTER-SECURITY-REVIEW.md` §4.7/§6 both explicitly deferred
("`GitDeploy` should be evaluated separately once real usage data shows
whether its mostly-single-exec pattern benefits enough to justify the same
new state"). It reads as a real evaluation, using the actual shipped
`GitDeploy.php` and its route wiring — not a restatement of the earlier
prediction.

**Recommendation: clear NO-GO. Do not extend `SshMultiplexer` to `GitDeploy`.**
Not "conditional," not "revisit later with usage data" — the mechanics of
`GitDeploy` itself make connection reuse structurally unable to pay for
itself, independent of how often deploys happen. See §2.

---

## 1. What a "deploy request" actually does today

Traced through the live code, not assumed:

`packages/core/api/index.php:1011` — one connection per request:

```php
[$conn, $root] = $diskManager->sftpConnection($disk);
```

`packages/core/api/index.php:1014` — exactly one call into `GitDeploy::run()`:

```php
$result = \FluxFiles\GitDeploy::run($conn, $path, $claims->gitDeployBranch, $claims->gitDeployHooks, $timeout);
```

`GitDeploy::run()` (`GitDeploy.php:122-151`) does not loop or issue several
sequential SSH exec calls. It builds **one shell script string**
(`buildCommand()`, `GitDeploy.php:72-112`) — lock-acquire, liveness check,
`git fetch`/`reset --hard` or `pull --ff-only`, trap-based unlock — and hands
that single string to **one** `$ssh->exec($wrapped)` call
(`GitDeploy.php:127-128`). The lock/lease/sync/unlock sequence is composed
entirely in shell (`if`/`trap`/`&&`), not in PHP round-trips.

So the question the parent task poses — "does the fixed-command-shape
sequence actually issue several execs per trigger that would benefit from
reuse *within* the same request" — has a definite answer: **no.** A deploy
trigger is:

- 1 TCP+SSH handshake (`sftpConnection()`, phpseclib)
- 1 `exec()` channel, running 1 composed shell script
- done

There is no multi-command session to collapse, so the "simpler win" the
parent task floats — reuse only *within* one request's several execs, no
persistent-socket machinery needed — **does not exist either**, because
there's only one exec to begin with. Nothing to batch.

## 2. Would cross-request reuse (the SshTerminal-shaped win) help instead?

That's the only remaining shape ControlMaster could offer here: cache the
handshake so *deploy trigger #2* skips the connect cost *deploy trigger #1*
already paid. Three independent reasons this doesn't clear the bar, not just
one:

**2.1 — Deploys are rare and lock-serialized by design.** `GitDeploy.php`'s
own concurrency model (`.fluxfiles-deploy.lock` directory + PID liveness
check, `GitDeploy.php:29-40`) exists *because* deploys are expected to be
infrequent, human-triggered, one-at-a-time events — "click deploy," wait for
it to finish, click again later (minutes/hours, not seconds/apart). ControlMaster's
`ControlPersist` window in the sibling terminal spec is deliberately short
(60s default, clamped `[10,120]` — `SFTP-CONTROLMASTER-SPEC.md` §6) precisely
to bound the credential-revocation exposure window (F3). A workload whose
natural inter-request gap (minutes+) is routinely larger than any defensible
`ControlPersist` value (seconds) will **almost never** find a live socket to
reuse — it pays the full cold-connect cost on effectively every trigger,
identical to today, while still carrying 100% of F1–F6's risk surface for a
reuse rate close to zero. This is exactly what the original review's §5
performance summary already flagged in the abstract ("a workload that only
ever issues one command per session ... sees roughly no benefit and pays
100% of the new state's risk for it") — this doc confirms it's not merely
likely but the *designed* usage pattern, straight from `GitDeploy`'s own
lock semantics.

**2.2 — With `host_fingerprint` pinned (the hardened, recommended config —
and mandatory if `require_host_key` is on), a cold multiplex connect is
strictly *worse* than today, not neutral.** `SFTP-CONTROLMASTER-SPEC.md` §9
documents this plainly for the terminal case: because a fingerprint can't be
turned back into raw key bytes for an OpenSSH `known_hosts` line, a cold
multiplex connect first pays a full phpseclib handshake just to extract an
already-verified host key, *then* pays the OpenSSH `-M` handshake — two
handshakes where today's `GitDeploy` pays one. Given §2.1's finding that a
deploy trigger essentially never lands within a live `ControlPersist`
window, **every single deploy would pay this doubled cost with no
amortization ever arriving to offset it.** This isn't a corner case for
`GitDeploy` — it's the expected outcome for exactly the security-hardened
configuration an operator running one-click deploy against a production VPS
should be using.

**2.3 — The absolute time saved doesn't move a human-perceived metric.** An
SSH/SFTP handshake is typically 100–300ms (the review's own §5 number). A
`git fetch`/`reset --hard` deploy — the actual dominant cost of "click
deploy" — routinely takes seconds to tens of seconds depending on repo size,
network, and whether LFS/submodules are involved (`FLUXFILES_GIT_DEPLOY_TIMEOUT`
defaults to **120s**, `index.php:1013`, specifically because the git
operation itself, not the handshake, is the long pole). Shaving ~200ms off
an operation whose own default timeout budget is two minutes is not a
perceptible win for the "click a button, watch it deploy" UX this feature
serves — unlike `SshTerminal`, where a human issuing several quick commands
in a row *does* perceive the per-command handshake tax.

## 3. Findings (why F1–F6 aren't worth reopening here)

| # | From the security review | Applies to GitDeploy? | Why |
|---|---|---|---|
| F1 (cache-key collision across tenants) | Critical | Would still apply if built | No mitigation specific to GitDeploy — same cache-key discipline would be needed, for zero offsetting benefit (§2). |
| F2 (local socket, no re-auth) | High | Would still apply if built | Same. |
| F3 (ControlPersist outlives a revoked credential) | High | **Worse here than for terminal** | A deploy path controls production release commands; keeping *any* window open where a rotated deploy-key credential still works is a worse trade for a "click deploy" action than for an interactive debugging terminal. |
| F4 (password/passphrase → argv exposure) | Medium | N/A if gated correctly | `multiplexEligible()`'s key-based-only gate (already shipped, shared code) would apply unchanged — but see §2.2: even the *eligible* (key-based, no passphrase) case is a net loss for GitDeploy specifically. |
| F5 (OpenSSH stack diverges from phpseclib's `require_host_key`/`strict_algorithms`) | Medium | Would still apply if built | Same porting burden as the terminal case, again for a workload that never benefits (§2.1). |
| F6 (unbounded socket/process growth) | Medium | Low realistic exposure, but irrelevant | Low deploy volume means F6 is unlikely to bite in practice — but this isn't a point in favor of building it; a risk that merely fails to materialize isn't the same as a benefit that does. |
| F7 (new persistent state, architecturally) | Low/architectural | **Fully applies, with zero offsetting win** | This is the crux: F7 is "worth it" for `SshTerminal` because §2's math (many commands, short natural gaps, human perceives the latency) pays for the new state. None of that math holds for `GitDeploy` (§2.1–§2.3). Introducing the *same* new persistent-state surface for *zero* expected benefit is a pure risk addition. |

## 4. Recommendation

**NO-GO, unconditionally — not "revisit if usage grows."** The reason isn't
that deploys are currently low-frequency (a fact that *could* change); it's
that `GitDeploy`'s fixed shape is **one exec per trigger**, and its natural
trigger cadence (human-driven, lock-serialized, minutes-to-hours apart) will
essentially never fall inside any `ControlPersist` window short enough to be
defensible against F3. Higher deploy frequency wouldn't fix this — a CI
pipeline redeploying every few seconds is exactly the scenario `GitDeploy`'s
own lock design and rate limit (`FLUXFILES_GIT_DEPLOY_RATE_LIMIT`, default
**5/min**, `index.php:399`) are built to prevent, not enable. There is no
realistic usage pattern for this specific feature where the reuse condition
(`ControlPersist` window still open at the next trigger) is met often enough
to justify F1–F7's risk. `SFTP-CONTROLMASTER-SPEC.md` §19's boundary is
correct and should stay exactly where it is.

`GitDeploy.php` and its route in `index.php` remain untouched. No claim,
config key, or env var is added by this review.

## 5. What *would* actually speed up GitDeploy (if that's ever the ask)

Since this doc is asked to be concrete rather than merely negative: the
dominant cost of a deploy is the `git fetch`/`reset --hard` itself (seconds
to tens of seconds), not the ~100–300ms SSH handshake. If deploy latency
becomes a real complaint, the productive places to look are shallow-fetch
tuning (`git fetch --depth=1 --prune` when history isn't needed),
`git gc`/repack hygiene on the target repo, or (bigger, deliberately
out-of-scope per `docs/GIT-DEPLOY-SECURITY-REVIEW.md` F5) a symlink-swap
release model — none of which is "reuse the SSH connection," and none of
which this doc proposes building now.
