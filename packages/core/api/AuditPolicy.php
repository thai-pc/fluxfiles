<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * What the audit log records for a request, and under which action name.
 *
 * This used to live as five loose functions in index.php, and the Laravel and
 * WordPress proxies each re-ported it by hand — which is how they drifted
 * (`content_edit` vs `content_update`, blocked-write keys, uncoded 403s). Every
 * method here takes its inputs explicitly (route, body, POST fields, error code,
 * claims) instead of reading PHP globals, so the three callers can share one
 * implementation whatever their request object looks like.
 *
 * Pure policy: nothing here decides whether a request is allowed. The only I/O is
 * the `record*()` helpers, which are best-effort and never throw — auditing must
 * not turn a clean 4xx into a 500.
 */
final class AuditPolicy
{
    /**
     * Route → audit action. Order matters: the FIRST needle contained in the URI
     * wins, so every longer route must precede its shorter prefix
     * (/share/revoke → /share, /chunk/presign → /presign, /metadata/import → /metadata).
     *
     * @var array<string,string>
     */
    private const ACTIONS = [
        '/audit/purge' => 'audit_purge',
        '/share/revoke'  => 'share_revoke',
        '/share'         => 'share_create',
        '/intake/revoke' => 'intake_revoke',
        '/intake'        => 'intake_create',
        '/versions/restore' => 'version_restore',
        '/webhooks/test' => 'webhook_test',
        '/virus/scan'    => 'virus_scan',
        '/fixity/check'  => 'fixity_check',
        '/retention/run' => 'retention_run',
        '/ai-vision'     => 'ai_vision',
        '/ocr'           => 'ocr',
        '/backup'        => 'backup',
        '/c2pa/sign'     => 'c2pa_sign',
        '/c2pa'          => 'c2pa_verify',
        '/metadata/import' => 'metadata_import',
        '/hold/release' => 'legal_hold_release',
        '/hold'       => 'legal_hold_place',
        '/trash/restore' => 'restore',
        '/trash/purge'   => 'purge',
        '/trash/empty'   => 'empty_trash',
        '/trash'      => 'trash',
        '/import-url' => 'url_import',
        '/upload'     => 'upload',
        '/rename'     => 'rename',
        '/delete'     => 'delete',
        '/ai-tag'     => 'ai_tag',
        '/crop'       => 'crop',
        '/cross-move' => 'cross_move',
        '/cross-copy' => 'cross_copy',
        '/move'       => 'move',
        '/copy'       => 'copy',
        '/mkdir'      => 'mkdir',
        '/metadata'   => 'metadata_update',
        '/chunk'      => 'chunk_upload',
        '/presign'    => 'presign',
        '/zip'        => 'zip_download',
        '/watermark/remove' => 'watermark_remove',
        '/watermark'  => 'watermark',
        '/optimize'   => 'optimize',
        '/extract'    => 'extract',
        '/chmod'      => 'chmod',
        '/content'    => 'content_update',
    ];

    /** Error code of a refused write → the audit action that records the attempt. */
    private const REFUSED_WRITE_ACTIONS = [
        'virus_detected'    => 'virus_blocked',
        'pii_detected'      => 'dlp_blocked',
        'legal_hold_active' => 'legal_hold_blocked',
    ];

    public static function resolveAction(string $uri): string
    {
        foreach (self::ACTIONS as $needle => $action) {
            if (strpos($uri, $needle) !== false) {
                return $action;
            }
        }

        return 'unknown';
    }

    /** The needle table, for the CI guard that every mutating route is named. */
    public static function actionMap(): array
    {
        return self::ACTIONS;
    }

    /**
     * Everything the generic "log write actions" step records for a successful
     * mutating request: action (presign split by method), tenant-scoped key, disk
     * and detail.
     *
     * @param array<string,mixed> $body  decoded JSON body ([] when none)
     * @param array<string,mixed> $post  $_POST (multipart uploads carry disk there)
     * @param mixed               $data  the operation result
     * @return array{action:string,disk:string,key:string,detail:?string}
     */
    public static function successEntry(string $uri, array $body, array $post, Claims $claims, $data): array
    {
        $action = self::resolveAction($uri);
        if ($action === 'presign') {
            // A GET presign hands out a download URL, a PUT/POST one an upload URL —
            // very different events for whoever reads the log.
            $presignMethod = strtoupper((string) ($body['method'] ?? ''));
            $action = $presignMethod === 'PUT' ? 'presign_put' : ($presignMethod === 'POST' ? 'presign_post' : 'presign_get');
        }
        $key = $body['path'] ?? $body['key'] ?? $body['from'] ?? $body['src_path'] ?? '';
        // Multipart uploads carry no JSON body, so the key isn't in $body — fall
        // back to the operation result (the uploaded file's key) so the entry
        // isn't blank. Only fills when empty, so other actions are unchanged.
        if ($key === '' && is_array($data) && isset($data['key'])) {
            $key = (string) $data['key'];
        }
        // Every path above (request body AND $data['key']) is tenant-relative —
        // FileManager::outKey()/unscopePath() strips the prefix before a path
        // ever reaches the client or comes back from one. isPathInScope() (used
        // by list()/exportAll() to keep tenants from reading each other's
        // entries) matches on the FULL scoped path, so logging the relative one
        // verbatim made a scoped tenant's own actions permanently invisible to
        // themselves — the prefix check could never match. Re-apply the same
        // prefixing FileManager used for the operation itself (idempotent, so
        // this is safe even if a path were already scoped).
        if ($key !== '') {
            $key = $claims->scopePath((string) $key);
        }
        $disk = $body['disk'] ?? $body['src_disk'] ?? $post['disk'] ?? 'local';

        return [
            'action' => $action,
            'disk'   => (string) $disk,
            'key'    => (string) $key,
            'detail' => self::detail($action, $body, $claims, $data),
        ];
    }

    /**
     * Free-form `detail` for an audit entry — the security-relevant fact for actions
     * whose path/key alone doesn't say what happened.
     *
     * @param array<string,mixed> $body
     * @param mixed $data
     */
    public static function detail(string $action, array $body, Claims $claims, $data = null): ?string
    {
        switch ($action) {
            case 'retention_run':
                return is_array($data)
                    ? sprintf('%s acted=%d held=%d', !empty($data['dry_run']) ? 'dry-run' : 'applied',
                        (int) ($data['acted'] ?? 0), (int) ($data['held'] ?? 0))
                    : null;
            case 'fixity_check':
                return is_array($data)
                    ? sprintf('checked=%d unexplained=%d', (int) ($data['checked'] ?? 0),
                        count($data['changed_unexplained'] ?? []) + count($data['missing_unexplained'] ?? []))
                    : null;
            case 'virus_scan':
                // The verdict IS the event.
                return is_array($data) && !empty($data['clean']) ? 'clean' : 'infected: ' . (string) ($data['threat'] ?? 'unknown');
            case 'legal_hold_place':
            case 'legal_hold_release':
                return (string) ($body['reason'] ?? '');
            case 'share_revoke':
            case 'intake_revoke':
                // The body carries only the link id — without it the entry is blank.
                return 'jti=' . (is_scalar($body['jti'] ?? null) ? (string) $body['jti'] : '');
            case 'version_restore':
                return 'version=' . (is_scalar($body['version_id'] ?? null) ? (string) $body['version_id'] : '');
            case 'presign_get':
            case 'presign_put':
            case 'presign_post':
                return 'ttl=' . (int) ($body['ttl'] ?? 0);
            default:
                return null;
        }
    }

    /**
     * "N files, X bytes: a, b, …" for a zip download, capped so one huge selection
     * can't blow up a single audit line.
     *
     * @param array{entries:array<int,array{key:string,name:string,size:int}>,total:int,count:int} $manifest
     */
    public static function zipDetail(array $manifest): string
    {
        $names = array_map(static fn ($e) => (string) $e['key'], $manifest['entries'] ?? []);
        $list = implode(', ', array_slice($names, 0, 20)) . (count($names) > 20 ? ', …' : '');
        $detail = (int) ($manifest['count'] ?? count($names)) . ' files, ' . (int) ($manifest['total'] ?? 0) . ' bytes: ' . $list;
        return mb_strlen($detail) > 1000 ? mb_substr($detail, 0, 999) . '…' : $detail;
    }

    /**
     * The storage key a refused (virus/DLP-blocked) write would have had, scoped like
     * every other audit file_key — a prefixed tenant's export filter must see its own
     * blocked events. Editor save names the file in the body; an upload names the
     * folder in the POST `path` and the file in the error params; an extract entry
     * stays relative to the tenant root (its destination isn't known here).
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $params ApiException error params
     * @param array<string,mixed> $post   $_POST
     */
    public static function blockedWriteKey(Claims $claims, array $body, array $params, array $post = []): string
    {
        $name = (string) ($params['name'] ?? $params['entry'] ?? '');
        if (!isset($params['entry']) && is_string($body['path'] ?? null) && $body['path'] !== '') {
            $rel = $body['path'];
        } elseif (!isset($params['entry']) && is_string($post['path'] ?? null) && trim($post['path'], '/') !== '') {
            $rel = trim($post['path'], '/') . '/' . $name;
        } else {
            $rel = $name;
        }
        try {
            return $claims->scopePath($rel);
        } catch (\Throwable $e) {
            return $name;
        }
    }

    /**
     * The audit entry for a write refused with virus_detected / pii_detected /
     * legal_hold_active, or null for any other error code. The audit log only
     * records SUCCESSFUL writes, so without this the security events a scanner or a
     * hold exist to produce would be the one thing never recorded.
     *
     * `pii_detected` detail carries ONLY the matched entity-TYPE names (never
     * matched text/offsets/counts — see §6.2 of docs/design/DLP-PII-REDACTION-DESIGN.md).
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $post
     * @return array{action:string,disk:string,key:string,detail:?string}|null
     */
    public static function refusedWriteEntry(Claims $claims, ?string $errorCode, array $params, array $body, array $post): ?array
    {
        $action = self::REFUSED_WRITE_ACTIONS[$errorCode ?? ''] ?? null;
        if ($action === null) {
            return null;
        }

        if ($action === 'legal_hold_blocked') {
            $rawKey = (string) ($body['path'] ?? $body['from'] ?? $body['src_path'] ?? '');
            // /trash/purge's body is {disk, trash_id} only — no path in the request —
            // so fall back to the path FileManager::assertNoActiveHold() put in the
            // exception params (resolved from the trash entry's original_key). That
            // one is tenant-relative (it is shown to the client), so it is scoped
            // again here like the request path; scopePath() is idempotent.
            return [
                'action' => $action,
                'disk'   => (string) ($body['disk'] ?? $body['src_disk'] ?? $post['disk'] ?? 'local'),
                'key'    => $claims->scopePath($rawKey !== '' ? $rawKey : (string) ($params['path'] ?? '')),
                'detail' => (string) ($params['hold_id'] ?? ''),
            ];
        }

        // The disk lands in $_POST for a multipart upload and in the JSON body for
        // the editor/extract paths — resolve both, like successEntry() does.
        return [
            'action' => $action,
            'disk'   => (string) ($post['disk'] ?? $body['disk'] ?? 'local'),
            'key'    => self::blockedWriteKey($claims, $body, $params, $post),
            'detail' => $action === 'dlp_blocked'
                ? implode(',', array_map('strval', (array) ($params['entities'] ?? [])))
                : null,
        ];
    }

    /**
     * Log a refused write (see refusedWriteEntry()). Best-effort, never throws.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $post
     */
    public static function recordRefusedWrite(AuditLogStorage $auditLog, Claims $claims, ApiException $e, array $body, array $post): void
    {
        try {
            $entry = self::refusedWriteEntry($claims, $e->getErrorCode(), $e->getErrorParams(), $body, $post);
            if ($entry === null) {
                return;
            }
            $auditLog->log($claims->userId, $entry['action'], $entry['disk'], $entry['key'], null, null, $entry['detail']);
        } catch (\Throwable $ignored) {
            // Auditing must never turn a clean 4xx into a 500.
        }
    }

    /**
     * Record an authenticated request that was refused with a 403 (`access_denied`,
     * detail = the error code + method + route). Best-effort, never throws.
     *
     * The entry is written to a disk the token CAN access: the requested disk when
     * allowed, otherwise the token's first disk (with the refused disk named in the
     * detail) — a `disk_denied` probe must not cause a write onto that very disk.
     * The key is the requested path scoped like any other entry; a path that can't
     * be scoped (the cross-tenant probe itself) is logged normalized but unscoped, so
     * it lands outside the tenant's own view and only an unscoped admin sees it.
     *
     * `legal_hold_active` is skipped: it already has its own `legal_hold_blocked` entry.
     *
     * @param array<string,mixed> $body decoded JSON body
     * @param array<string,mixed> $post $_POST
     * @param array<string,mixed> $get  $_GET
     */
    public static function recordDenied(
        AuditLogStorage $auditLog,
        Claims $claims,
        ApiException $e,
        string $method,
        string $uri,
        array $body,
        array $post,
        array $get
    ): void {
        if ($e->getHttpCode() !== 403 || $e->getErrorCode() === 'legal_hold_active') {
            return;
        }
        try {
            $src = $body + $post + $get;
            $rawKey = '';
            foreach (['path', 'key', 'from', 'src_path'] as $k) {
                if (isset($src[$k]) && is_scalar($src[$k]) && (string) $src[$k] !== '') {
                    $rawKey = (string) $src[$k];
                    break;
                }
            }
            $disk = '';
            foreach (['disk', 'src_disk'] as $k) {
                if (isset($src[$k]) && is_scalar($src[$k])) {
                    $disk = (string) $src[$k];
                    break;
                }
            }
            $detail = (string) ($e->getErrorCode() ?? 'forbidden') . ' ' . $method . ' ' . $uri;
            if ($disk === '' || !$claims->hasDisk($disk)) {
                if ($disk !== '') {
                    $detail .= ' disk=' . $disk;
                }
                $disk = $claims->allowedDisks[0] ?? '';
            }
            if ($disk === '') {
                return; // a token with no disk at all has nowhere to log to
            }
            try {
                $key = $rawKey === '' ? '' : $claims->scopePath($rawKey);
            } catch (ApiException $scopeErr) {
                $key = $claims->normalizeKey($rawKey);
            }
            $auditLog->log($claims->userId, 'access_denied', $disk, $key, null, null, mb_substr($detail, 0, 500));
        } catch (\Throwable $ignored) {
            // Auditing must never turn a clean 403 into a 500.
        }
    }

    /**
     * Decode a raw request body for the helpers above: `[]` for anything that is
     * not a JSON object (an empty or malformed body must never fail an audit).
     *
     * @return array<string,mixed>
     */
    public static function decodeBody(string $raw): array
    {
        $body = json_decode($raw !== '' ? $raw : '{}', true);
        return is_array($body) ? $body : [];
    }
}
