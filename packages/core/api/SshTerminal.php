<?php

declare(strict_types=1);

namespace FluxFiles;

use phpseclib3\Net\SSH2;

/**
 * Runs ONE shell command per call over an existing SSH/SFTP connection and
 * returns the combined output + the resulting working directory + exit code.
 *
 * This is a stateless command-runner, not a PTY: each call is independent, so
 * there is no live stdin and interactive TUIs (vim/top/nano) won't work. The
 * working directory is threaded back to the client (via a printed marker) and
 * replayed with `cd` on the next call, so `cd`/`pwd` feel persistent.
 *
 * SECURITY: this grants shell access as the SSH user. There is no real sandbox
 * here — a shell can't be safely restricted by filtering its input. The actual
 * boundary is the SSH account's OS permissions (use a least-privilege user).
 * The dangerous-command list below is an ACCIDENT guardrail (e.g. a fat-fingered
 * `rm -rf /`), not a defense against a malicious token holder. Gating
 * (allow_terminal claim, SFTP-only, kill-switch) lives in the route.
 */
class SshTerminal
{
    /** Cap the response so a `cat hugefile` can't blow up memory/the response. */
    public const MAX_OUTPUT = 2 * 1024 * 1024; // 2 MB

    /** Marker we print after a command to recover the (possibly changed) cwd. */
    private const CWD_MARK = '__FFCWD__';

    /**
     * Catastrophic patterns we warn on (UI double-confirm). Deliberately narrow
     * — this only catches the obvious "oops" cases, it is NOT a security filter.
     *
     * @var string[]
     */
    private const DANGEROUS = [
        // rm with a recursive flag AND a catastrophic target (/, ~, *, ., or any
        // absolute path). A named relative dir like `rm -rf node_modules` is NOT
        // flagged — only root/home/wildcard/absolute deletions are.
        '~\brm\b(?=[^\n]*\s-\w*r)(?=[^\n]*\s(/|\~|\*(\s|$)|\.(\s|$)))~i',
        '~--no-preserve-root~i',                           // rm --no-preserve-root
        '~\bmkfs\b|\bdd\b[^\n]*\bof=/dev/~i',              // format / overwrite a device
        '~>\s*/dev/(sd|nvme|hd|disk)~i',                   // write to a raw disk
        '~\bchmod\s+-\w*R\w*\s+0?777\s+/~i',               // chmod -R 777 /
        '~:\s*\(\s*\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:~', // fork bomb :(){ :|:& };:
        '~\b(shutdown|reboot|halt|poweroff)\b|\binit\s+0~i', // power state
        '~\bmv\s+[^\n]*\s+/dev/null\b~i',                  // mv something -> /dev/null
    ];

    /**
     * True if a command matches a catastrophic pattern (UI should double-confirm).
     */
    public static function isDangerous(string $cmd): bool
    {
        foreach (self::DANGEROUS as $re) {
            if (preg_match($re, $cmd)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the client-supplied working directory against the SFTP root. The
     * client tracks cwd relative to the disk root (like the file manager), so:
     *   ''            → the root itself (or '.' when no root)
     *   'html', 'a/b' → anchored under the root ($root/html) — NOT the SSH login
     *                   home, or `cd html` would run from /root and 404
     *   '/abs/path'   → used as-is (a `pwd`-reported absolute cwd from a prior run)
     */
    public static function resolveCwd(string $cwd, string $root): string
    {
        if ($cwd === '') {
            return $root !== '' ? $root : '.';
        }
        if ($cwd[0] === '/') {
            return $cwd;
        }
        return ($root !== '' ? rtrim($root, '/') . '/' : '') . $cwd;
    }

    /**
     * Probe whether the host actually grants an interactive-capable shell. Many
     * shared hosts allow SFTP but force `internal-sftp` / a `ForceCommand`, so
     * exec() returns nothing or the forced command's output. Returns true only
     * when our echo round-trips.
     */
    public static function shellAvailable(SSH2 $ssh): bool
    {
        try {
            $ssh->setTimeout(8);
            $out = $ssh->exec('echo __ff_shell_ok__');
            return is_string($out) && strpos($out, '__ff_shell_ok__') !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Run $cmd in $cwd. Combines stdout+stderr, recovers the resulting cwd so
     * `cd` persists across calls, and caps the output.
     *
     * @return array{output:string,cwd:string,exit:int,truncated:bool,shell_ok:bool}
     *         `shell_ok` is false when the host produced no shell (forced command /
     *         SFTP-only) — the caller then reports `terminal_no_shell`.
     */
    public static function run(SSH2 $ssh, string $cmd, string $cwd, int $timeout): array
    {
        $cwd = $cwd !== '' ? $cwd : '.';
        // cd into cwd, run the user's command (merging stderr), then print the
        // current directory behind a marker so the client can track `cd`. The
        // command itself is intentionally NOT escaped (arbitrary shell is the
        // point); only $cwd is escaped to stop path-based injection.
        $wrapped = 'cd ' . escapeshellarg($cwd) . ' && { ' . $cmd . '; } 2>&1; '
                 . '__ff_rc=$?; printf "\n' . self::CWD_MARK . '%s\n" "$(pwd)"; exit $__ff_rc';

        $ssh->setTimeout(max(1, $timeout));
        $raw = $ssh->exec($wrapped);
        if (!is_string($raw)) {
            $raw = '';
        }
        $exit = $ssh->getExitStatus();

        // Recover the resulting cwd from the marker, then strip the marker line.
        // The marker is printed unconditionally by our wrapper on any real shell,
        // so its PRESENCE doubles as a "a shell actually ran this" signal — a host
        // that forces a command / allows only SFTP never produces it.
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

        return [
            'output'    => $raw,
            'cwd'       => $newCwd,
            'exit'      => is_int($exit) ? $exit : 0,
            'truncated' => $truncated,
            'shell_ok'  => $shellOk,
        ];
    }
}
