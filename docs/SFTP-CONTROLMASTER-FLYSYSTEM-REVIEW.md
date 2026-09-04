# SSH ControlMaster for the Flysystem SFTP Adapter — Review

Status: **Analysis only. No code written, none planned.** This is the
separate evaluation `docs/SFTP-CONTROLMASTER-SECURITY-REVIEW.md` F7 required
before ever considering the browsing/upload/download SFTP path: "explicitly
do NOT extend it to the Flysystem SFTP adapter ... without separate
evaluation." It reads as that evaluation, tracing the actual phpseclib/
Flysystem code paths involved — not a restatement of the earlier caution.

**Recommendation: clear NO-GO.** Two independent reasons, either one of
which alone would be sufficient: (1) phpseclib/Flysystem already gives this
path connection reuse *for free*, within the scope where it actually
matters (one request), so there is close to nothing left to win; and
(2) `SshMultiplexer`'s architecture — "run one shell command, get text
back" via `proc_open` — is not the right shape for what Flysystem actually
needs (structured SFTP subsystem RPCs: stat/get/put/rename/mkdir/list),
so "extending" it here would mean building a materially different,
much larger thing, not reusing what already shipped for `SshTerminal`.

---

## 1. What phpseclib/Flysystem already do, traced from the live code

**Claim under test:** "PHP's per-request lifecycle already gives this path
connect-once/reuse-for-the-request for free, so ControlMaster adds nothing
within a request; the only remaining question is cross-request reuse."

Confirmed by reading the actual dependency, not assumed:

`vendor/league/flysystem-sftp-v3/SftpConnectionProvider.php:50-59` —
`provideConnection()`:

```php
public function provideConnection(): SFTP
{
    $tries = 0;
    start:
    $tries++;
    try {
        $connection = $this->connection instanceof SFTP
            ? $this->connection          // <-- reuse if already connected
            : $this->setupConnection();
    } catch (Throwable $exception) { ... }
    ...
}
```

`$this->connection` is a private instance property on the
`SftpConnectionProvider` object — it is set once on first connect and handed
back unchanged on every subsequent call (subject to a liveness recheck via
`$connectivityChecker->isConnected()`, line 72). This is phpseclib/Flysystem's
own, already-shipped connection reuse, no FluxFiles code involved.

The question is then whether FluxFiles constructs a *fresh*
`SftpConnectionProvider` (and therefore a fresh handshake) per file
operation, or one per request. Traced through `DiskManager.php`:

- `DiskManager::disk($name)` (`DiskManager.php:25-35`) memoizes into
  `$this->disks[$name]` — built once, returned from the map on every
  subsequent call for that disk name.
- `DiskManager::buildSftpAdapter()` (`DiskManager.php:195-199`) constructs
  exactly **one** `SftpConnectionProvider` per `disk()` build, wrapped in one
  `SftpAdapter`, wrapped in one Flysystem `Filesystem` — all cached behind
  the `disk()` memoization above.
- `$diskManager = new DiskManager($diskConfigs)` is constructed **once per
  request** in `index.php` (three call sites — `index.php:273`, `:1533`,
  `:1680` — each is a distinct route/script entry, not a per-file-operation
  rebuild within one request).

So: every `list()`/`upload()`/`download()`/`rename()`/`move()`/`copy()`/
`mkdir()` call against a given SFTP disk within one HTTP request goes through
the *same* `Filesystem` → same `SftpAdapter` → same `SftpConnectionProvider`
→ same cached phpseclib `SFTP` connection object. A file-manager action that
issues several Flysystem calls in one request (e.g. a folder listing that
stats several entries, or a move that does an existence check + the move
itself) **already pays the SSH/SFTP handshake exactly once**, today, with
zero FluxFiles-authored pooling code — this is what "one request, one
process, reuse for the process's lifetime, then it exits" gives for free,
exactly as the parent task's framing anticipated.

**Conclusion of §1:** there is no *within-request* win available to add.
The only thing ControlMaster could still offer is amortizing the handshake
*across* separate HTTP requests — the same value proposition `SshTerminal`
gets from `ControlPersist`. §2 evaluates that.

## 2. Is cross-request reuse worth it here specifically?

Restating the trade the security review already quantified in the abstract,
now applied to this path's actual volume and blast radius:

**2.1 — This is the highest-request-volume SFTP path in the entire app.**
Every browse, thumbnail load, upload, download, rename, move, copy, and
`mkdir` against an SFTP disk goes through `buildSftpAdapter()`. Compare
`SshTerminal`, gated behind `allow_terminal` + `write` perm + an operator
choosing to expose a shell at all — a deliberately rare, human-interactive
surface. Extending reuse here means **F1's cache-key correctness now has to
hold at 100x+ the request volume**, and a cache-key bug's blast radius (two
tenants sharing an authenticated session) scales with how many requests hit
the shared code path before anyone notices — this is exactly the "highest
stakes if the cache key is ever wrong" case the parent task named, and
`SFTP-CONTROLMASTER-SECURITY-REVIEW.md` §4.7 flagged it for precisely this
reason before any implementation existed.

**2.2 — The handshake cost is already amortized for the case that matters.**
A typical file-manager session issues *several* SFTP calls per request when
it needs to (a folder listing plus per-entry metadata, for instance) and
that entire cluster already shares one handshake per §1. What's left
unamortized is the handshake at the *start* of each new HTTP request — i.e.
each individual click in the UI that results in exactly one API call is its
own request, paying its own handshake, the same shape `SshTerminal` had
before ControlMaster. The difference is how often "one API call = one
request" actually happens here vs. terminal: a human typing several shell
commands in a row is the terminal's dominant traffic shape (this is *why*
`SshTerminal` benefits); a file browser's dominant traffic shape is many
small, independent, already-fast (typically single Flysystem call each)
requests fired by UI interactions (open a folder, load a thumbnail, rename
a file) — each individually short, and each already amortizing its *own*
handshake across whatever calls that one request needed. There's no
equivalent to "five commands typed within ten seconds of each other,
watching the terminal degrade to a fresh handshake every time" here — the
UI doesn't sit and issue five sequential unrelated requests to the same
disk as fast as a human types into a terminal; it fires one request, gets a
listing, renders it, and waits for the next explicit user action.

**2.3 — Multi-tenancy amplifies F1/F6, not just their probability but their
*count of affected parties*.** A SaaS operator serving many BYOB SFTP
tenants through one FluxFiles installation would, on this path, be
multiplexing the credential cache across the single highest-traffic surface
every one of those tenants uses on every single page load — vs.
`SshTerminal`, which only a subset of tenants ever enable at all
(`allow_terminal` is opt-in per token, and many operators never grant shell
access to anyone). The tenant population exposed to a cache-key mistake is
structurally larger here.

## 3. Is `SshMultiplexer`'s architecture even the right shape for this path?

**No — and this is a categorical mismatch, not a tuning question.**
`SshMultiplexer` (as shipped for `SshTerminal`) works because the entire
contract it needs to satisfy is: *"run one shell command string on the
remote host, capture combined stdout/stderr and an exit code."* That's
exactly what `ssh -S <socket> -- <wrapped command>` gives back
(`SshMultiplexer.php`'s `execCold()`/`execReuse()`, §11.1/§11.2 of the spec).
There is no structured RPC involved — the wrapper prints a `cd`/`pwd`
marker into the text stream and parses it back out
(`SshTerminal::buildWrappedCommand()`/`parseOutput()`).

Flysystem's `SftpAdapter` needs something categorically different: a live
`phpseclib3\Net\SFTP` **object** exposing typed methods —
`SftpAdapter.php:56` (`is_file`), `:72` (`is_dir`), `:88`/`:116`/`:156`/
`:169`/`:186`/`:193`/`:206` (stat, get, put, rename, mkdir, chmod, list, and
more) — each a distinct SFTP-protocol-proper operation (not a shell
command), with typed return values (stat structs, directory entries, raw
bytes) that the adapter code consumes directly as PHP values. There is no
"stdout text stream to parse a marker out of" step anywhere in this
contract.

**Could an OpenSSH ControlMaster socket carry an actual SFTP subsystem
session, the way `sftp -oControlPath=<socket>` can?** Technically yes —
OpenSSH's `sftp` client accepts `-oControlPath=`/`-S` the same as `ssh` and
will open a new channel over an already-multiplexed connection, skipping
the TCP+KEX+auth cost of a cold connect. But adopting *that* is not
"extending `SshMultiplexer`" — it requires building a wholly new Flysystem
`ConnectionProvider`/adapter pair that:

- shells out to `sftp -b <batchfile> -S <socket>` (OpenSSH's batch mode) for
  every single stat/get/put/rename/mkdir/list operation, since each
  `proc_open` is a new process with no persistent in-PHP object to call
  methods on (unlike phpseclib's `SFTP` object, which stays alive and
  stateful for the whole request);
- parses `sftp`'s human-oriented text output (`ls -la`-style listings,
  free-form error strings) back into the typed structures
  `League\Flysystem\PhpseclibV3\SftpAdapter` currently gets natively from
  phpseclib's typed API — a full reimplementation of a well-tested adapter's
  parsing/error-mapping logic, not a config change;
- **loses §1's already-free win in the process**: a `sftp -b` batch-mode
  invocation is itself a fresh process per Flysystem call even when it rides
  an already-open ControlMaster socket — it still pays SFTP-subsystem
  channel-open negotiation on *every single call*, including the 2nd, 3rd,
  4th call within one request that phpseclib's cached `SFTP` object
  currently serves with **zero** additional negotiation at all. Trading a
  free, in-process, stateful connection for a proc_open-per-call batch
  client would very plausibly be *slower* for the common multi-call-per-request
  case, not faster — the opposite of the intended effect.

**Conclusion of §3:** this is not a matter of pointing the existing
`SshMultiplexer` class at a second call site. It would require an entirely
separate, parallel SFTP client implementation (`sftp -b` batch-mode driven)
with its own protocol-response parsing, its own error taxonomy, and its own
correctness burden — a new adapter roughly comparable in scope to
`League\Flysystem\PhpseclibV3\SftpAdapter` itself, maintained a second time,
forever, in parallel with the phpseclib one every other SFTP hardening
feature (`host_fingerprint`, `require_host_key`, `strict_algorithms`)
already targets. That is a materially different, much larger project than
"extend ControlMaster reuse" — and per §2, one aimed at a benefit that's
already mostly captured for free and a cost surface that's already the
largest in the app.

## 4. Findings

| # | From the security review | Applies here? | Why |
|---|---|---|---|
| F1 (cache-key collision across tenants) | Critical | **Applies at the largest possible scale** | Highest-request-volume path, largest tenant population exposed (§2.1, §2.3). |
| F2 (local socket, no re-auth) | High | Applies unchanged | No path-specific mitigation exists. |
| F3 (ControlPersist outlives a revoked credential) | High | Applies unchanged, at higher exposure | More requests per unit time hitting this path means more opportunities for a request to land inside a stale-but-not-yet-expired persist window after a credential rotation. |
| F4 (password/passphrase → argv exposure) | Medium | Mitigated by the same key-based-only gate, if built | Doesn't offset F1–F3/F6/F7 below. |
| F5 (OpenSSH stack diverges from phpseclib hardening) | Medium | Applies unchanged | Same porting burden, now guarding the highest-traffic path instead of an opt-in terminal. |
| F6 (unbounded socket/process growth) | Medium | **Applies at its worst-case shape** | This is precisely the volume/tenant-count-driven growth pattern F6 describes — every configured SFTP disk (not just ones with `allow_terminal` granted) would be a candidate for a lingering socket. |
| F7 (new persistent state, architecturally) | Low/architectural | Fully applies | See §3 — the honest scope here isn't "add a cache," it's "add and forever maintain a second SFTP client implementation." |
| (new) Batch-mode-per-call regression | — | **Net-negative for the common case** | §3's last point: a naive `sftp -b`-per-call implementation would very plausibly be slower than what phpseclib already does for free within a request — not merely "not worth the risk," but "measurably worse" for the majority of real traffic shapes. |

## 5. Recommendation

**NO-GO.** Do not extend `SshMultiplexer`, or build a parallel
`sftp`-batch-mode adapter, for the Flysystem SFTP path. `DiskManager::disk()`,
`buildSftpAdapter()`, and `sftpConnection()` keep their current signatures
and behavior, unchanged, exactly as `SFTP-CONTROLMASTER-SPEC.md` §19 already
states. No claim, config key, or env var is added by this review.

If a future performance complaint specifically targets SFTP-disk browsing
latency, the productive places to look are (a) whether the request volume
itself can be reduced (batching stat calls, caching directory listings —
FluxFiles already has `_fluxfiles/index.json`/`dirs.json` search indexes for
exactly this kind of avoidance elsewhere), or (b) `SftpConnectionProvider`'s
own `timeout`/`maxTries` tuning — not connection-level multiplexing, and
certainly not a second, hand-rolled SFTP client.
