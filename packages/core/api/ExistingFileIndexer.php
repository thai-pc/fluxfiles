<?php

declare(strict_types=1);

namespace FluxFiles;

use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;

class ExistingFileIndexer
{
    private DiskManager $diskManager;
    private StorageMetadataHandler $metadata;
    private ImageOptimizer $imageOptimizer;

    public function __construct(DiskManager $diskManager, ?StorageMetadataHandler $metadata = null)
    {
        $this->diskManager = $diskManager;
        $this->metadata = $metadata ?? new StorageMetadataHandler($diskManager);
        $this->imageOptimizer = new ImageOptimizer();
    }

    /**
     * @param array{
     *   disk?: string,
     *   path?: string,
     *   overwrite?: bool,
     *   dry_run?: bool,
     *   owner?: string|null,
     *   readonly?: bool,
     *   hash?: bool,
     *   variants?: bool,
     *   persist_metadata?: bool,
     *   on_item?: callable|null
     * } $options
     * @return array{files_indexed:int,folders_indexed:int,skipped:int,hashed:int,variants:int,errors:int,dry_run:bool}
     */
    public function index(array $options = []): array
    {
        $disk = (string) ($options['disk'] ?? 'local');
        $path = trim((string) ($options['path'] ?? ''), '/');
        $overwrite = (bool) ($options['overwrite'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $owner = isset($options['owner']) && $options['owner'] !== '' ? (string) $options['owner'] : null;
        $readonly = (bool) ($options['readonly'] ?? false);
        $hash = (bool) ($options['hash'] ?? false);
        $variants = (bool) ($options['variants'] ?? false);
        $persistMetadata = (bool) ($options['persist_metadata'] ?? false);
        $onItem = $options['on_item'] ?? null;

        $fs = $this->diskManager->disk($disk);
        $stats = [
            'files_indexed' => 0,
            'folders_indexed' => 0,
            'skipped' => 0,
            'hashed' => 0,
            'variants' => 0,
            'errors' => 0,
            'dry_run' => $dryRun,
        ];

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($path, true) as $item) {
            $key = $item->path();
            $name = basename($key);

            if ($this->isInternalPath($key) || str_ends_with($name, '.meta.json')) {
                continue;
            }

            try {
                if ($item instanceof FileAttributes) {
                    $existing = $this->metadata->getBulk($disk, [$key])[$key] ?? null;
                    if (!$overwrite && $existing !== null) {
                        $stats['skipped']++;
                        $this->emit($onItem, 'skip', $key, $stats);
                        continue;
                    }

                    $data = [
                        'title' => pathinfo($name, PATHINFO_FILENAME),
                        'alt_text' => '',
                        'caption' => '',
                        'tags' => '',
                        'uploaded_by' => $readonly ? '__fluxfiles_readonly__' : $owner,
                    ];

                    if ($hash) {
                        $fileHash = $this->hashFile($disk, $key);
                        if ($fileHash !== null) {
                            $data['file_hash'] = $fileHash;
                            $stats['hashed']++;
                        }
                    }

                    if (!$dryRun) {
                        if ($persistMetadata || $owner !== null || $readonly) {
                            $this->metadata->save($disk, $key, $data);
                            if (isset($data['file_hash'])) {
                                $this->metadata->saveHash($disk, $key, $data['file_hash']);
                            }
                        } else {
                            $this->metadata->indexFile($disk, $key, $data, $overwrite);
                        }

                        $this->metadata->trackParents($disk, $key);

                        if ($variants && $this->imageOptimizer->isImage($key)) {
                            $stats['variants'] += $this->generateVariants($disk, $key);
                        }
                    }

                    $stats['files_indexed']++;
                    $this->emit($onItem, 'file', $key, $stats);
                    continue;
                }

                if (!$dryRun) {
                    $this->metadata->trackDir($disk, $key);
                    $this->metadata->trackParents($disk, $key);
                }
                $stats['folders_indexed']++;
                $this->emit($onItem, 'dir', $key, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->emit($onItem, 'error', $key, $stats, $e);
            }
        }

        return $stats;
    }

    private function isInternalPath(string $key): bool
    {
        return str_starts_with($key, '_fluxfiles/')
            || str_starts_with($key, '_variants/')
            || str_contains($key, '/_fluxfiles/')
            || str_contains($key, '/_variants/');
    }

    private function hashFile(string $disk, string $key): ?string
    {
        $fs = $this->diskManager->disk($disk);
        $stream = $fs->readStream($key);
        if (!is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function generateVariants(string $disk, string $key): int
    {
        $fs = $this->diskManager->disk($disk);
        $stream = $fs->readStream($key);
        if (!is_resource($stream)) {
            return 0;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ffindex_');
        if ($tmpFile === false) {
            fclose($stream);
            return 0;
        }

        $out = fopen($tmpFile, 'wb');
        if ($out === false) {
            fclose($stream);
            @unlink($tmpFile);
            return 0;
        }

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($stream);
            fclose($out);
        }

        try {
            return count($this->imageOptimizer->process($fs, $key, $tmpFile));
        } catch (\Throwable $e) {
            return 0;
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @param callable|null $callback
     * @param array<string,mixed> $stats
     */
    private function emit($callback, string $type, string $key, array $stats, ?\Throwable $error = null): void
    {
        if (!is_callable($callback)) {
            return;
        }

        $callback($type, $key, $stats, $error);
    }
}
