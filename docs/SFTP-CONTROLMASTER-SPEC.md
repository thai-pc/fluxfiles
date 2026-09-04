# SSH ControlMaster — Design Spec

Status: **Design spec, no code written.** Follow-up to
`docs/SFTP-CONTROLMASTER-SECURITY-REVIEW.md` (**conditional go, narrowly
scoped**), which is the constraint source of truth — this doc turns its §4
into exact names, file paths, function signatures, and flag strings. It does
not re-litigate whether to build this; every design choice below traces back
to a specific §4 constraint (or, in three places called out explicitly,
closes a gap the review didn't fully resolve — see §7, §9, §11).

Scope, restated from the review's §4.7 and re-confirmed in §19 below: **this
only ever wires into `SshTerminal`'s `/api/fm/terminal` path.** `GitDeploy`
and the Flysystem SFTP adapter (browsing/upload/download) are untouched.

---

## 1. Decision summary

| Question | Decision |
|---|---|
| Opt-in name | **`ssh_multiplex`** — a **disk config key**, not a JWT claim (see §2) |
| Cache/socket key | `hash('sha256', json_encode([host,port,username,password,private_key,passphrase,host_fingerprint,strict_algorithms]))` (§4) |
| Socket dir | `{FLUXFILES_STORAGE_PATH}/ssh-sockets/` (0700), filename = first 20 hex chars of the cache-key hash + `.sock` (§5) |
| `ControlPersist` | `60` seconds, env-tunable `[10, 120]` clamp (§6) |
| Teardown | Cache key itself absorbs config changes (no separate "revoke" plumbing); LRU eviction + opportunistic sweep are the only *active* teardown paths (§6) |
| Auth gate | Multiplex-eligible **iff** `private_key` is set **and** `private_key_passphrase` is empty — passphrase-protected keys fall back too, not just password-only (§7 — extends the review's F4 gate) |
| Algorithm sync | One private source list feeds both phpseclib's `preferredAlgorithms` shape and OpenSSH's `-o *Algorithms=` flags (§8) |
| Known-hosts | Piggybacks on phpseclib's own already-fingerprint-verified connection to avoid a second, unauthenticated key-fetch (§9 — resolves a gap the review left as "populated ... from host_fingerprint" without saying how) |
| LRU cap | **20** concurrently-open sockets, server-wide, env-tunable (§10) |
| New i18n | **None** — every multiplex-specific failure falls back to the existing phpseclib path silently (logged, not surfaced) (§16) |

---

## 2. Config surface

### 2.1 Disk config key: `ssh_multiplex` (bool, default `false`)

Placed alongside `host_fingerprint` / `require_host_key` / `strict_algorithms`
in `config/disks.php`'s SFTP block — **not** a JWT claim. Rationale for that
placement (mirrors the existing three, not `allow_terminal`/`allow_git_deploy`):

- It grants **no new privilege**. `allow_terminal` already grants full shell;
  `ssh_multiplex` only changes *how* an already-granted shell's connection is
  transported. A token holder gains nothing by having it on vs off — so
  unlike `allow_git_deploy` (a dedicated claim precisely because it's a new
  privilege boundary), this has no reason to be per-token.
- It's a property of the **disk's operational posture** (does this SFTP
  target/credential get multiplexed), decided once by whoever configures the
  disk (operator for a static disk, or a BYOB tenant for their own disk) —
  exactly the same shape as `require_host_key`/`strict_algorithms`.
- **BYOB gets it for free**, same note as `require_host_key`/`strict_algorithms`:
  `CredentialEncryptor::validate()` doesn't allowlist config fields, so a BYOB
  tenant can set `ssh_multiplex: true` in their own encrypted `byob_disks`
  blob with zero extra plumbing. This is safe under the F1 fix (§4) because
  the cache key is a hash of that same tenant's *own* decrypted credential
  material — two tenants can only ever collide on a socket if every one of
  host/port/username/password/private_key/passphrase/host_fingerprint/
  strict_algorithms is byte-identical, which is precisely the "this is
  genuinely the same login" case the review says is fine to share.

Rejected name: `controlmaster_enabled`. `ssh_multiplex` matches the terse,
behavior-describing, verb-free style of `require_host_key`/`strict_algorithms`
— an operator doesn't need to know OpenSSH's internal feature name to decide
whether to turn on connection reuse.

`config/disks.php` (SFTP block), new line after `strict_algorithms`:

```php
// Reuse an OpenSSH ControlMaster session across terminal commands on this
// disk instead of reconnecting per command. Off by default. Key-based auth
// only — a password-only (or passphrase-protected-key) config silently
// falls back to the existing per-request phpseclib path. See
// docs/SFTP-CONTROLMASTER-SPEC.md.
'ssh_multiplex' => ($_ENV['SFTP_MULTIPLEX'] ?? '') === 'true',
```

### 2.2 Env vars (new)

| Env var | Default | Notes |
|---|---|---|
| `SFTP_MULTIPLEX` | `false` | Static-disk `ssh_multiplex` toggle (see §2.1). |
| `FLUXFILES_SSH_MULTIPLEX_DISABLED` | `false` | Server kill-switch — `true` forces every disk back to the phpseclib-only path regardless of `ssh_multiplex`, mirroring `FLUXFILES_TERMINAL_DISABLED`/`FLUXFILES_GIT_DEPLOY_DISABLED`. |
| `FLUXFILES_SSH_MULTIPLEX_PERSIST` | `60` | `ControlPersist` seconds. Clamped `[10, 120]` — an out-of-range value is clamped, not rejected, matching `Claims::fromJwtPayload`'s "bad value never breaks the server" philosophy applied to env vars. |
| `FLUXFILES_SSH_MULTIPLEX_MAX_SOCKETS` | `20` | LRU cap, server-wide (§10). |
| `FLUXFILES_SSH_MULTIPLEX_CONNECT_TIMEOUT` | `10` | Seconds `ssh -M`'s cold connect gets before it's killed and the request falls back to phpseclib for that call. |

---

## 3. New components

| File | New |
|---|---|
| `packages/core/api/SshMultiplexer.php` | **New file.** All multiplex mechanics: cache key, socket dir/path, known-hosts materialization, temp key file, `proc_open` invocations, LRU tracker file. |
| `packages/core/api/DiskManager.php` | +`modernSshAlgorithmLists()` (private static, new canonical source), +`modernSshOpensshFlags()` (public static, new), +`multiplexHandle()` (public, new), +`multiplexEligible()` (private static, new). `modernSshAlgorithms()`, `buildSftpProvider()`, `sftpConnection()` **unchanged in behavior** (existing signature/body untouched except the internal delegation described in §8). |
| `packages/core/api/SshTerminal.php` | `run()`'s body split into two new pure helpers (§12); `run()`'s own signature/behavior is **unchanged**. |
| `packages/core/api/index.php` | `/api/fm/terminal` route gains a 4-line branch (§13). `/api/fm/git-deploy` **untouched**. |
| `config/disks.php` | +1 line (§2.1). |
| `.env.example` | +5 lines (§15). |
| `docs/CONFIG.md` | +1 new small section (§14). |

---

## 4. Cache key derivation (closes F1)

```php
// SshMultiplexer.php
private static function cacheKey(array $cfg): string
{
    return hash('sha256', json_encode([
        'host'              => (string) ($cfg['host'] ?? ''),
        'port'              => (int) ($cfg['port'] ?? 22),
        'username'          => (string) ($cfg['username'] ?? ''),
        'password'          => (string) ($cfg['password'] ?? ''),
        'private_key'       => (string) ($cfg['private_key'] ?? ''),
        'passphrase'        => (string) ($cfg['private_key_passphrase'] ?? ''),
        'host_fingerprint'  => (string) ($cfg['host_fingerprint'] ?? ''),
        'strict_algorithms' => !empty($cfg['strict_algorithms']) ? '1' : '0',
    ], JSON_UNESCAPED_SLASHES));
}
```

Notes:

- `json_encode()` of a fixed-shape associative array (not raw string
  concatenation) sidesteps any delimiter-collision ambiguity (e.g. a
  password containing the literal string used as a separator) — JSON's own
  length-prefixed-string escaping does that job for free.
- `password` is included even on a key-based-auth-eligible config (harmless
  — usually empty) so that a config which sets *both* and later drops the
  password doesn't silently keep colliding with its former self across that
  edit; cheap and correct.
- `root` is deliberately **excluded** — two configs identical in every
  auth-relevant field but pointed at different `root` prefixes are still the
  same authenticated login and safely share one multiplexed session
  (`SshTerminal::resolveCwd()` handles root-scoping client-side, same as
  today).
- `host_fingerprint`/`strict_algorithms` are included even though they don't
  affect *authentication* — this is what gives §6's "config change ⇒ new
  cache key" its teeth without any separate diff/revoke mechanism (see §6).

---

## 5. Socket directory & filename (closes F2)

Base dir, mirroring `RateLimiterFactory`'s existing pattern exactly:

```php
$base = rtrim($_ENV['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage'), '/');
$socketDir = $base . '/ssh-sockets';
```

- Created lazily, **only** on the first `/api/fm/terminal` request that
  actually attempts a multiplex connect (never at boot) — same "don't touch
  disk for unrelated requests" discipline as `RateLimiterFileStorage`.
- `mkdir($socketDir, 0700, true)`, then an ownership check before ever
  reading/writing into it — same defensive pattern `OidcDiscovery`'s
  `isOwnedByUs()` already uses for its JWKS cache dir, because
  `FLUXFILES_STORAGE_PATH`'s *default* fallback (`__DIR__/../storage`) is
  safe, but an operator-supplied path could theoretically be a
  pre-existing, loosely-permissioned directory. Refuse to use it (fall back
  to phpseclib for the request, `error_log` once) rather than trust it.
- Filename = **`substr($cacheKeyHash, 0, 20) . '.sock'`** — never a
  `host-username` string, so a local process that can merely `ls` the
  directory (but not open a socket, since perms are 0700) still learns
  nothing about which hosts/users are configured. 20 hex chars (80 bits) is
  far more collision-resistant than needed while keeping the full path well
  under Unix's `sun_path` limit (108 bytes on Linux, 104 on BSD/macOS).
- **`sun_path` length is a real, checkable failure mode**, not a footnote:
  before using a computed socket path, `SshMultiplexer` asserts
  `strlen($socketPath) <= 100` (5-byte safety margin below the tightest
  platform limit); over that, multiplexing is skipped for that disk for the
  request (fall back, `error_log` a clear "socket path too long — set
  `FLUXFILES_STORAGE_PATH` shorter" message) instead of letting `proc_open`
  fail with an opaque `bind: File name too long`. An operator with a very
  deeply nested `FLUXFILES_STORAGE_PATH` should set it to something short
  (e.g. `/var/lib/fluxfiles`) rather than relying on a project-relative path.

```php
private static function socketPath(string $cacheKeyHash, string $socketDir): string
{
    return $socketDir . '/' . substr($cacheKeyHash, 0, 20) . '.sock';
}
```

`keys/` and `known_hosts/` are sibling subdirectories of `ssh-sockets/`, same
0700 dir, same ownership check (§7, §9).

---

## 6. `ControlPersist` + teardown (closes/bounds F3)

`ControlPersist=60` (env-tunable, clamped `[10,120]` — see §2.2). 60s is
picked as the upper end of the review's suggested 30–60s range: long enough
that a human issuing a few terminal commands a few seconds apart reuses the
socket, short enough that a revoked claim or rotated credential's exposure
window stays bounded to "about a minute," the same order of magnitude this
codebase already accepts for a short-lived JWT.

**Teardown has exactly three triggers — no fourth "revoke" endpoint exists,
and none should be added** (this repo has no push-based revocation channel
at all; inventing one just for this feature would be new state precisely of
the kind the review's F7 warns against):

1. **Natural `ControlPersist` expiry.** OpenSSH's own master process
   self-exits N seconds after the last multiplexed sub-connection closes.
   This is the primary mechanism and the only one that requires zero
   FluxFiles code.
2. **Cache-key-driven implicit invalidation.** Because §4's key hashes
   `host_fingerprint`/`strict_algorithms` alongside the actual credentials,
   *any* config edit (rotate a password, change the pinned fingerprint,
   flip `strict_algorithms`) produces a **different** cache key on the next
   request — the old socket is simply never looked up again. It isn't
   *killed* early by this alone (that's still bounded by #1's timeout), but
   it can never be reused under the new config, which is the property that
   actually matters. **This must be read as "bounded, not immediate" — the
   exact trade-off the review requires be documented rather than solved**,
   the same way `GitDeploy`'s non-atomic-deploy limitation (its review §4.8)
   is documented rather than solved.
3. **Explicit `ssh -O exit -S <socket>`**, fired only by:
   - LRU eviction (§10) — a *different* cache key needs the slot.
   - An opportunistic sweep, 1-in-20 terminal requests (same probability
     `DemoMode::purge()` already uses for its own opportunistic cleanup):
     scan the tracker file (§10), and for any entry whose
     `last_used + ControlPersist + 5s grace` has passed, run
     `ssh -O check -S <socket>` — if the master is already gone (the normal
     case, since it self-expired per #1), just drop the stale tracker entry
     and `@unlink()` any leftover socket file (defends against a rare
     OOM-killed master that didn't clean up its own socket); if `check`
     reports it's *still* alive past its expected expiry (clock skew, a
     slow-to-exit master), issue the explicit `-O exit`.

There is **deliberately no "kill this socket because a claim changed"
hook** — claims live in the JWT, not the disk config, and `ssh_multiplex`
lives in the disk config precisely so it's orthogonal to per-token claims
(§2.1). A claim change never needs to invalidate a multiplex session because
multiplexing carries no privilege of its own.

---

## 7. Key-based-auth-only gate (closes F4 — and one gap beyond it)

```php
// DiskManager.php
private static function multiplexEligible(array $cfg): bool
{
    if (($cfg['driver'] ?? '') !== 'sftp' || empty($cfg['ssh_multiplex'])) {
        return false;
    }
    if (($cfg['private_key'] ?? '') === '') {
        return false; // password-only → sshpass/argv exposure (F4). Fall back.
    }
    if (($cfg['private_key_passphrase'] ?? '') !== '') {
        return false; // see below — this is NOT in the review's F4 wording.
    }
    return true;
}
```

**The passphrase branch is a gap the review didn't name.** F4 only discusses
password auth (`sshpass -p<password>` putting a password in argv). But a
passphrase-*protected* private key has the exact same problem one level
down: OpenSSH's `-i <keyfile>` has no non-interactive way to supply a
passphrase either (no interactive TTY under `proc_open`, and `useAgent:
false`/no local ssh-agent is an existing, deliberate rule — see
`DiskManager::buildSftpProvider()`'s comment). The only ways to automate a
passphrase-protected key with the real `ssh` binary are `SSH_ASKPASS` +
`setsid`/no-TTY tricks, or `sshpass -P passphrase -p<phrase> ssh ...` — both
put the passphrase in an env var or argv exactly like F4's password case.
phpseclib, by contrast, decrypts a passphrase-protected key entirely
in-process with no subprocess and no argv/env exposure — exactly the
capability §7's fallback already relies on.

**Extension of §4.4: multiplex-eligible ⟺ `ssh_multiplex` on, `private_key`
non-empty, `private_key_passphrase` empty.** A passphrase-protected key
config falls back to phpseclib, same as password-only, for the same reason.

`DiskManager::multiplexHandle()` is the single call site (only ever invoked
from the `/api/fm/terminal` route — see §19):

```php
// DiskManager.php
/** @return array{0:SshMultiplexer,1:string}|null [handle, root], or null → caller falls back to sftpConnection(). */
public function multiplexHandle(string $name): ?array
{
    $cfg = $this->configs[$name] ?? [];
    if (!self::multiplexEligible($cfg)) {
        return null;
    }
    return [SshMultiplexer::acquire($cfg, $name), rtrim((string) ($cfg['root'] ?? '/'), '/')];
}
```

If `require_host_key` is on but `host_fingerprint` is empty,
`multiplexEligible()` doesn't need a special case: `multiplexHandle()`
returning `null` just routes to `sftpConnection()`, which already throws
`sftp_host_key_required` at that point (unchanged, existing behavior) — so
the inconsistency resolves itself for free.

---

## 8. Algorithm allowlist: one source feeding both stacks (closes F5)

Today `DiskManager::modernSshAlgorithms()` builds `$ciphers`/`$macs` inline
and returns phpseclib's nested shape. Refactor: extract the four flat lists
into one new private method; both consumers become thin, mechanical
reshapers of it.

```php
// DiskManager.php

/**
 * THE single source for the modern-only KEX/hostkey/cipher/MAC allowlist.
 * Every algorithm name is already OpenSSH's own IANA-registry naming (that's
 * why the exact same strings work for both phpseclib's preferredAlgorithms
 * AND OpenSSH's -o *Algorithms= flags) — only the packaging differs.
 *
 * @return array{kex:string[],hostkey:string[],ciphers:string[],macs:string[]}
 */
private static function modernSshAlgorithmLists(): array
{
    return [
        'kex' => [
            'curve25519-sha256', 'curve25519-sha256@libssh.org',
            'ecdh-sha2-nistp256', 'ecdh-sha2-nistp384', 'ecdh-sha2-nistp521',
            'diffie-hellman-group-exchange-sha256',
            'diffie-hellman-group16-sha512', 'diffie-hellman-group18-sha512',
            'diffie-hellman-group14-sha256',
        ],
        'hostkey' => [
            'ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384',
            'ecdsa-sha2-nistp521', 'rsa-sha2-512', 'rsa-sha2-256',
        ],
        'ciphers' => [
            'aes256-gcm@openssh.com', 'chacha20-poly1305@openssh.com',
            'aes128-gcm@openssh.com', 'aes256-ctr', 'aes192-ctr', 'aes128-ctr',
        ],
        'macs' => [
            'hmac-sha2-256-etm@openssh.com', 'hmac-sha2-512-etm@openssh.com',
            'hmac-sha2-256', 'hmac-sha2-512',
        ],
    ];
}

/** UNCHANGED signature/return shape — now a thin reshape of modernSshAlgorithmLists(). */
private static function modernSshAlgorithms(): array
{
    $l = self::modernSshAlgorithmLists();
    return [
        'kex' => $l['kex'],
        'hostkey' => $l['hostkey'],
        'client_to_server' => ['crypt' => $l['ciphers'], 'mac' => $l['macs']],
        'server_to_client' => ['crypt' => $l['ciphers'], 'mac' => $l['macs']],
    ];
}

/**
 * OpenSSH -o flag pairs for the SAME allowlist, for SshMultiplexer's proc_open
 * argv. NEW — public because SshMultiplexer (a different class) consumes it.
 *
 * @return string[] flat ['-o','KexAlgorithms=...', '-o','HostKeyAlgorithms=...', ...]
 */
public static function modernSshOpensshFlags(): array
{
    $l = self::modernSshAlgorithmLists();
    return [
        '-o', 'KexAlgorithms=' . implode(',', $l['kex']),
        '-o', 'HostKeyAlgorithms=' . implode(',', $l['hostkey']),
        '-o', 'Ciphers=' . implode(',', $l['ciphers']),
        '-o', 'MACs=' . implode(',', $l['macs']),
    ];
}
```

Because both public/consumable methods are pure reshapes of one private
list, there is structurally nothing to "forget to update" — a future edit to
the allowlist happens in exactly one array literal. The regression that
still needs a test is someone *bypassing* this wiring later (e.g. hand-
rolling a second list inside `modernSshOpensshFlags()` "for a quick fix") —
§18's sync test asserts set-equality across both outputs so that would fail
CI immediately.

---

## 9. Known-hosts file (resolves a real gap in the review's §4.5 wording)

The review says `require_host_key` → `-o UserKnownHostsFile=<a per-disk file
populated only from the disk's own pinned host_fingerprint>`. That's not
directly buildable: a **fingerprint is a one-way hash** of a host key —
useful for *verifying* a key you already have, but you cannot reconstruct
the actual public key bytes an OpenSSH `known_hosts` line requires (`<host>
<keytype> <base64-key>`) from a fingerprint alone.

**Resolution: piggyback on phpseclib's own connection instead of a second,
separately-unauthenticated fetch (e.g. `ssh-keyscan`).**
`DiskManager::sftpConnection($name)` already builds a phpseclib
`SftpConnectionProvider` that — when `host_fingerprint` is set — verifies
the offered host key against it as a normal part of establishing the
connection (throws before handing back a connection otherwise). phpseclib3's
`SSH2` exposes `getServerPublicHostKey(): string`, already in the exact
`"<keytype> <base64>"` format a known_hosts line's last two fields need.

```php
// SshMultiplexer.php — only runs on a cold connect for a disk with host_fingerprint set
private static function ensureKnownHosts(array $cfg, DiskManager $diskManager, string $diskName): string
{
    $dir = self::runtimeDir() . '/known_hosts';
    self::ensureOwnedDir($dir);
    $fp = trim((string) ($cfg['host_fingerprint'] ?? ''));

    if ($fp !== '') {
        // Fingerprint pinned → get an ALREADY-VERIFIED key from phpseclib
        // (it throws before this line if the offered key doesn't match $fp),
        // never trust a second, separately-fetched key blindly.
        [$conn] = $diskManager->sftpConnection($diskName); // throws on mismatch
        $keyLine = $conn->getServerPublicHostKey(); // "<type> <base64>"
        $path = $dir . '/' . hash('sha256', $cfg['host'] . ':' . ($cfg['port'] ?? 22)) . '.khosts';
        // Regenerated fresh every cold connect — cheap, avoids any stale-file
        // management, and each write is itself re-verified via the throw above.
        file_put_contents($path, ($cfg['host'] ?? '') . ' ' . $keyLine . "\n");
        chmod($path, 0600);
        return $path; // caller uses -o StrictHostKeyChecking=yes
    }

    // No fingerprint pinned → same "trust whatever's offered" default posture
    // phpseclib already has without one — but pin-on-first-sight (TOFU) into a
    // PERSISTENT per-disk file, which is strictly not worse than phpseclib's
    // unconditional trust-any-key default, and avoids the double-handshake
    // cost below on every cold connect.
    $path = $dir . '/' . hash('sha256', $cfg['host'] . ':' . ($cfg['port'] ?? 22)) . '.khosts';
    if (!is_file($path)) {
        touch($path);
        chmod($path, 0600);
    }
    return $path; // caller uses -o StrictHostKeyChecking=accept-new
}
```

**Honest cost, stated plainly (extends the review's §5 performance
honesty):** when `host_fingerprint` is set, a cold multiplex connect now
pays **two** handshakes — one phpseclib connection (solely to obtain a
pre-verified host key) plus the OpenSSH `-M` connection itself — strictly
*worse* than plain phpseclib alone for that first command. The win only
appears from the 2nd command onward in the same `ControlPersist` window.
This sharpens the review's own conclusion that a mostly-single-exec
consumer (its example: `GitDeploy`) would not benefit — with a pinned
fingerprint it would be pure overhead on every single deploy trigger, never
amortized, which is a second, independent reason (beyond §19's scope
argument) `GitDeploy` should stay out.

When `host_fingerprint` is **not** set, there's no such penalty — a single
OpenSSH handshake with `StrictHostKeyChecking=accept-new` against the
persistent per-disk file.

---

## 10. LRU eviction (closes F6)

**Cap: 20** concurrently-open sockets, server-wide (not per-tenant, not per
disk) — `FLUXFILES_SSH_MULTIPLEX_MAX_SOCKETS`. Justification: this feature
is scoped to `SshTerminal` only (§19), an inherently human-interactive,
low-concurrency surface (an admin or a CI runner actively typing/executing
commands) — not a per-request-volume path. 20 gives comfortable headroom for
a few dozen simultaneously-active terminal users on one app-server process
while still putting a small, fixed ceiling on process/fd growth regardless
of how many *tenants* are configured — directly the "grows with tenant count,
not concurrent usage" fix F6 asks for.

**Tracking structure — a small locked JSON file, not a DB, not
`_fluxfiles/`.** It's local-server-only ephemeral state (the opposite of
"travels with user storage"), so it lives under `FLUXFILES_STORAGE_PATH`
next to `rate_limit.json`, not under any per-disk `_fluxfiles/` prefix:

`{FLUXFILES_STORAGE_PATH}/ssh-sockets/index.json`

```json
{
  "3f9a1b2c4d5e6f7081a2": {
    "socket": "/var/lib/fluxfiles/ssh-sockets/3f9a1b2c4d5e6f7081a2.sock",
    "disk": "sftp",
    "created_at": 1735689600,
    "last_used": 1735689660
  }
}
```

Read/modify/write follows `RateLimiterFileStorage`'s exact pattern:
`fopen(path, 'c+')` → `flock(LOCK_EX)` → decode → mutate → `ftruncate` +
`rewind` + `fwrite` → `flock(LOCK_UN)`, `chmod(0600)` on first create.

Eviction decision is a **pure function**, tested without touching any real
process (§18):

```php
// SshMultiplexer.php
/** Oldest-first cache keys to evict so the index doesn't exceed $cap after adding one more. @return string[] */
public static function selectEvictions(array $index, int $cap): array
{
    if (count($index) < $cap) {
        return [];
    }
    $byAge = $index;
    uasort($byAge, fn($a, $b) => $a['last_used'] <=> $b['last_used']);
    return array_slice(array_keys($byAge), 0, count($index) - $cap + 1);
}
```

A thin I/O wrapper (`SshMultiplexer::evict(string $cacheKey): void`) then
does the actual `ssh -O exit -S <socket> <host>` (§11) + tracker-entry
removal + `@unlink($socket)` for each key `selectEvictions()` returns, called
right before inserting a new entry that would exceed the cap.

---

## 11. `proc_open` invocation shapes

All argv, no shell string — same discipline as `PdfOptimizer`'s
`proc_open($args, ...)` (array form; PHP execs directly, no `/bin/sh -c`
involved, so no flag/metacharacter injection from any array element).

### 11.1 Cold connect + first command

```php
$args = [
    'ssh',
    '-M', '-S', $socketPath,
    '-o', 'ControlPersist=' . $persistSeconds,
    '-o', 'BatchMode=yes',                 // never prompt — fail fast instead
    '-o', 'ConnectTimeout=' . $connectTimeout,
    '-o', 'IdentitiesOnly=yes',            // only the -i key, never ssh-agent/defaults
    '-o', 'PasswordAuthentication=no',
    '-o', 'KbdInteractiveAuthentication=no',
    '-o', 'StrictHostKeyChecking=' . ($fingerprintPinned ? 'yes' : 'accept-new'),
    '-o', 'UserKnownHostsFile=' . $knownHostsPath,
    '-o', 'GlobalKnownHostsFile=/dev/null', // never consult the system-wide file either
];
if (!empty($cfg['strict_algorithms'])) {
    $args = array_merge($args, DiskManager::modernSshOpensshFlags());
}
$args = array_merge($args, [
    '-p', (string) $port,
    '-i', $keyFile,          // §7: only reached when private_key set, passphrase empty
    '-l', $username,
    $host,
    '--',
    $wrappedCmd,              // from SshTerminal::buildWrappedCommand() — §12
]);

$desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = @proc_open($args, $desc, $pipes);
// poll proc_get_status() same as PdfOptimizer::compress(), proc_terminate(9) past
// $connectTimeout (this is the CONNECT+first-command timeout, distinct from the
// per-command $timeout used on the reuse path in 11.2), capture exit at first
// !running, THEN unlink($keyFile) in a finally — never before the process exits.
```

The private key touches disk **only for this cold-connect invocation's
lifetime** — written just before `proc_open`, deleted the moment that exact
process has exited (it has already read the key file during its own
startup/auth phase by then). The 2nd+ call (§11.2) never references a key
file at all.

### 11.2 Reuse (2nd+ call against an already-alive socket)

```php
$args = [
    'ssh',
    '-S', $socketPath,
    '-o', 'BatchMode=yes',
    '-p', (string) $port, '-l', $username, $host,
    '--',
    $wrappedCmd,
];
// same desc/poll/capture as 11.1, timeout = the PER-COMMAND $timeout (terminal's
// existing FLUXFILES_TERMINAL_TIMEOUT), no key file, no known-hosts/algorithm
// flags (a live multiplexed sub-connection inherits the master's already-
// negotiated session — these are ignored, not re-verified, which is correct:
// the trust decision was made once, at cold-connect).
```

### 11.3 Liveness check (before trusting a tracked socket as reusable)

```php
$args = ['ssh', '-S', $socketPath, '-O', 'check', $host];
// exit 0 → alive, reuse via 11.2. Non-zero/proc_open failure → treat as dead:
// drop the tracker entry, @unlink the socket file, fall through to 11.1 (cold).
```

### 11.4 Explicit teardown (LRU eviction, sweep)

```php
$args = ['ssh', '-S', $socketPath, '-O', 'exit', $host];
// best-effort — failure just means @unlink cleans up the file anyway.
```

---

## 12. `SshTerminal.php` — what changes vs. what stays identical

**Unchanged:** `run(SSH2 $ssh, ...)`'s public signature and return shape,
`isDangerous()`, `resolveCwd()`, `shellAvailable()`, `MAX_OUTPUT`,
`CWD_MARK`. Every existing caller of `SshTerminal::run()` (the phpseclib
path, and `GitDeploy.php` conceptually reuses the same *pattern* though it
has its own `run()`) keeps working exactly as today.

**New — pure, phpseclib-independent helpers extracted out of `run()`'s
body**, so `SshMultiplexer::run()` can produce byte-identical output shape
without depending on an `SSH2` object at all:

```php
/** Extracted from run(): builds the wrapped shell string. No I/O. */
public static function buildWrappedCommand(string $cmd, string $cwd): string
{
    $cwd = $cwd !== '' ? $cwd : '.';
    return 'cd ' . escapeshellarg($cwd) . ' && { ' . $cmd . '; } 2>&1; '
         . '__ff_rc=$?; printf "\n' . self::CWD_MARK . '%s\n" "$(pwd)"; exit $__ff_rc';
}

/**
 * Extracted from run(): parses raw combined stdout+stderr + an exit code into
 * the {output,cwd,exit,truncated,shell_ok} shape. No I/O.
 */
public static function parseOutput(string $raw, ?int $exit, string $cwd): array
{
    $newCwd = $cwd === '.' ? '' : $cwd;
    $mark = preg_quote(self::CWD_MARK, '~');
    $shellOk = preg_match('~' . $mark . '(.*?)\s*$~s', $raw, $m) === 1;
    if ($shellOk) {
        $newCwd = trim($m[1]) !== '' ? trim($m[1]) : $newCwd;
    }
    $raw = (string) preg_replace('~\n?' . $mark . '.*$~s', '', $raw);

    $truncated = false;
    if (strlen($raw) > self::MAX_OUTPUT) {
        $raw = substr($raw, 0, self::MAX_OUTPUT);
        $truncated = true;
    }
    return ['output' => $raw, 'cwd' => $newCwd, 'exit' => $exit ?? 0, 'truncated' => $truncated, 'shell_ok' => $shellOk];
}

/** run() becomes a thin wrapper (behavior-preserving refactor): */
public static function run(SSH2 $ssh, string $cmd, string $cwd, int $timeout): array
{
    $cwd = $cwd !== '' ? $cwd : '.';
    $wrapped = self::buildWrappedCommand($cmd, $cwd);
    $ssh->setTimeout(max(1, $timeout));
    $raw = $ssh->exec($wrapped);
    if (!is_string($raw)) { $raw = ''; }
    $exit = $ssh->getExitStatus();
    return self::parseOutput($raw, is_int($exit) ? $exit : 0, $cwd);
}
```

`SshMultiplexer::run()` (§11.1/§11.2) calls `buildWrappedCommand()` to build
its argv's last element and `parseOutput()` on the captured stdout+exit code
— **identical escaping story to today** (only `$cwd` is ever
`escapeshellarg()`'d; the command itself is intentionally raw shell, same as
now, and it reaches the *remote* shell as a single argv element either way —
phpseclib's `exec($wrapped)` and local `ssh ... -- $wrapped` both hand the
remote sshd exactly one string to run). **No new injection surface.**

---

## 13. `/api/fm/terminal` route integration

```php
// index.php — inside the existing /api/fm/terminal handler, replacing the
// single "[$conn, $root] = $diskManager->sftpConnection($disk);" line:

$mux = (($_ENV['FLUXFILES_SSH_MULTIPLEX_DISABLED'] ?? '') === 'true')
    ? null
    : $diskManager->multiplexHandle($disk);

if ($mux !== null) {
    [$handle, $root] = $mux;
    $cwd = \FluxFiles\SshTerminal::resolveCwd((string) ($body['cwd'] ?? ''), $root);
    $result = $handle->run($cmd, $cwd, $timeout); // same {output,cwd,exit,truncated,shell_ok} shape
} else {
    [$conn, $root] = $diskManager->sftpConnection($disk);
    $cwd = \FluxFiles\SshTerminal::resolveCwd((string) ($body['cwd'] ?? ''), $root);
    $result = \FluxFiles\SshTerminal::run($conn, $cmd, $cwd, $timeout);
}
// everything after this line (shell_ok check, return $result) is UNCHANGED.
```

Every existing gate (kill-switch, `allow_terminal`, `write` perm, disk ACL,
driver check, dangerous-command confirm) stays exactly where it is, running
*before* this branch — multiplexing changes nothing about who is allowed to
reach this point, only how the already-authorized connection is built.

`/api/fm/git-deploy` is **not touched** — see §19.

---

## 14. `docs/CONFIG.md` additions

`ssh_multiplex` is **not** a JWT claim, so it does not go in §2 (and
`tests/unit/test-config-doc.php` — which only scans `$payload->X` reads in
`Claims.php` — has nothing to check here regardless). Two additions:

**New §3 env var rows** (server env vars table):

```
| `SFTP_MULTIPLEX` | `false` | Static-disk `ssh_multiplex` toggle — reuse an OpenSSH ControlMaster session across `/api/fm/terminal` commands instead of reconnecting per command. Key-based auth only (no passphrase); a password-only or passphrase-protected-key disk silently falls back to the existing per-request path. See `docs/SFTP-CONTROLMASTER-SPEC.md`. |
| `FLUXFILES_SSH_MULTIPLEX_DISABLED` | `false` | Server kill-switch — forces every disk back to the phpseclib-only path regardless of `ssh_multiplex`. |
| `FLUXFILES_SSH_MULTIPLEX_PERSIST` | `60` | `ControlPersist` seconds. Clamped `[10, 120]`. |
| `FLUXFILES_SSH_MULTIPLEX_MAX_SOCKETS` | `20` | Server-wide LRU cap on concurrently-open multiplexed sockets. |
| `FLUXFILES_SSH_MULTIPLEX_CONNECT_TIMEOUT` | `10` | Seconds a cold `ssh -M` connect gets before it's killed and the request falls back to phpseclib. |
```

**New small section, right after §2.13 or before §3** (this repo's
`config/disks.php`-level SFTP keys — `host_fingerprint`, `require_host_key`,
`strict_algorithms`, and now `ssh_multiplex` — have never had a home in this
doc; flagged, not silently fixed, as a pre-existing gap. This spec adds a
section for all four together rather than documenting only the new one in
isolation, since a reader hitting `ssh_multiplex` with no context for the
other three would be worse off):

```markdown
### 2.14 Static SFTP disk config keys (`config/disks.php` / BYOB `byob_disks`)

Not JWT claims — set per disk, either in the static `config/disks.php` SFTP
block (env-var-driven) or inside an encrypted BYOB `byob_disks` entry (same
fields, `CredentialEncryptor` doesn't allowlist config keys).

| Key | Type | Default | Notes |
|---|---|---|---|
| `host_fingerprint` | string | `""` | Colon-hex fingerprint(s) (comma-separated) pinning the expected host key. Empty = trust any host key. |
| `require_host_key` | bool | `false` | Fail closed (`sftp_host_key_required`) if `host_fingerprint` isn't also set. |
| `strict_algorithms` | bool | `false` | Modern-only KEX/cipher/MAC/host-key allowlist (`DiskManager::modernSshAlgorithmLists()`). |
| `ssh_multiplex` | bool | `false` | Reuse an OpenSSH ControlMaster session across `/api/fm/terminal` commands. Key-based auth only, no passphrase. See `docs/SFTP-CONTROLMASTER-SPEC.md`. |
```

---

## 15. `.env.example` additions

In the existing SFTP section, right after the `SFTP_STRICT_ALGORITHMS` line:

```
# Reuse an OpenSSH ControlMaster session across /api/fm/terminal commands on
# this disk instead of a fresh connection per command (faster multi-command
# terminal sessions). Off by default. Key-based auth ONLY — a password-only
# disk, or a private key protected by a passphrase, silently falls back to
# the existing per-request phpseclib path (no plaintext secret ever reaches
# a subprocess's argv/env). See docs/SFTP-CONTROLMASTER-SPEC.md.
# SFTP_MULTIPLEX=false
```

In the existing "SSH terminal (SFTP disks)" section, after
`FLUXFILES_TERMINAL_CONFIRM`:

```
# ControlMaster connection reuse (see SFTP_MULTIPLEX above). Server-wide
# tuning/kill-switch — inert unless at least one disk has ssh_multiplex=true.
# FLUXFILES_SSH_MULTIPLEX_DISABLED=false        # true = force everyone back to phpseclib
# FLUXFILES_SSH_MULTIPLEX_PERSIST=60            # ControlPersist seconds, clamped [10,120]
# FLUXFILES_SSH_MULTIPLEX_MAX_SOCKETS=20        # server-wide LRU cap
# FLUXFILES_SSH_MULTIPLEX_CONNECT_TIMEOUT=10    # cold-connect timeout, seconds
```

---

## 16. i18n

**No new `lang/*.json` keys.** Every multiplex-specific failure mode (no
`ssh` binary, `proc_open` failure, cold-connect timeout, dead socket,
ineligible config) resolves by **falling back to the existing phpseclib
path for that request** — same response shape, same existing error codes
(`terminal_no_shell`, etc.) if the fallback itself then fails. Failures are
`error_log()`'d (same posture as `DiskManager::presignGetUrl()`'s catch)
purely so an operator can notice their `ssh_multiplex=true` config is
silently inert (e.g. `ssh` not installed on the app server), never surfaced
to the client as a new error. This is a deliberate simplification: it means
`tests/unit/test-i18n.php` needs no changes for this feature.

---

## 17. Storage layout (runtime-only, not `_fluxfiles/`)

All under `{FLUXFILES_STORAGE_PATH}/ssh-sockets/` (0700, app-local — this is
the opposite of storage-resident state; it must **never** be placed under a
disk's `_fluxfiles/` prefix, since it's per-app-server process/fd state, not
metadata that travels with user storage):

```
ssh-sockets/
├── index.json           # LRU tracker — {cacheKeyHash: {socket,disk,created_at,last_used}}
├── <20-hex>.sock         # one Unix domain socket per active multiplexed session
├── keys/
│   └── <random>.pem      # ephemeral, 0600, created just before a cold-connect's
│                         # proc_open, unlinked the instant that process exits
└── known_hosts/
    └── <sha256(host:port)>.khosts   # per-disk, phpseclib-verified or TOFU'd (§9)
```

---

## 18. Test plan

`packages/core/tests/unit/test-ssh-multiplex.php` (new, pure-PHP, no live
SSH — mirrors `test-terminal.php`'s style, `ReflectionMethod`/
`ReflectionProperty` with `setAccessible(true)` for private statics, exactly
like `test-sftp-passphrase.php` already does for `buildSftpProvider()`):

- **F1 — multi-tenant cache-key collision**: two configs identical in
  host/port/username but differing in `password` → different hashes;
  differing only in `private_key` → different; differing only in
  `host_fingerprint` → different; differing only in `strict_algorithms` →
  different; fully identical configs → **same** hash (the "genuinely same
  login" case is allowed to collide, on purpose).
- **F2 — socket permissions**: call the (reflected) dir-creation helper
  against a fresh temp dir and assert the resulting directory mode is
  `0700`; assert the computed filename never contains `host`/`username`
  substrings; assert a deliberately long fake `FLUXFILES_STORAGE_PATH`
  causes the >100-byte guard to reject before ever calling `proc_open`.
- **F4 — password/passphrase disk falls back**: `multiplexEligible()`
  (reflected) returns `false` for: `ssh_multiplex` unset; `ssh_multiplex`
  true + no `private_key`; `ssh_multiplex` true + `private_key` +
  non-empty `private_key_passphrase`. Returns `true` only for `ssh_multiplex`
  true + `private_key` set + `private_key_passphrase` empty.
- **F5 — algorithm-list sync**: reflect `modernSshAlgorithmLists()`,
  `modernSshAlgorithms()`, and call the public `modernSshOpensshFlags()`;
  assert every kex/hostkey/cipher/mac name appearing in the OpenSSH flag
  strings is present in the phpseclib-shaped output's corresponding
  category and vice versa (set equality, not just non-empty) — this is the
  "assert both stay in sync" test §4.5 of the review calls for.
- **F6 — LRU eviction is pure and correct**: `SshMultiplexer::selectEvictions()`
  against a hand-built `$index` array (no real sockets/processes) — cap not
  yet reached → `[]`; exactly at cap+1 → evicts exactly the single
  oldest-`last_used` entry; several stale entries → evicts oldest-first in
  the right order.
- **known_hosts line format**: given a fake `getServerPublicHostKey()`
  return value, assert the written line is exactly `"<host> <keytype>
  <base64>\n"`.
- **env round-trip** (mirrors the existing `SFTP_STRICT_ALGORITHMS`/
  `SFTP_REQUIRE_HOST_KEY` tests in `test-sftp-passphrase.php`):
  `SFTP_MULTIPLEX=true` → `require config/disks.php` → `$disks['sftp']['ssh_multiplex'] === true`.

`packages/core/tests/integration/test-ssh-multiplex-live.php` (new,
env-gated like `test-sftp-live.php`/`test-s3-live.php` — skips cleanly with
no live SSH host configured, so it never blocks CI by default):

- **F3 — `ControlPersist` expiry**: set
  `FLUXFILES_SSH_MULTIPLEX_PERSIST=2`, run a command (cold connect, capture
  the socket file's mtime/inode), run a second command immediately (assert
  socket file unchanged → reused), sleep 3s, run a third command (assert a
  **new** socket file/inode → cold-reconnected, proving the old session did
  not linger past its persist window).
- **cold vs. reuse timing sanity**: first command against a fresh disk
  config takes measurably longer than the second (not a strict assertion on
  absolute ms, just "2nd ≤ 1st", to catch a regression where reuse silently
  isn't happening at all).
- **password-disk end-to-end fallback**: a password-only SFTP disk with
  `ssh_multiplex: true` still successfully runs a terminal command (via the
  phpseclib fallback) and never creates a socket file — asserted by listing
  `ssh-sockets/` before/after.

---

## 19. Out of scope (restated, per review §4.7)

- **`GitDeploy` is not wired to `DiskManager::multiplexHandle()`.** Two
  independent reasons, not just "the review said so": (a) it's
  overwhelmingly a single-`exec`-per-trigger action — §9's cold-connect cost
  (which, with a pinned `host_fingerprint`, is *two* handshakes, not one) is
  paid on every deploy and never amortized, since there's rarely a 2nd
  command in the same window to benefit; (b) it should be evaluated
  separately, with real usage data, per the review's recommendation — not
  bundled in "since the plumbing already exists." `GitDeploy.php` and its
  route in `index.php` are untouched by this spec.
- **The Flysystem SFTP adapter (`buildSftpAdapter()`, used by every
  browsing/list/upload/download/copy/move/chmod call) is not touched.**
  It's the highest-request-volume SFTP path in the app, each call is a
  single round trip rather than a multi-command session (the actual
  performance problem this feature solves doesn't exist there), and it's
  exactly where a wrong cache key (F1) would have the largest blast radius
  across the most tenants. `DiskManager::sftpConnection()` and
  `buildSftpAdapter()` keep their current signatures and behavior
  unchanged; `multiplexHandle()` is a wholly separate, additive method.

---

## 20. Open questions / residual trade-offs

- **F3's window is bounded, not closed**, by design (§6) — a rotated
  credential or pulled `allow_terminal` claim can still ride an already-open
  multiplexed session for up to `ControlPersist` seconds. This is the same
  order of magnitude as this codebase's existing short-TTL-JWT assumption,
  but it is a new instance of that assumption, not a pre-existing one:
  today, pulling `allow_terminal` from freshly-minted tokens takes effect
  the moment old tokens expire; with multiplexing on, a *credential rotation
  on the disk itself* additionally has to wait out the persist window even
  after every token is already expired/revoked, because the socket's trust
  boundary is the OS process, not the JWT.
- **Single-app-server assumption.** The socket dir/tracker file are local to
  one PHP-FPM host. A multi-node deployment behind a load balancer gets
  independent, uncoordinated multiplex caches per node (each node pays its
  own cold-connect cost, capped at its own LRU limit) — not a correctness
  bug (every node's cache key is still credential-hashed correctly), but the
  effective cap is `FLUXFILES_SSH_MULTIPLEX_MAX_SOCKETS` × node count, worth
  an operator knowing before setting a cluster-wide expectation.
- **`ssh` binary availability** is a hard runtime dependency for this path
  only (phpseclib needs nothing extra). Worth a startup-time (not
  per-request) sanity note in ops docs, though the code itself must not
  assume it's present — see §16's fallback-always posture.
- **`GitDeploy` revisit trigger**: if real usage data later shows
  multi-command sessions are common for deploys (e.g. an operator scripting
  several sequential SSH actions around one deploy trigger), this spec's
  §19 boundary should be revisited **through the review**, not silently
  extended.
