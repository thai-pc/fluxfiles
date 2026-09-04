<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * SSH ControlMaster (OpenSSH connection reuse) for `SshTerminal`'s
 * `/api/fm/terminal` path ONLY — see docs/SFTP-CONTROLMASTER-SPEC.md (follow-up
 * to docs/SFTP-CONTROLMASTER-SECURITY-REVIEW.md). Never wired into `GitDeploy`
 * or the Flysystem SFTP adapter (browsing/upload/download) — see the spec's §19.
 *
 * phpseclib3 has no concept of ControlMaster, so this shells out to the real
 * `ssh` binary via `proc_open` (array argv only, no shell) — a second, narrow
 * SSH client stack that exists purely to multiplex an already-authorized
 * terminal session across commands. Every failure mode here (missing `ssh`
 * binary, an unusable socket dir, a `proc_open` failure, a cold-connect
 * timeout, a dead tracked socket) is swallowed and falls back to the existing
 * per-request phpseclib connection for that one request — logged via
 * `error_log()`, never surfaced as a new client-facing error (§16 of the spec).
 * A genuine phpseclib auth/host-key failure (thrown while materializing
 * known_hosts, see `ensureKnownHosts()`) is the one exception: that propagates
 * exactly like the plain phpseclib path would, since it's a real security
 * failure, not an infrastructure hiccup.
 *
 * Cache key = a hash of the full resolved credential material (§4), so two
 * BYOB tenants (or a tenant and the operator's own static disk) can never
 * share a socket unless every auth-relevant field is byte-identical. Eligible
 * ONLY for key-based auth with no passphrase (`DiskManager::multiplexEligible()`)
 * — password auth and passphrase-protected keys have no non-interactive way to
 * authenticate the real `ssh` binary without putting a secret in argv/env.
 */
final class SshMultiplexer
{
    /** @var string|null|false memoized resolved `ssh` binary path (false = not looked up yet). */
    private static $sshBinary = false;

    private array $cfg;
    private string $diskName;
    private DiskManager $diskManager;
    private string $cacheKeyHash;

    private function __construct(array $cfg, string $diskName, DiskManager $diskManager)
    {
        $this->cfg = $cfg;
        $this->diskName = $diskName;
        $this->diskManager = $diskManager;
        $this->cacheKeyHash = self::cacheKey($cfg);
    }

    /**
     * The single entry point `DiskManager::multiplexHandle()` calls once eligibility
     * is already confirmed. Cheap and never fails on its own — all the actual I/O
     * (socket dir, proc_open, known_hosts) is deferred to run(), so this can't leave
     * behind any state just from being constructed.
     */
    public static function acquire(array $cfg, string $diskName, DiskManager $diskManager): self
    {
        return new self($cfg, $diskName, $diskManager);
    }

    /**
     * Run $cmd in $cwd, reusing an already-open ControlMaster socket when one exists
     * and is alive, cold-connecting (and registering a new socket) otherwise. Falls
     * back to the plain phpseclib connection on any infrastructure failure.
     *
     * @return array{output:string,cwd:string,exit:int,truncated:bool,shell_ok:bool}
     *         Same shape as `SshTerminal::run()` — see its docblock.
     */
    public function run(string $cmd, string $cwd, int $timeout): array
    {
        self::maybeSweep();

        $wrapped = SshTerminal::buildWrappedCommand($cmd, $cwd);
        try {
            $result = $this->tryMultiplexed($wrapped, $cwd, $timeout);
            if ($result !== null) {
                return $result;
            }
        } catch (\Throwable $e) {
            if ($e instanceof ApiException || $e instanceof \League\Flysystem\FilesystemException) {
                // A real phpseclib auth/host-key failure from ensureKnownHosts()'s
                // piggybacked sftpConnection() call — either our own ApiException
                // (require_host_key/config errors) or, on a pinned-fingerprint
                // mismatch, League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost
                // (implements the FilesystemException marker interface, not
                // ApiException). Either way this is a genuine security error, not
                // an infra hiccup — surface it exactly like the plain phpseclib
                // path would, don't mask it as a fallback-worthy failure.
                throw $e;
            }
            error_log('FluxFiles: SSH multiplex failed, falling back to phpseclib — ' . $e->getMessage());
        }

        // Fallback: identical to the non-multiplexed branch in index.php. $cwd is
        // already resolved against the same disk root either path would use.
        [$conn] = $this->diskManager->sftpConnection($this->diskName);
        return SshTerminal::run($conn, $cmd, $cwd, $timeout);
    }

    // ---------------------------------------------------------------------
    // §4 — cache key
    // ---------------------------------------------------------------------

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

    // ---------------------------------------------------------------------
    // §5 — socket dir / filename
    // ---------------------------------------------------------------------

    /** Base runtime dir for all multiplex state — local-server-only, never `_fluxfiles/`. */
    private static function runtimeDir(): string
    {
        $base = rtrim($_ENV['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage'), '/');
        return $base . '/ssh-sockets';
    }

    private static function socketPath(string $cacheKeyHash, string $socketDir): string
    {
        return $socketDir . '/' . substr($cacheKeyHash, 0, 20) . '.sock';
    }

    /**
     * mkdir(0700) + refuse a pre-existing directory we don't own — same defensive
     * pattern as `OidcDiscovery::isOwnedByUs()`'s JWKS cache dir guard, since
     * FLUXFILES_STORAGE_PATH's default fallback is safe but an operator-supplied
     * path could theoretically be a pre-existing, loosely-permissioned directory.
     */
    private static function ensureOwnedDir(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        return self::isOwnedByUs($dir);
    }

    /** True if $path is owned by the current process user, or ownership can't be determined. */
    private static function isOwnedByUs(string $path): bool
    {
        $mine = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if ($mine === false) {
            return true;
        }
        $owner = @fileowner($path);
        return $owner !== false && $owner === $mine;
    }

    // ---------------------------------------------------------------------
    // §9 — known_hosts (piggybacks on phpseclib's own verified connection)
    // ---------------------------------------------------------------------

    /**
     * @return string|null the UserKnownHostsFile path, or null when the runtime dir
     *         is unusable (infra failure → caller falls back to phpseclib).
     * @throws ApiException propagated unmodified when a pinned host_fingerprint
     *         doesn't match the offered key — a real security failure, not an
     *         infra hiccup, so it must NOT be swallowed into a silent fallback.
     */
    private static function ensureKnownHosts(array $cfg, DiskManager $diskManager, string $diskName): ?string
    {
        $dir = self::runtimeDir() . '/known_hosts';
        if (!self::ensureOwnedDir($dir)) {
            error_log('FluxFiles: SSH multiplex known_hosts dir unusable — ' . $dir);
            return null;
        }
        $fp = trim((string) ($cfg['host_fingerprint'] ?? ''));
        $path = $dir . '/' . hash('sha256', ($cfg['host'] ?? '') . ':' . ($cfg['port'] ?? 22)) . '.khosts';

        if ($fp !== '') {
            // Fingerprint pinned → get an ALREADY-VERIFIED key from phpseclib (it
            // throws before this line if the offered key doesn't match $fp), never
            // trust a second, separately-fetched key blindly.
            [$conn] = $diskManager->sftpConnection($diskName); // throws on mismatch
            $keyLine = $conn->getServerPublicHostKey(); // "<type> <base64>"
            // Regenerated fresh every cold connect — cheap, avoids stale-file
            // management, and each write is itself re-verified via the throw above.
            file_put_contents($path, ($cfg['host'] ?? '') . ' ' . $keyLine . "\n");
            chmod($path, 0600);
            return $path; // caller uses StrictHostKeyChecking=yes
        }

        // No fingerprint pinned → same "trust whatever's offered" default posture
        // phpseclib already has without one — but pin-on-first-sight (TOFU) into a
        // PERSISTENT per-disk file, avoiding the double-handshake cost above on
        // every cold connect.
        if (!is_file($path)) {
            touch($path);
            chmod($path, 0600);
        }
        return $path; // caller uses StrictHostKeyChecking=accept-new
    }

    /** Ephemeral private-key file, written just before proc_open, 0600, keys/ subdir. */
    private static function ensureKeyFile(string $privateKey, string $dir): ?string
    {
        if (!self::ensureOwnedDir($dir)) {
            return null;
        }
        $path = $dir . '/' . bin2hex(random_bytes(16)) . '.pem';
        if (@file_put_contents($path, $privateKey) === false) {
            return null;
        }
        @chmod($path, 0600);
        return $path;
    }

    // ---------------------------------------------------------------------
    // Env-tunable config (§2.2)
    // ---------------------------------------------------------------------

    private static function envInt(string $key, int $default): int
    {
        $raw = $_ENV[$key] ?? getenv($key);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }
        return (int) $raw;
    }

    /** ControlPersist seconds, clamped [10,120] — a bad value never breaks the server. */
    private static function persistSeconds(): int
    {
        return max(10, min(120, self::envInt('FLUXFILES_SSH_MULTIPLEX_PERSIST', 60)));
    }

    /** Server-wide LRU cap on concurrently-open multiplexed sockets. */
    private static function maxSockets(): int
    {
        return max(1, self::envInt('FLUXFILES_SSH_MULTIPLEX_MAX_SOCKETS', 20));
    }

    /** Seconds a cold `ssh -M` connect + first command gets before it's killed. */
    private static function connectTimeout(): int
    {
        return max(1, self::envInt('FLUXFILES_SSH_MULTIPLEX_CONNECT_TIMEOUT', 10));
    }

    // ---------------------------------------------------------------------
    // §11 — the multiplex attempt itself
    // ---------------------------------------------------------------------

    /** @return array{output:string,cwd:string,exit:int,truncated:bool,shell_ok:bool}|null null → caller falls back to phpseclib. */
    private function tryMultiplexed(string $wrapped, string $cwd, int $timeout): ?array
    {
        if (self::sshBinary() === null) {
            return null;
        }
        $socketDir = self::runtimeDir();
        if (!self::ensureOwnedDir($socketDir)) {
            error_log('FluxFiles: SSH multiplex socket dir unusable — ' . $socketDir);
            return null;
        }
        $socketPath = self::socketPath($this->cacheKeyHash, $socketDir);
        // sun_path is 108 bytes on Linux, 104 on BSD/macOS — 100 keeps a 5-byte
        // margin below the tightest platform limit instead of letting proc_open
        // fail later with an opaque "bind: File name too long".
        if (strlen($socketPath) > 100) {
            error_log(
                'FluxFiles: SSH multiplex socket path too long (' . strlen($socketPath)
                . ' bytes) — set FLUXFILES_STORAGE_PATH shorter. See docs/SFTP-CONTROLMASTER-SPEC.md'
            );
            return null;
        }

        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 22);
        $username = (string) ($this->cfg['username'] ?? '');

        $entry = self::lookupEntry($socketDir, $this->cacheKeyHash);
        if ($entry !== null) {
            if (self::checkAlive($socketPath, $host)) {
                self::touchLastUsed($socketDir, $this->cacheKeyHash);
                $result = $this->execReuse($socketPath, $host, $port, $username, $wrapped, $cwd, $timeout);
                if ($result !== null) {
                    return $result;
                }
                // Reuse failed right after a successful liveness check (rare race) —
                // drop it and fall through to a fresh cold connect below.
            }
            // Tracked but dead (the normal case — it already self-expired via
            // ControlPersist) or just failed reuse: forget it either way.
            self::forgetSocket($socketDir, $this->cacheKeyHash, $socketPath);
        }

        return $this->execCold($socketDir, $socketPath, $host, $port, $username, $wrapped, $cwd);
    }

    /** §11.1 — cold connect + first command. */
    private function execCold(
        string $socketDir,
        string $socketPath,
        string $host,
        int $port,
        string $username,
        string $wrapped,
        string $cwd
    ): ?array {
        $ssh = self::sshBinary();
        if ($ssh === null) {
            return null;
        }
        $keyFile = self::ensureKeyFile((string) ($this->cfg['private_key'] ?? ''), self::runtimeDir() . '/keys');
        if ($keyFile === null) {
            error_log('FluxFiles: SSH multiplex could not write a temp key file');
            return null;
        }

        try {
            // May throw ApiException on a real host-key mismatch — let it propagate
            // (see run()'s catch), don't swallow a genuine security failure.
            $knownHosts = self::ensureKnownHosts($this->cfg, $this->diskManager, $this->diskName);
            if ($knownHosts === null) {
                return null;
            }
            $fingerprintPinned = trim((string) ($this->cfg['host_fingerprint'] ?? '')) !== '';

            $args = [
                $ssh,
                '-M', '-S', $socketPath,
                '-o', 'ControlPersist=' . self::persistSeconds(),
                '-o', 'BatchMode=yes',                 // never prompt — fail fast instead
                '-o', 'ConnectTimeout=' . self::connectTimeout(),
                '-o', 'IdentitiesOnly=yes',            // only the -i key, never ssh-agent/defaults
                '-o', 'PasswordAuthentication=no',
                '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'StrictHostKeyChecking=' . ($fingerprintPinned ? 'yes' : 'accept-new'),
                '-o', 'UserKnownHostsFile=' . $knownHosts,
                '-o', 'GlobalKnownHostsFile=/dev/null', // never consult the system-wide file either
            ];
            if (!empty($this->cfg['strict_algorithms'])) {
                $args = array_merge($args, DiskManager::modernSshOpensshFlags());
            }
            $args = array_merge($args, [
                '-p', (string) $port,
                '-i', $keyFile, // §7: only reached when private_key set, passphrase empty
                '-l', $username,
                $host,
                '--',
                $wrapped,
            ]);

            $r = self::execProcess($args, self::connectTimeout());
        } finally {
            // The private key touches disk only for this invocation's lifetime —
            // the process has already read it during its own auth phase by now.
            @unlink($keyFile);
        }

        $parsed = SshTerminal::parseOutput($r['stdout'], $r['exit'], $cwd);
        if (!$parsed['shell_ok']) {
            // Never got far enough to print our marker. This isn't necessarily an
            // auth failure — an entirely ordinary SFTP-only/ForceCommand/no-shell
            // account authenticates fine and still never echoes the marker, and
            // `ssh -M` has ALREADY forked a real, persistent ControlPersist master
            // by the time we get here. Since we're about to return null (no
            // registerSocket() call), that master would otherwise be invisible to
            // selectEvictions()'s LRU cap and maybeSweep() — tear it down now so a
            // shell-less disk can't accumulate untracked sockets outside the cap.
            self::teardown($socketPath, $host);
            @unlink($socketPath);
            return null;
        }

        self::registerSocket($this->cacheKeyHash, $socketPath, $this->diskName, $host);
        return $parsed;
    }

    /** §11.2 — reuse (2nd+ call against an already-alive socket). */
    private function execReuse(
        string $socketPath,
        string $host,
        int $port,
        string $username,
        string $wrapped,
        string $cwd,
        int $timeout
    ): ?array {
        $args = [
            self::sshBinary(),
            '-S', $socketPath,
            '-o', 'BatchMode=yes',
            '-p', (string) $port, '-l', $username, $host,
            '--',
            $wrapped,
        ];
        // Per-command timeout (the terminal's existing FLUXFILES_TERMINAL_TIMEOUT) —
        // distinct from the cold-connect timeout in execCold(). No key file, no
        // known-hosts/algorithm flags: a live multiplexed sub-connection inherits
        // the master's already-negotiated session, which is correct — the trust
        // decision was made once, at cold-connect.
        $r = self::execProcess($args, max(1, $timeout));
        $parsed = SshTerminal::parseOutput($r['stdout'], $r['exit'], $cwd);
        return $parsed['shell_ok'] ? $parsed : null;
    }

    /** §11.3 — liveness check before trusting a tracked socket as reusable. */
    private static function checkAlive(string $socketPath, string $host): bool
    {
        $ssh = self::sshBinary();
        if ($ssh === null || $host === '' || !file_exists($socketPath)) {
            return false;
        }
        $r = self::execProcess([$ssh, '-S', $socketPath, '-O', 'check', $host], 5);
        return !$r['timed_out'] && $r['exit'] === 0;
    }

    /** §11.4 — explicit teardown (LRU eviction, sweep). Best-effort. */
    private static function teardown(string $socketPath, string $host): void
    {
        $ssh = self::sshBinary();
        if ($ssh === null || $host === '') {
            return;
        }
        // Failure just means @unlink cleans up the socket file anyway.
        self::execProcess([$ssh, '-S', $socketPath, '-O', 'exit', $host], 5);
    }

    /**
     * Generic argv proc_open runner — no shell, same poll/timeout/proc_terminate
     * discipline as `PdfOptimizer::optimize()`. Local ssh's own stderr (banners,
     * diagnostics) is discarded: success/failure is derived from the wrapped
     * command's marker (via SshTerminal::parseOutput()), not from ssh's stderr.
     *
     * @return array{stdout:string,exit:int,timed_out:bool}
     */
    private static function execProcess(array $args, int $timeoutSec): array
    {
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = @proc_open($args, $desc, $pipes);
        if (!is_resource($proc)) {
            return ['stdout' => '', 'exit' => -1, 'timed_out' => false];
        }
        stream_set_blocking($pipes[1], false);

        $stdout = '';
        $start = microtime(true);
        $timedOut = false;
        $exit = -1;
        while (true) {
            $stdout .= (string) @stream_get_contents($pipes[1]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Capture the exit code HERE: polling proc_get_status reaps the
                // child, so a later proc_close() would return -1, not the real code.
                $exit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) - $start > $timeoutSec) {
                proc_terminate($proc, 9);
                $timedOut = true;
                break;
            }
            usleep(50_000);
        }
        $stdout .= (string) @stream_get_contents($pipes[1]); // drain whatever was buffered at exit
        fclose($pipes[1]);
        proc_close($proc);

        return ['stdout' => $stdout, 'exit' => $exit, 'timed_out' => $timedOut];
    }

    /** Locate the `ssh` binary, or null when it isn't installed. */
    private static function sshBinary(): ?string
    {
        if (self::$sshBinary !== false) {
            return self::$sshBinary;
        }
        foreach (['/usr/bin/ssh', '/usr/local/bin/ssh', '/opt/homebrew/bin/ssh', '/bin/ssh'] as $cand) {
            if (@is_executable($cand)) {
                return self::$sshBinary = $cand;
            }
        }
        // `command -v ssh` carries no user input, so this is safe.
        $found = @trim((string) @shell_exec('command -v ssh 2>/dev/null'));
        return self::$sshBinary = ($found !== '' && @is_executable($found)) ? $found : null;
    }

    // ---------------------------------------------------------------------
    // §10 — LRU tracker (index.json)
    // ---------------------------------------------------------------------

    /**
     * Oldest-first cache keys to evict so the index doesn't exceed $cap after
     * adding one more. Pure — no I/O, testable against a hand-built $index.
     *
     * @return string[]
     */
    public static function selectEvictions(array $index, int $cap): array
    {
        if (count($index) < $cap) {
            return [];
        }
        $byAge = $index;
        uasort($byAge, fn ($a, $b) => $a['last_used'] <=> $b['last_used']);
        return array_slice(array_keys($byAge), 0, count($index) - $cap + 1);
    }

    /**
     * I/O wrapper: tears down ($ssh -O exit) + removes the tracker entry +
     * @unlink()s the socket for $cacheKey, if one exists. Used both by LRU
     * eviction (registerSocket()) and the opportunistic sweep (maybeSweep()).
     */
    public static function evict(string $cacheKey): void
    {
        $socketDir = self::runtimeDir();
        $indexPath = $socketDir . '/index.json';
        $entry = null;
        $fp = @fopen($indexPath, 'c+');
        if ($fp !== false) {
            try {
                if (flock($fp, LOCK_EX)) {
                    $raw = stream_get_contents($fp);
                    $data = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : [];
                    if (is_array($data) && isset($data[$cacheKey])) {
                        $entry = $data[$cacheKey];
                        unset($data[$cacheKey]);
                        ftruncate($fp, 0);
                        rewind($fp);
                        fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
                        fflush($fp);
                    }
                    flock($fp, LOCK_UN);
                }
            } finally {
                fclose($fp);
            }
        }
        if (!is_array($entry)) {
            return;
        }
        $socket = (string) ($entry['socket'] ?? '');
        if ($socket === '') {
            return;
        }
        self::teardown($socket, (string) ($entry['host'] ?? ''));
        @unlink($socket);
    }

    /** Read-only, best-effort lookup — never blocks on a lock. */
    private static function lookupEntry(string $socketDir, string $cacheKeyHash): ?array
    {
        $data = self::readIndexBestEffort($socketDir);
        return $data[$cacheKeyHash] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    private static function readIndexBestEffort(string $socketDir): array
    {
        $path = $socketDir . '/index.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** Drop $cacheKeyHash from the tracker (if present) + @unlink the socket file. */
    private static function forgetSocket(string $socketDir, string $cacheKeyHash, string $socketPath): void
    {
        $indexPath = $socketDir . '/index.json';
        $fp = @fopen($indexPath, 'c+');
        if ($fp !== false) {
            try {
                if (flock($fp, LOCK_EX)) {
                    $raw = stream_get_contents($fp);
                    $data = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : [];
                    if (is_array($data) && isset($data[$cacheKeyHash])) {
                        unset($data[$cacheKeyHash]);
                        ftruncate($fp, 0);
                        rewind($fp);
                        fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
                        fflush($fp);
                    }
                    flock($fp, LOCK_UN);
                }
            } finally {
                fclose($fp);
            }
        }
        @unlink($socketPath);
    }

    private static function touchLastUsed(string $socketDir, string $cacheKeyHash): void
    {
        $indexPath = $socketDir . '/index.json';
        $fp = @fopen($indexPath, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($fp);
            $data = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : [];
            if (is_array($data) && isset($data[$cacheKeyHash])) {
                $data[$cacheKeyHash]['last_used'] = time();
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Registers a freshly cold-connected socket in the tracker, evicting the
     * LRU-oldest entry/entries first when the cap would otherwise be exceeded
     * (§10 — "called right before inserting a new entry that would exceed the cap").
     */
    private static function registerSocket(string $cacheKeyHash, string $socketPath, string $diskName, string $host): void
    {
        $socketDir = self::runtimeDir();
        foreach (self::selectEvictions(self::readIndexBestEffort($socketDir), self::maxSockets()) as $key) {
            self::evict($key);
        }

        $indexPath = $socketDir . '/index.json';
        $isNew = !file_exists($indexPath);
        $fp = @fopen($indexPath, 'c+');
        if ($fp === false) {
            return; // best-effort — a missed tracker entry just means it won't be reused next time
        }
        if ($isNew) {
            @chmod($indexPath, 0600);
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($fp);
            $data = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            $now = time();
            $data[$cacheKeyHash] = [
                'socket'     => $socketPath,
                'disk'       => $diskName,
                'host'       => $host,
                'created_at' => $now,
                'last_used'  => $now,
            ];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    // ---------------------------------------------------------------------
    // §6 item 3 — opportunistic sweep, 1-in-20 (same probability DemoMode::purge()
    // already uses for its own opportunistic cleanup)
    // ---------------------------------------------------------------------

    private static function maybeSweep(): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }
        $socketDir = self::runtimeDir();
        $index = self::readIndexBestEffort($socketDir);
        if ($index === []) {
            return;
        }
        $now = time();
        $grace = self::persistSeconds() + 5;
        foreach ($index as $cacheKey => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $lastUsed = (int) ($entry['last_used'] ?? 0);
            if ($now - $lastUsed <= $grace) {
                continue; // still within its expected ControlPersist window
            }
            $socket = (string) ($entry['socket'] ?? '');
            $host = (string) ($entry['host'] ?? '');
            if ($socket !== '' && $host !== '' && self::checkAlive($socket, $host)) {
                // Still alive past its expected expiry (clock skew, a slow-to-exit
                // master) — force it. The normal case is checkAlive() is already
                // false here (the master self-expired via ControlPersist).
                self::teardown($socket, $host);
            }
            // Drop the stale entry + clean up any leftover socket file either way
            // (defends against a rare OOM-killed master that didn't clean up its own).
            self::evict((string) $cacheKey);
        }
    }
}
