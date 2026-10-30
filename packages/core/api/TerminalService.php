<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * `POST /api/fm/terminal` — the SSH command-runner route body, shared by core and
 * the Laravel/WordPress proxies (which used to carry their own copy and lacked
 * the ControlMaster reuse).
 *
 * SFTP disks only. Stateless: one command per request; the cwd is threaded back
 * to the client. Hard-gated, in this order: the server kill-switch, the
 * `allow_terminal` claim, `write` perm, a disk the token may use, an SFTP disk
 * that actually grants a shell, and a double-confirm for catastrophic commands.
 * See .claude/rules/ssh-terminal-git-deploy.md.
 *
 * Auditing is NOT done here: the audit entry for a successful run is produced by
 * AuditPolicy::successEntry() (action `terminal`, detail = the command) from the
 * host's generic write-audit step, so it stays identical for every host and the
 * service has no logger to inject. Rate limiting is also the host's (core: its
 * own `terminal` bucket).
 */
final class TerminalService
{
    /**
     * Server kill-switch. Separate so a proxy can refuse BEFORE authenticating, as
     * they always have; run() re-checks, so core need not call it.
     *
     * @param array<string,mixed> $env $_ENV-style
     * @throws ApiException 403 `terminal_disabled`
     */
    public static function assertEnabled(array $env): void
    {
        if (($env['FLUXFILES_TERMINAL_DISABLED'] ?? '') === 'true') {
            throw new ApiException('The terminal is disabled on this server', 403, 'terminal_disabled');
        }
    }

    /**
     * @param array<string,mixed>|\Closure():array<string,mixed> $body the request
     *        body (`disk`, `cmd`, `cwd`, `confirm`), or a lazy provider invoked only
     *        after the kill-switch and claim gates pass — core's body parse throws
     *        `invalid_json`, and it must not pre-empt those 403s.
     * @param array<string,mixed> $env $_ENV-style (kill-switch, confirm, timeout, multiplex)
     * @return array<string,mixed> the SshTerminal result (stdout/stderr/exit/cwd/…)
     * @throws ApiException
     */
    public static function run(Claims $claims, DiskManager $diskManager, $body, array $env): array
    {
        self::assertEnabled($env);
        if (!$claims->allowTerminal) {
            throw new ApiException('Terminal access is not allowed', 403, 'terminal_forbidden');
        }
        $body = $body instanceof \Closure ? $body() : $body;
        $claims->requirePerm('write');
        $disk = (string) ($body['disk'] ?? '');
        $claims->requireDisk($disk);
        if (($diskManager->config($disk)['driver'] ?? '') !== 'sftp') {
            throw new ApiException('The terminal only works on an SFTP disk', 400, 'terminal_unsupported');
        }
        $cmd = trim((string) ($body['cmd'] ?? ''));
        if ($cmd === '') {
            throw new ApiException('Missing command', 400, 'missing_param');
        }
        // Catastrophic-command guardrail: double-confirm unless the host opted out.
        $confirmOff = ($env['FLUXFILES_TERMINAL_CONFIRM'] ?? '') === 'false';
        if (!$confirmOff && empty($body['confirm']) && SshTerminal::isDangerous($cmd)) {
            throw new ApiException('This command looks dangerous — confirm to run it', 409, 'terminal_confirm_required');
        }
        $timeout = (int) ($env['FLUXFILES_TERMINAL_TIMEOUT'] ?? 30);
        // ControlMaster connection reuse (SshTerminal path only — see
        // docs/security/SFTP-CONTROLMASTER-SPEC.md). multiplexHandle() returns null for any
        // ineligible disk (not SFTP, ssh_multiplex off, password-only, or a
        // passphrase-protected key), in which case this falls back to the
        // per-request phpseclib connection below, unchanged.
        $mux = (($env['FLUXFILES_SSH_MULTIPLEX_DISABLED'] ?? '') === 'true')
            ? null
            : $diskManager->multiplexHandle($disk);
        if ($mux !== null) {
            [$handle, $root] = $mux;
            // Resolve cwd against the SFTP ROOT (not the SSH login home) — a relative
            // path from the client ("html") must be anchored to $root, or `cd html`
            // runs from /root and every command 404s.
            $cwd = SshTerminal::resolveCwd((string) ($body['cwd'] ?? ''), $root);
            $result = $handle->run($cmd, $cwd, $timeout);
        } else {
            [$conn, $root] = $diskManager->sftpConnection($disk);
            $cwd = SshTerminal::resolveCwd((string) ($body['cwd'] ?? ''), $root);
            $result = SshTerminal::run($conn, $cmd, $cwd, $timeout);
        }
        // No separate shell probe (it doubled the SSH round-trips per command):
        // run() prints a cwd marker on any real shell, so its absence means the
        // host forces a command / allows SFTP only.
        if (empty($result['shell_ok'])) {
            throw new ApiException('This host does not allow a shell (SFTP-only)', 400, 'terminal_no_shell');
        }
        return $result;
    }
}
