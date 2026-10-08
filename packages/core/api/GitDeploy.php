<?php

declare(strict_types=1);

namespace FluxFiles;

use phpseclib3\Net\SSH2;

/**
 * One-click Git deploy — a fixed-command-shape, single-exec sync of a repo on an
 * SFTP disk. Deliberately NARROWER than SshTerminal: no free-form command, no
 * client-supplied path/remote/branch — those are OPERATOR claims baked into the
 * JWT at mint time (Claims::$gitDeployPath / $gitDeployBranch), never read from
 * the request body. See docs/security/GIT-DEPLOY-SECURITY-REVIEW.md for the threat model
 * this design closes (F1 command/remote injection, F2 hostile hooks, F6 races).
 *
 * SECURITY:
 *  - The assembled command is built from escapeshellarg()'d, claim-supplied
 *    values only — never string-concatenated with anything the request body
 *    could influence (unlike SshTerminal, where arbitrary shell IS the point).
 *  - Hooks are neutered by default (`core.hooksPath=/dev/null`) since a hostile
 *    `post-merge` hook in the deployed repo is otherwise arbitrary code execution
 *    independent of any FluxFiles bug. Opt back in per-tenant via the
 *    `git_deploy_hooks` claim.
 *  - Concurrency is serialized with an `mkdir`-based lock DIRECTORY inside the
 *    repo itself (atomic at the filesystem level, and visible to every FluxFiles
 *    app instance since it lives on the SFTP disk, not local PHP state) rather
 *    than a local file lock, matching the "metadata travels with user storage"
 *    rule. Staleness is liveness-checked, not just time-checked: the lock
 *    owner writes its own PID into `$L/pid` right after acquiring the lock,
 *    and a new invocation that finds the lock held asks `kill -0` whether
 *    that PID is still alive on the remote host BEFORE ever treating the
 *    lock as abandoned — a live owner is refused outright, no matter how old
 *    the lock looks (`FLUXFILES_GIT_DEPLOY_TIMEOUT` is routinely raised past
 *    LOCK_STALE_MINUTES for slow LFS/submodule fetches, so age alone let a
 *    second trigger steal the lock from a deploy that was still running).
 *    Only when the PID is confirmed dead (or missing/unreadable, the shape
 *    of a lock from a pre-liveness-check version) does the `-mmin` age check
 *    run, now as a secondary sanity check rather than the sole signal,
 *    before the lock is reclaimed.
 *  - Gating (allow_git_deploy claim, SFTP-only, kill-switch, write perm, rate
 *    limit) lives in the route, mirroring SshTerminal.
 */
class GitDeploy
{
    /** Cap the response, same reasoning as SshTerminal::MAX_OUTPUT. */
    public const MAX_OUTPUT = 2 * 1024 * 1024; // 2 MB

    /** Directory name of the deploy lock, created inside the repo path. */
    private const LOCK_NAME = '.fluxfiles-deploy.lock';

    /** A lock directory older than this is assumed abandoned and reclaimed. */
    private const LOCK_STALE_MINUTES = 5;

    /** Printed before the sync so run() can tell a real shell from a forced command. */
    private const SHELL_OK_MARK = '__ffdeploy_shell_ok__';

    /** Printed by the lock guard when a deploy is already in progress. */
    private const LOCKED_MARK = '__ffdeploy_locked__';

    /** Printed by the config audit when the repo's own .git/config is hostile. */
    private const UNSAFE_MARK = '__ffdeploy_unsafe_config__';

    /**
     * ALLOWLIST (not a denylist) of config keys an ordinary `git init`/`git
     * clone`/`git checkout -b` actually produces. Git's exec-from-config
     * surface (filter.*, credential.*, core.askPass, core.hooksPath,
     * core.gitProxy, core.pager/editor/sshCommand/fsmonitor, alias.*,
     * diff.*.textconv, uploadpack.*, receive.*, include.path/includeIf.*,
     * …) is large and grows per release, and three independent bypasses of
     * an earlier denylist form of this check were found in one review
     * (`include.path`, `extensions.worktreeConfig` + a worktree-scoped
     * filter, and `core.askPass`/`core.hooksPath` simply not being listed).
     * A denylist can only ever cover the keys someone thought of; an
     * allowlist fails closed on anything unexpected, including a brand new
     * git feature nobody on this project has heard of yet.
     *
     * Checked against `git config --list --show-scope --name-only`, scoped
     * to the `local` and `worktree` rows only (see configAuditCommand() for
     * why both scopes and why `--show-scope` instead of `--local`). The key
     * part of a three-segment key (e.g. the `<anything>` in
     * `filter.<anything>.smudge`, or the remote/branch name below) is
     * attacker-chosen and NOT required to be ASCII-identifier-shaped, so the
     * `[^.]+` segments intentionally do not further restrict its charset —
     * the point of the allowlist is the key's *section/leaf*, not its name.
     */
    private const SAFE_CONFIG_RE = '^(core\\.(repositoryformatversion|filemode|bare|logallrefupdates'
        . '|ignorecase|precomposeunicode|symlinks|autocrlf|safecrlf|sparsecheckout|worktree)'
        . '|remote\\.[^.]+\\.(url|fetch|pushurl|tagopt|prune|mirror)'
        . '|branch\\.[^.]+\\.(remote|merge|rebase)'
        . '|submodule\\.active|pull\\.ff|push\\.default|init\\.defaultbranch|user\\.(name|email))$';

    /**
     * Build the fixed shell command: acquire the lock, run the git sync, release
     * the lock. Every variable piece ($path, $branch) is escapeshellarg()'d — this
     * is NOT a free-form command builder like SshTerminal::run().
     *
     * $branch === '' → `git pull --ff-only` on whatever branch is checked out
     * (safe: refuses if the local branch has diverged, never rewrites history).
     * $branch !== '' → `git fetch --prune && git reset --hard origin/<branch>`
     * (a forced sync to a known-good ref — the destructive form, so it only runs
     * when the operator has explicitly named a branch in the claim).
     */
    public static function buildCommand(string $path, string $branch, bool $hooksEnabled): string
    {
        $p = escapeshellarg($path);
        $lockDir = escapeshellarg(rtrim($path, '/') . '/' . self::LOCK_NAME);

        // Config-driven exec, neutered UNCONDITIONALLY — the git_deploy_hooks
        // claim opts into *hooks*, not into arbitrary command execution, and
        // every one of these is read from the repo's own .git/config, which is
        // an ordinary extensionless file any write-scoped token can overwrite
        // (assertExt/assertSafeFilename do not stop it: no extension means
        // DANGEROUS_EXTENSIONS never fires, and assertNotSystem only guards
        // _fluxfiles/ and _variants/). Without them, a file-write in the repo
        // escalates to RCE as the SSH user even with hooks disabled: `git pull`
        // runs core.fsmonitor and core.sshCommand, and the ext/file transports
        // run whatever a remote URL names.
        //
        // The transport list is an ALLOWLIST (`protocol.allow=never` + explicit
        // https/ssh), not a deny-list of the two transports we happened to think
        // of: `core.gitProxy` + a `remote.origin.url = git://…` makes `git fetch`
        // exec the named proxy binary, and `protocol.git.allow=never` is what
        // actually stops it ("fatal: transport 'git' not allowed", before the
        // proxy is ever spawned). `core.gitProxy=` is cleared too, so the same
        // repo config can't reach any other transport that honours it.
        $safety = '-c core.fsmonitor=false '
            . '-c core.sshCommand=ssh '
            . '-c protocol.ext.allow=never '
            . '-c protocol.file.allow=never '
            . '-c protocol.allow=never '
            . '-c protocol.https.allow=always '
            . '-c protocol.ssh.allow=always '
            . '-c core.gitProxy= '
            . '-c core.askPass= ';

        // core.hooksPath is pinned EITHER way, not just when hooks are
        // disabled: the config-audit allowlist below already refuses a repo
        // that sets it at all (it is not a safe key), but this is a second,
        // independent layer — if the allowlist regex ever had a gap, an
        // attacker-set core.hooksPath pointing at a directory of files they
        // also wrote would still be RCE even with git_deploy_hooks=true. When
        // hooks are enabled, pin it to the repo's OWN real hooks directory
        // (same effective behaviour as git's default, just not readable out
        // of .git/config) rather than leaving it unset.
        $hooksFlag = $safety . (
            $hooksEnabled
                ? '-c core.hooksPath=' . escapeshellarg(rtrim($path, '/') . '/.git/hooks') . ' '
                : '-c core.hooksPath=' . escapeshellarg('/dev/null') . ' '
        );

        $sync = $branch !== ''
            ? sprintf(
                'git -C %s %sfetch --prune origin && git -C %s %sreset --hard %s',
                $p,
                $hooksFlag,
                $p,
                $hooksFlag,
                escapeshellarg('origin/' . $branch)
            )
            : sprintf('git -C %s %spull --ff-only', $p, $hooksFlag);

        // `find -mmin` (not `stat`, whose flag differs between GNU and BSD/macOS)
        // is the portable way to check a directory's age across the SSH hosts
        // this might run on.
        //
        // Lock-held branch: liveness first (kill -0 on the PID recorded in
        // $L/pid), age check only as a fallback for a dead/pid-less lock —
        // never the other way around, or a still-running deploy past
        // LOCK_STALE_MINUTES gets its lock stolen by a concurrent trigger.
        return 'L=' . $lockDir . '; '
            . 'if [ -d "$L" ]; then '
            // The PID comes from a file inside the repo, so it is
            // attacker-writable: validate it is digits-only before kill -0.
            // `kill -0 -1` returns 0 ("every process you may signal"), which
            // would make the lock read as held forever and short-circuit the
            // -mmin staleness reclaim below — wedging every later deploy.
            . 'P="$(cat "$L/pid" 2>/dev/null)"; '
            . 'case "$P" in (*[!0-9]*|"") P="";; esac; '
            . 'if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then '
            . 'echo ' . escapeshellarg(self::LOCKED_MARK) . '; exit 99; '
            . 'fi; '
            . 'if [ -z "$(find "$L" -maxdepth 0 -mmin +' . self::LOCK_STALE_MINUTES . ' 2>/dev/null)" ]; then '
            . 'echo ' . escapeshellarg(self::LOCKED_MARK) . '; exit 99; '
            . 'fi; '
            . 'rm -rf "$L" 2>/dev/null; '
            . 'fi; '
            . 'mkdir "$L" 2>/dev/null || { echo ' . escapeshellarg(self::LOCKED_MARK) . '; exit 99; }; '
            . 'echo "$$" > "$L/pid" 2>/dev/null; '
            . 'trap \'if [ "$(cat "$L/pid" 2>/dev/null)" = "$$" ]; then rm -rf "$L" 2>/dev/null; fi\' EXIT; '
            . self::configAuditCommand($path)
            . $sync . ' 2>&1';
    }

    /**
     * Fixed-shape pre-deploy audit of the repo's OWN config, emitted into the
     * same single exec right before the sync so it short-circuits BEFORE any
     * fetch/reset touches an attacker-controlled filter or transport.
     *
     * Uses `git config --list --show-scope --name-only`, NOT `--local`:
     * `--local` only reads `.git/config` and misses two real bypasses —
     * (1) `[include] path = /tmp/x.cfg` (or `includeIf.*`) in `.git/config`
     * pulls in an arbitrary second file whose keys `--local --list` never
     * prints, even though git itself expands and applies them; (2) a repo
     * with `extensions.worktreeConfig=true` additionally reads
     * `.git/config.worktree`, a second config file `--local` never looks
     * at either. `--show-scope` prints every applicable key regardless of
     * which file it came from, tagged `<scope>\t<key>` — so both bypasses
     * surface as ordinary `local`/`worktree` rows. Only the `local` and
     * `worktree` scopes are kept (the `grep -E '^(local|worktree)'` +
     * `cut -f2` pair; `cut`'s default delimiter is a tab, matching the
     * `--show-scope` output): `global`/`system` belong to the operator who
     * set up the VPS, not to anyone who can write a file through FluxFiles,
     * and must never fail a deploy.
     *
     * Each surviving key name (never a value — nothing attacker-supplied is
     * interpolated into the shell) is checked against SAFE_CONFIG_RE, an
     * ALLOWLIST: anything NOT on it aborts the deploy. `head -n1` keeps the
     * first offending key so the output can name it (operator diagnosis),
     * without needing every offender.
     */
    private static function configAuditCommand(string $path): string
    {
        $p = escapeshellarg($path);

        return 'UNSAFE_KEY="$(git -C ' . $p
            . ' config --list --show-scope --name-only 2>/dev/null'
            . ' | grep -E ' . escapeshellarg('^(local|worktree)')
            . ' | cut -f2'
            . ' | grep -viE ' . escapeshellarg(self::SAFE_CONFIG_RE)
            . ' | head -n1)"; '
            . 'if [ -n "$UNSAFE_KEY" ]; then '
            . 'echo ' . escapeshellarg(self::UNSAFE_MARK) . ' "$UNSAFE_KEY"; exit 98; '
            . 'fi; ';
    }

    /**
     * Run the deploy over an existing SSH connection.
     *
     * @return array{output:string,exit:int,truncated:bool,shell_ok:bool,locked:bool,unsafe_config:bool}
     *         `shell_ok` false means the host forces a command / is SFTP-only (same
     *         signal SshTerminal::run() derives — see its docblock). `locked` true
     *         means a concurrent deploy is already running against this path.
     *         `unsafe_config` true means the pre-deploy config audit refused the
     *         repo (see configAuditCommand()) — nothing was fetched or reset.
     */
    public static function run(SSH2 $ssh, string $path, string $branch, bool $hooksEnabled, int $timeout): array
    {
        $cmd = self::buildCommand($path, $branch, $hooksEnabled);
        $wrapped = 'echo ' . escapeshellarg(self::SHELL_OK_MARK) . '; { ' . $cmd . '; }';

        $ssh->setTimeout(max(1, $timeout));
        $raw = $ssh->exec($wrapped);
        if (!is_string($raw)) {
            $raw = '';
        }
        $exit = $ssh->getExitStatus();

        $shellOk = strpos($raw, self::SHELL_OK_MARK) !== false;
        $raw = (string) preg_replace('~^' . preg_quote(self::SHELL_OK_MARK, '~') . '\R?~', '', $raw, 1);
        $locked = strpos($raw, self::LOCKED_MARK) !== false;
        $unsafeConfig = strpos($raw, self::UNSAFE_MARK) !== false;

        $truncated = false;
        if (strlen($raw) > self::MAX_OUTPUT) {
            $raw = substr($raw, 0, self::MAX_OUTPUT);
            $truncated = true;
        }

        return [
            'output'    => $raw,
            'exit'      => is_int($exit) ? $exit : 0,
            'truncated' => $truncated,
            'shell_ok'  => $shellOk,
            'locked'    => $locked,
            'unsafe_config' => $unsafeConfig,
        ];
    }
}
