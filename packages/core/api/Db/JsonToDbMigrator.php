<?php

declare(strict_types=1);

namespace FluxFiles\Db;

use FluxFiles\DiskManager;
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\StorageMetadataHandler;

/**
 * Copies the JSON backend's on-disk metadata (_fluxfiles/index.json,
 * dirs.json, trash.json, audit.jsonl + archive, and — for local disks —
 * orphaned _fluxfiles/meta/*.json sidecars) into a DB-backed
 * MetadataRepositoryInterface destination, for the json -> db cutover
 * described in docs/DB-STORAGE-MIGRATION-DESIGN.md §9.
 *
 * Never writes to any _fluxfiles/*.json* file, in either dry-run or real-run
 * mode — reads only from $source, writes only to $destination.
 *
 * _fluxfiles/rate_limit.json is deliberately never read here: rate-limiter
 * state is short-lived and self-correcting, not worth migrating (see §9).
 */
class JsonToDbMigrator
{
    private const AUDIT_KEY = '_fluxfiles/audit.jsonl';
    private const AUDIT_ARCHIVE_DIR = '_fluxfiles/audit/archive/';
    private const SIDECAR_DIR = '_fluxfiles/meta/';
    private const AUDIT_HASH_CHUNK = 500;

    /** Fields compared numerically in verify() — everything else compares as string. */
    private const NUMERIC_FIELDS = ['width', 'height', 'size', 'modified', 'created'];

    private DiskManager $diskManager;
    private StorageMetadataHandler $source;
    private MetadataRepositoryInterface $destination;
    private MigrationImportInterface $destinationImport;

    public function __construct(DiskManager $diskManager, StorageMetadataHandler $source, MetadataRepositoryInterface $destination)
    {
        if (!$destination instanceof MigrationImportInterface) {
            throw new \RuntimeException(
                get_class($destination) . ' must implement MigrationImportInterface to be used as a JsonToDbMigrator destination.'
            );
        }
        $this->diskManager = $diskManager;
        $this->source = $source;
        $this->destination = $destination;
        $this->destinationImport = $destination;
    }

    /**
     * @param callable|null $onItem function(string $section, string $key, string $action): void
     * @return array<string, array<string, int>>
     */
    public function migrate(string $disk, string $prefix = '', bool $dryRun = false, ?callable $onItem = null): array
    {
        $prefix = trim($prefix, '/');
        return [
            'file_metadata' => $this->migrateFileMetadata($disk, $prefix, $dryRun, $onItem),
            'directories'   => $this->migrateDirectories($disk, $prefix, $dryRun, $onItem),
            'trash'         => $this->migrateTrash($disk, $prefix, $dryRun, $onItem),
            'legal_holds'   => $this->migrateHolds($disk, $prefix, $dryRun, $onItem),
            'audit'         => $this->migrateAudit($disk, $dryRun, $onItem),
            'sidecar_fallback' => $this->migrateLocalSidecarFallback($disk, $prefix, $dryRun, $onItem),
        ];
    }

    // -------------------------------------------------------------------
    // 1. File metadata (_fluxfiles/index.json)
    // -------------------------------------------------------------------

    private function inPrefix(string $key, string $prefix): bool
    {
        return $prefix === '' || $key === $prefix || str_starts_with($key, $prefix . '/');
    }

    private function readRawIndex(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        if (!$fs->fileExists('_fluxfiles/index.json')) {
            return [];
        }
        $decoded = json_decode($fs->read('_fluxfiles/index.json'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function migrateFileMetadata(string $disk, string $prefix, bool $dryRun, ?callable $onItem): array
    {
        $counts = ['insert' => 0, 'update' => 0, 'skip' => 0];
        $raw = $this->readRawIndex($disk);
        $keys = array_filter(array_keys($raw), fn($k) => $this->inPrefix($k, $prefix));
        if ($keys === []) {
            return $counts;
        }

        $existing = $this->destination->getBulk($disk, array_values($keys));
        foreach ($keys as $key) {
            $data = $raw[$key];
            $existingRow = $existing[$key] ?? null;

            if ($existingRow === null) {
                $action = 'insert';
            } else {
                $srcModified = $data['modified'] ?? null;
                $dstModified = $existingRow['modified'] ?? null;
                // No source timestamp to compare against (older/hand-seeded index
                // entries): once migrated, trust it — re-flagging as 'update' on
                // every run forever would break idempotency for no benefit, since
                // verify() (full field comparison, not modified-based) is already
                // the drift safety net regardless of this shortcut.
                $action = ($srcModified === null || ($dstModified !== null && (int) $srcModified === (int) $dstModified))
                    ? 'skip'
                    : 'update';
            }

            if (!$dryRun && $action !== 'skip') {
                $this->destination->indexFile($disk, $key, is_array($data) ? $data : [], true);
            }
            $counts[$action]++;
            if ($onItem !== null) {
                $onItem('file_metadata', $key, $action);
            }
        }
        return $counts;
    }

    // -------------------------------------------------------------------
    // 2. Directories (_fluxfiles/dirs.json)
    // -------------------------------------------------------------------

    private function migrateDirectories(string $disk, string $prefix, bool $dryRun, ?callable $onItem): array
    {
        $counts = ['insert' => 0, 'skip' => 0];
        $srcDirs = $this->source->dirsCreated($disk);
        $srcDirs = array_filter($srcDirs, fn($createdAt, $path) => $this->inPrefix((string) $path, $prefix), ARRAY_FILTER_USE_BOTH);
        if ($srcDirs === []) {
            return $counts;
        }

        $dstDirs = $this->destination->dirsCreated($disk);
        $toInsert = [];
        foreach ($srcDirs as $path => $createdAt) {
            if (array_key_exists($path, $dstDirs)) {
                $counts['skip']++;
                if ($onItem !== null) {
                    $onItem('directories', $path, 'skip');
                }
                continue;
            }
            $toInsert[$path] = $createdAt;
            $counts['insert']++;
            if ($onItem !== null) {
                $onItem('directories', $path, 'insert');
            }
        }

        if (!$dryRun && $toInsert !== []) {
            $this->destinationImport->insertDirectoriesPreservingTimestamp($disk, $toInsert);
        }
        return $counts;
    }

    // -------------------------------------------------------------------
    // 3. Trash (_fluxfiles/trash.json)
    // -------------------------------------------------------------------

    private function migrateTrash(string $disk, string $prefix, bool $dryRun, ?callable $onItem): array
    {
        $counts = ['insert' => 0, 'update' => 0, 'skip' => 0];
        $srcTrash = $this->source->allTrash($disk);
        foreach ($srcTrash as $id => $entry) {
            $originalKey = (string) ($entry['original_key'] ?? '');
            if (!$this->inPrefix($originalKey, $prefix)) {
                continue;
            }

            $existingEntry = $this->destination->getTrash($disk, (string) $id);
            if ($existingEntry === null) {
                $action = 'insert';
            } else {
                $srcDeletedAt = (int) ($entry['deleted_at'] ?? 0);
                $dstDeletedAt = (int) ($existingEntry['deleted_at'] ?? 0);
                $action = $srcDeletedAt === $dstDeletedAt ? 'skip' : 'update';
            }

            if (!$dryRun && $action !== 'skip') {
                $this->destination->addTrash($disk, (string) $id, $entry);
            }
            $counts[$action]++;
            if ($onItem !== null) {
                $onItem('trash', (string) $id, $action);
            }
        }
        return $counts;
    }

    // -------------------------------------------------------------------
    // 3b. Legal holds (_fluxfiles/holds.json) — docs/RETENTION-LEGAL-HOLD-DESIGN.md §5
    // -------------------------------------------------------------------

    private function migrateHolds(string $disk, string $prefix, bool $dryRun, ?callable $onItem): array
    {
        $counts = ['insert' => 0, 'update' => 0, 'skip' => 0];
        $srcHolds = $this->source->allHolds($disk);
        foreach ($srcHolds as $id => $entry) {
            $path = (string) ($entry['path'] ?? '');
            if (!$this->inPrefix($path, $prefix)) {
                continue;
            }

            $existingEntry = $this->destination->getHold($disk, (string) $id);
            if ($existingEntry === null) {
                $action = 'insert';
            } else {
                // released_at is the one field release() mutates after placement,
                // so it's the cheapest reliable "did anything change" signal.
                $srcReleasedAt = $entry['released_at'] ?? null;
                $dstReleasedAt = $existingEntry['released_at'] ?? null;
                $action = $srcReleasedAt === $dstReleasedAt ? 'skip' : 'update';
            }

            if (!$dryRun && $action !== 'skip') {
                $this->destination->addHold($disk, (string) $id, $entry);
            }
            $counts[$action]++;
            if ($onItem !== null) {
                $onItem('legal_holds', (string) $id, $action);
            }
        }
        return $counts;
    }

    // -------------------------------------------------------------------
    // 4. Audit log (_fluxfiles/audit.jsonl + archive) — whole disk, no prefix
    // -------------------------------------------------------------------

    /** @return array<int, array{ts:mixed, action:mixed, context:array}> */
    private function readRawAuditRows(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        $rows = [];

        if ($fs->fileExists(self::AUDIT_KEY)) {
            foreach (array_filter(explode("\n", $fs->read(self::AUDIT_KEY))) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        if ($fs->directoryExists(self::AUDIT_ARCHIVE_DIR)) {
            $archiveFiles = [];
            foreach ($fs->listContents(self::AUDIT_ARCHIVE_DIR, false) as $item) {
                if ($item->isFile() && str_ends_with($item->path(), '.jsonl')) {
                    $archiveFiles[] = $item->path();
                }
            }
            sort($archiveFiles, SORT_STRING);
            foreach ($archiveFiles as $path) {
                foreach (array_filter(explode("\n", $fs->read($path))) as $line) {
                    $row = json_decode($line, true);
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }

        return $rows;
    }

    /** @return array<string, array> Map of content_hash => entry ready for insertAuditEntries(). */
    private function buildAuditEntries(array $rawRows): array
    {
        $entries = [];
        foreach ($rawRows as $row) {
            $ts = $row['ts'] ?? null;
            $action = $row['action'] ?? null;
            if ($ts === null || $action === null) {
                continue;
            }
            $context = is_array($row['context'] ?? null) ? $row['context'] : [];
            $hash = hash('sha256', $ts . $action . json_encode($context));
            $entries[$hash] = [
                'user_id'      => $context['user_id'] ?? null,
                'action'       => (string) $action,
                'file_key'     => $context['file_key'] ?? null,
                'ip'           => $context['ip'] ?? null,
                'user_agent'   => $context['user_agent'] ?? null,
                'detail'       => $context['detail'] ?? null,
                'created_at'   => (int) $ts,
                'content_hash' => $hash,
            ];
        }
        return $entries;
    }

    private function migrateAudit(string $disk, bool $dryRun, ?callable $onItem): array
    {
        $counts = ['insert' => 0, 'skip' => 0];
        $entries = $this->buildAuditEntries($this->readRawAuditRows($disk));
        if ($entries === []) {
            return $counts;
        }

        $existingHashes = [];
        foreach (array_chunk(array_keys($entries), self::AUDIT_HASH_CHUNK) as $chunk) {
            $existingHashes = array_merge($existingHashes, $this->destinationImport->existingAuditContentHashes($disk, $chunk));
        }
        $existingHashes = array_flip($existingHashes);

        $toInsert = [];
        foreach ($entries as $hash => $entry) {
            if (isset($existingHashes[$hash])) {
                $counts['skip']++;
                if ($onItem !== null) {
                    $onItem('audit', $hash, 'skip');
                }
                continue;
            }
            $toInsert[] = $entry;
            $counts['insert']++;
            if ($onItem !== null) {
                $onItem('audit', $hash, 'insert');
            }
        }

        if (!$dryRun && $toInsert !== []) {
            $this->destinationImport->insertAuditEntries($disk, $toInsert);
        }
        return $counts;
    }

    // -------------------------------------------------------------------
    // 5. Orphaned local sidecars (_fluxfiles/meta/**/*.json), defensive only
    // -------------------------------------------------------------------

    private function migrateLocalSidecarFallback(string $disk, string $prefix, bool $dryRun, ?callable $onItem): array
    {
        $counts = ['insert' => 0, 'skip' => 0];
        $fs = $this->diskManager->disk($disk);
        if (!$fs->directoryExists(self::SIDECAR_DIR)) {
            return $counts;
        }

        $rawIndexKeys = array_keys($this->readRawIndex($disk));
        $rawIndexKeys = array_flip($rawIndexKeys);

        foreach ($fs->listContents(self::SIDECAR_DIR, true) as $item) {
            if (!$item->isFile() || !str_ends_with($item->path(), '.json')) {
                continue;
            }
            $key = substr($item->path(), strlen(self::SIDECAR_DIR), -strlen('.json'));
            if ($key === '' || !$this->inPrefix($key, $prefix) || isset($rawIndexKeys[$key])) {
                continue;
            }

            $data = $this->source->get($disk, $key);
            if ($data === null) {
                continue;
            }

            $existing = $this->destination->getBulk($disk, [$key]);
            $action = ($existing[$key] ?? null) === null ? 'insert' : 'skip';
            if (!$dryRun && $action === 'insert') {
                $this->destination->indexFile($disk, $key, $data, false);
            }
            $counts[$action]++;
            if ($onItem !== null) {
                $onItem('sidecar_fallback', $key, $action);
            }
        }
        return $counts;
    }

    // -------------------------------------------------------------------
    // Verify
    // -------------------------------------------------------------------

    private function normalizeScalar(string $field, $value)
    {
        if ($value === null) {
            return null;
        }
        return in_array($field, self::NUMERIC_FIELDS, true) ? (int) $value : (string) $value;
    }

    public function verify(string $disk, string $prefix = ''): array
    {
        $prefix = trim($prefix, '/');
        return [
            'file_metadata' => $this->verifyFileMetadata($disk, $prefix),
            'directories'   => $this->verifyDirectories($disk, $prefix),
            'trash'         => $this->verifyTrash($disk, $prefix),
            'audit'         => $this->verifyAudit($disk),
        ];
    }

    private function verifyFileMetadata(string $disk, string $prefix): array
    {
        $missing = [];
        $mismatched = [];
        $raw = $this->readRawIndex($disk);
        $keys = array_values(array_filter(array_keys($raw), fn($k) => $this->inPrefix($k, $prefix)));
        if ($keys === []) {
            return ['missing_in_db' => [], 'mismatched' => []];
        }

        $existing = $this->destination->getBulk($disk, $keys);
        foreach ($keys as $key) {
            $existingRow = $existing[$key] ?? null;
            if ($existingRow === null) {
                $missing[] = $key;
                continue;
            }
            $diff = [];
            foreach ($raw[$key] as $field => $srcValue) {
                if ($field === 'file_hash' || !array_key_exists($field, $existingRow)) {
                    continue;
                }
                $a = $this->normalizeScalar($field, $srcValue);
                $b = $this->normalizeScalar($field, $existingRow[$field]);
                if ($a !== $b) {
                    $diff[$field] = [$a, $b];
                }
            }
            if ($diff !== []) {
                $mismatched[$key] = $diff;
            }
        }
        return ['missing_in_db' => $missing, 'mismatched' => $mismatched];
    }

    private function verifyDirectories(string $disk, string $prefix): array
    {
        $srcDirs = $this->source->dirsCreated($disk);
        $srcDirs = array_filter($srcDirs, fn($createdAt, $path) => $this->inPrefix((string) $path, $prefix), ARRAY_FILTER_USE_BOTH);
        $dstDirs = $this->destination->dirsCreated($disk);
        $missing = array_values(array_diff(array_keys($srcDirs), array_keys($dstDirs)));
        return ['missing_in_db' => $missing];
    }

    private function verifyTrash(string $disk, string $prefix): array
    {
        $missing = [];
        $mismatched = [];
        $srcTrash = $this->source->allTrash($disk);
        foreach ($srcTrash as $id => $entry) {
            $originalKey = (string) ($entry['original_key'] ?? '');
            if (!$this->inPrefix($originalKey, $prefix)) {
                continue;
            }
            $existingEntry = $this->destination->getTrash($disk, (string) $id);
            if ($existingEntry === null) {
                $missing[] = (string) $id;
                continue;
            }
            if ((int) ($entry['deleted_at'] ?? 0) !== (int) ($existingEntry['deleted_at'] ?? 0)) {
                $mismatched[(string) $id] = [
                    'deleted_at' => [$entry['deleted_at'] ?? null, $existingEntry['deleted_at'] ?? null],
                ];
            }
        }
        return ['missing_in_db' => $missing, 'mismatched' => $mismatched];
    }

    private function verifyAudit(string $disk): array
    {
        $entries = $this->buildAuditEntries($this->readRawAuditRows($disk));
        if ($entries === []) {
            return ['missing_in_db' => []];
        }

        $existingHashes = [];
        foreach (array_chunk(array_keys($entries), self::AUDIT_HASH_CHUNK) as $chunk) {
            $existingHashes = array_merge($existingHashes, $this->destinationImport->existingAuditContentHashes($disk, $chunk));
        }
        $existingHashes = array_flip($existingHashes);

        $missing = [];
        foreach ($entries as $hash => $entry) {
            if (!isset($existingHashes[$hash])) {
                $missing[] = ['action' => $entry['action'], 'created_at' => $entry['created_at']];
            }
        }
        return ['missing_in_db' => $missing];
    }

    public static function isClean(array $verifyResult): bool
    {
        foreach ($verifyResult as $section) {
            if (!empty($section['missing_in_db']) || !empty($section['mismatched'])) {
                return false;
            }
        }
        return true;
    }
}
