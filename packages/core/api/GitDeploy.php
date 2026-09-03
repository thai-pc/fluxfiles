<?php

declare(strict_types=1);

namespace FluxFiles;

use phpseclib3\Net\SSH2;

/**
 * One-click Git deploy — a fixed-command-shape, single-exec sync of a repo on an
 * SFTP disk. Deliberately NARROWER than SshTerminal: no free-form command, no
 * client-supplied path/remote/branch — those are OPERATOR claims baked into the
 * JWT at mint time (Claims::$gitDeployPath / $gitDeployBranch), never read from
 * the request body. See docs/GIT-DEPLOY-SECURITY-REVIEW.md for the threat model
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
 *    rule. A lock older than LOCK_STALE_MINUTES is treated as abandoned (the
 *    prior deploy's process died without its EXIT trap running) and reclaimed.
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
        $hooksFlag = $hooksEnabled ? '' : '-c core.hooksPath=' . escapeshellarg('/dev/null') . ' ';

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
        return 'L=' . $lockDir . '; '
            . 'if [ -d "$L" ] && [ -z "$(find "$L" -maxdepth 0 -mmin +' . self::LOCK_STALE_MINUTES . ' 2>/dev/null)" ]; then '
            . 'echo ' . escapeshellarg(self::LOCKED_MARK) . '; exit 99; '
            . 'fi; '
            . 'rm -rf "$L" 2>/dev/null; '
            . 'mkdir "$L" 2>/dev/null || { echo ' . escapeshellarg(self::LOCKED_MARK) . '; exit 99; }; '
            . 'trap \'rmdir "$L" 2>/dev/null\' EXIT; '
            . $sync . ' 2>&1';
    }

    /**
     * Run the deploy over an existing SSH connection.
     *
     * @return array{output:string,exit:int,truncated:bool,shell_ok:bool,locked:bool}
     *         `shell_ok` false means the host forces a command / is SFTP-only (same
     *         signal SshTerminal::run() derives — see its docblock). `locked` true
     *         means a concurrent deploy is already running against this path.
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
        ];
    }
}
