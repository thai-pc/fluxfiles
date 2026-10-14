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
    ];

    public const MANIFEST = '_fluxfiles/fixity.json';

    /**
     * @param callable(string):bool $isSkipped  reserved/hidden filter (FileManager's rules)
     * @param array<int,array<string,mixed>> $auditEntries readAudit() rows for the disk
     * @return array<string,mixed> report
     */
    public static function run(
        Filesystem $fs,
        string $root,
        callable $isSkipped,
        array $auditEntries,
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

        $present = [];
        foreach ($fs->listContents($root, true) as $item) {
            if ($item->isFile() && !$isSkipped($item->path())) {
                $present[$item->path()] = (int) ($item->fileSize() ?? 0);
            }
        }
        ksort($present, SORT_STRING);

        // Last write/delete through FluxFiles per path (scoped keys, as audited).
        $lastWrite = [];
        foreach ($auditEntries as $e) {
            if (in_array($e['action'] ?? '', self::WRITE_ACTIONS, true)) {
                $k = (string) ($e['file_key'] ?? '');
                $lastWrite[$k] = max($lastWrite[$k] ?? 0, (int) ($e['created_at'] ?? 0));
            }
        }
        $explained = static function (string $key, int $since) use ($lastWrite): bool {
            return ($lastWrite[$key] ?? 0) >= $since;
        };

        $now = time();
        $report = ['checked' => 0, 'baselined' => 0, 'unchanged' => 0, 'bytes_read' => 0,
            'changed_explained' => [], 'changed_unexplained' => [], 'missing_explained' => [],
            'missing_unexplained' => [], 'complete' => false];
        $budget = $budgetBytes;
        $stoppedAt = '';
        foreach ($present as $key => $size) {
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
                if (!isset($present[$key])) {
                    if ($explained((string) $key, (int) $rec['checked_at'])) {
                        $report['missing_explained'][] = (string) $key;
                    } else {
                        $report['missing_unexplained'][] = (string) $key;
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
        $fs->write($manifestKey, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES));

        $report['ok'] = $report['changed_unexplained'] === [] && $report['missing_unexplained'] === [];
        $report['last_complete_at'] = $manifest['last_complete_at'];
        return $report;
    }
}
