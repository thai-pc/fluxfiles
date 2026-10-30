<?php

declare(strict_types=1);

namespace FluxFiles;

use League\Flysystem\Filesystem;

/**
 * Fixity (integrity) check: has any stored file's content changed in a way
 * FluxFiles can't account for?
 *
 * The first pass records a SHA-256 baseline per file in
 * `<prefix>/_fluxfiles/fixity.json` (storage-resident, like every other
 * FluxFiles bookkeeping). Later passes re-hash and compare. A changed or missing
 * file is then checked against the AUDIT LOG: if a write/delete through
 * FluxFiles touched that path since the last check, the change is `explained`;
 * otherwise it is `unexplained` — edited or removed directly on the storage
 * (bucket console, a leaked credential, a script) or silently corrupted.
 *
 * Bounded work per call: files are visited in key order, hashing stops at
 * $budgetBytes, and a cursor in the manifest resumes the next call where this
 * one stopped, so a large library is covered over several runs.
 */
final class FixityChecker
{
    /** Audit actions that legitimately rewrite or remove a file's bytes. */
    private const WRITE_ACTIONS = [
        'upload', 'chunk_upload', 'content_update', 'crop', 'watermark', 'watermark_remove',
        'optimize', 'extract', 'url_import', 'version_restore', 'ai_vision', 'c2pa_sign',
        'delete', 'trash', 'purge', 'empty_trash', 'rename', 'move', 'cross_move',
        'copy', 'cross_copy', 'restore', 'backup', 'presign_put', 'presign_post',
        'retention_trash', 'retention_delete', 'intake_upload',
    ];

    /**
     * Actions whose entry names the ARCHIVE, while the bytes they rewrite (and may
     * overwrite) land next to it — an extract's default destination is a folder
     * beside the archive. They explain everything under the archive's directory.
     */
    private const SIBLING_TREE_ACTIONS = ['extract'];

    /**
     * HISTORICAL: the SSH terminal and one-click Git deploy were removed in 1.0.0,
     * but their chained entries stay in existing audit logs. A command or a deploy
     * could touch any file and its entry carries no disk-relative key, so it keeps
     * explaining every change on the disk in its window; otherwise the first
     * fixity run after an upgrade would flag those changes as unexplained.
     * Nothing writes these actions any more.
     */
    private const DISK_WIDE_ACTIONS = ['terminal', 'git_deploy'];

    public const MANIFEST = '_fluxfiles/fixity.json';

    /**
     * @param callable(string):bool $isSkipped  reserved/hidden filter (FileManager's rules)
     * @param array<int,array<string,mixed>>|callable(?int):array<int,array<string,mixed>> $auditEntries
     *        readAudit() rows for the disk, or a loader given the oldest baseline
     *        time (null = no baseline yet) so a DB backend reads only that window
     * @return array<string,mixed> report
     */
    public static function run(
        Filesystem $fs,
        string $root,
        callable $isSkipped,
        $auditEntries,
        int $budgetBytes = 1024 * 1024 * 1024
    ): array {
        $manifestKey = ($root !== '' ? $root . '/' : '') . self::MANIFEST;
        $manifest = ['files' => [], 'cursor' => '', 'last_complete_at' => null];
        if ($fs->fileExists($manifestKey)) {
            $decoded = json_decode((string) $fs->read($manifestKey), true);
            if (is_array($decoded) && is_array($decoded['files'] ?? null)) {
                $manifest = $decoded + $manifest;
            }
        }
        $files = $manifest['files'];
        $cursor = (string) ($manifest['cursor'] ?? '');
        if (is_callable($auditEntries)) {
            // Only writes after a file's baseline can explain a change to it, so the
            // oldest baseline bounds the audit window worth reading.
            $since = null;
            foreach ($files as $rec) {
                $t = (int) ($rec['checked_at'] ?? 0);
                $since = $since === null ? $t : min($since, $t);
            }
            $auditEntries = (array) $auditEntries($since);
        }

        $present = [];
        foreach ($fs->listContents($root, true) as $item) {
            if ($item->isFile() && !$isSkipped($item->path())) {
                $present[$item->path()] = (int) ($item->fileSize() ?? 0);
            }
        }
        ksort($present, SORT_STRING);

        // Last write/delete through FluxFiles per path (scoped keys, as audited).
        // Only entries the audit chain vouches for count: once the log is chained,
        // an unchained or forged line is exactly what someone with storage write
        // access would append to turn their own edit into an "explained" change.
        // Keys are cast back to string: PHP turns an all-digit key ("2024") into an
        // int array key, which strict_types then refuses in readStream()/strcmp().
        $lastWrite = [];
        $lastTreeWrite = [];
        $lastDiskWrite = 0;
        foreach (AuditChain::trustedEntries($auditEntries) as $e) {
            $action = (string) ($e['action'] ?? '');
            $k = trim((string) ($e['file_key'] ?? ''), '/');
            $ts = (int) ($e['created_at'] ?? 0);
            if (in_array($action, self::WRITE_ACTIONS, true)) {
                $lastWrite[$k] = max($lastWrite[$k] ?? 0, $ts);
            }
            if (in_array($action, self::SIBLING_TREE_ACTIONS, true)) {
                $dir = $k !== '' && strpos($k, '/') !== false ? dirname($k) : '';
                $lastTreeWrite[$dir] = max($lastTreeWrite[$dir] ?? 0, $ts);
            }
            if (in_array($action, self::DISK_WIDE_ACTIONS, true)) {
                $lastDiskWrite = max($lastDiskWrite, $ts);
            }
        }
        $explained = static function (string $key, int $since) use ($lastWrite, $lastTreeWrite, $lastDiskWrite): bool {
            if ($lastDiskWrite >= $since || ($lastWrite[$key] ?? 0) >= $since) {
                return true;
            }
            foreach ($lastTreeWrite as $dir => $ts) {
                $dir = (string) $dir;
                if ($ts >= $since && ($dir === '' || strpos($key, $dir . '/') === 0)) {
                    return true;
                }
            }
            return false;
        };

        $now = time();
        $report = ['checked' => 0, 'baselined' => 0, 'unchanged' => 0, 'bytes_read' => 0,
            'changed_explained' => [], 'changed_unexplained' => [], 'missing_explained' => [],
            'missing_unexplained' => [], 'complete' => false];
        $budget = $budgetBytes;
        $stoppedAt = '';
        foreach ($present as $key => $size) {
            $key = (string) $key;
            if ($cursor !== '' && strcmp($key, $cursor) <= 0) {
                continue; // resumed pass: already done this round
            }
            if ($budget < $size && $report['checked'] > 0) {
                $stoppedAt = $key;
                break;
            }
            $stream = $fs->readStream($key);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $hash = hash_final($ctx);
            $budget -= $size;
            $report['bytes_read'] += $size;
            $report['checked']++;
            $prev = $files[$key] ?? null;
            if ($prev === null) {
                $report['baselined']++;
            } elseif ($prev['sha256'] === $hash) {
                $report['unchanged']++;
            } elseif ($explained($key, (int) $prev['checked_at'])) {
                $report['changed_explained'][] = $key;
            } else {
                $report['changed_unexplained'][] = $key;
            }
            $files[$key] = ['sha256' => $hash, 'size' => $size, 'checked_at' => $now];
        }

        if ($stoppedAt === '') {
            // A full round finished: anything in the baseline that's gone now.
            foreach ($files as $key => $rec) {
                $key = (string) $key;
                // Not visible to THIS token (e.g. a dotfile with show_hidden=false):
                // it wasn't listed, which doesn't make it missing — keep its baseline
                // for a token that can see it.
                if ($isSkipped($key)) {
                    continue;
                }
                if (!isset($present[$key])) {
                    if ($explained($key, (int) $rec['checked_at'])) {
                        $report['missing_explained'][] = $key;
                    } else {
                        $report['missing_unexplained'][] = $key;
                    }
                    unset($files[$key]);
                }
            }
            $report['complete'] = true;
            $manifest['last_complete_at'] = $now;
            $manifest['cursor'] = '';
        } else {
            $manifest['cursor'] = (string) array_key_last(array_filter(
                $present,
                static fn ($k) => strcmp((string) $k, $stoppedAt) < 0,
                ARRAY_FILTER_USE_KEY
            ));
        }
        $manifest['files'] = $files;
        $fs->write($manifestKey, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES), StorageMetadataHandler::INTERNAL_WRITE);

        $report['ok'] = $report['changed_unexplained'] === [] && $report['missing_unexplained'] === [];
        $report['last_complete_at'] = $manifest['last_complete_at'];
        return $report;
    }
}
