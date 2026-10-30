<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * `POST /api/fm/git-deploy` — the route body around GitDeploy::run(), shared by
 * core and the proxies.
 *
 * A fixed-command-shape SUBSET of the terminal, not a second arbitrary-shell door:
 * the repo path / branch / hooks-enabled are OPERATOR claims (`git_deploy_path`,
 * `git_deploy_branch`, `git_deploy_hooks`) and are never read from the request body
 * — the body carries at most `disk`. See docs/security/GIT-DEPLOY-SECURITY-REVIEW.md §4.
 *
 * Gate order: server kill-switch, `allow_git_deploy` claim, `write` perm, a disk
 * the token may use, an SFTP disk, a configured `git_deploy_path` — then run, then
 * map the run's flags (no shell / locked / unsafe config) to coded errors.
 *
 * Auditing and the dedicated rate-limit bucket are the host's (see TerminalService):
 * AuditPolicy::detail() builds the `path@branch` detail for the `git_deploy` entry.
 */
final class GitDeployService
{
    /**
     * @param array<string,mixed> $env $_ENV-style
     * @throws ApiException 403 `git_deploy_disabled`
     */
    public static function assertEnabled(array $env): void
    {
        if (($env['FLUXFILES_GIT_DEPLOY_DISABLED'] ?? '') === 'true') {
            throw new ApiException('Git deploy is disabled on this server', 403, 'git_deploy_disabled');
        }
    }

    /**
     * @param array<string,mixed>|\Closure():array<string,mixed> $body request body
     *        (only `disk` is read), or a lazy provider invoked after the perm gate
     *        (core's body parse throws `invalid_json` and must not pre-empt the 403s).
     * @param array<string,mixed> $env $_ENV-style (kill-switch, timeout)
     * @return array<string,mixed> the GitDeploy::run result
     * @throws ApiException
     */
    public static function run(Claims $claims, DiskManager $diskManager, $body, array $env): array
    {
        self::assertEnabled($env);
        if (!$claims->allowGitDeploy) {
            throw new ApiException('Git deploy is not allowed', 403, 'git_deploy_forbidden');
        }
        $claims->requirePerm('write');
        $body = $body instanceof \Closure ? $body() : $body;
        $disk = (string) ($body['disk'] ?? '');
        $claims->requireDisk($disk);
        if (($diskManager->config($disk)['driver'] ?? '') !== 'sftp') {
            throw new ApiException('Git deploy only works on an SFTP disk', 400, 'terminal_unsupported');
        }
        if ($claims->gitDeployPath === '') {
            throw new ApiException('git_deploy_path claim is not configured for this token', 400, 'git_deploy_unconfigured');
        }
        [$conn, $root] = $diskManager->sftpConnection($disk);
        $path = SshTerminal::resolveCwd($claims->gitDeployPath, $root);
        $timeout = (int) ($env['FLUXFILES_GIT_DEPLOY_TIMEOUT'] ?? 120);
        $result = GitDeploy::run($conn, $path, $claims->gitDeployBranch, $claims->gitDeployHooks, $timeout);
        if (empty($result['shell_ok'])) {
            throw new ApiException('This host does not allow a shell (SFTP-only)', 400, 'terminal_no_shell');
        }
        if (!empty($result['locked'])) {
            throw new ApiException('A deploy is already in progress for this repo', 409, 'git_deploy_in_progress');
        }
        if (!empty($result['unsafe_config'])) {
            throw new ApiException(
                'The repository\'s own git config contains a command-execution setting and was refused',
                409,
                'git_deploy_unsafe_repo'
            );
        }
        return $result;
    }
}
