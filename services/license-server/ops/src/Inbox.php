<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Ops;

use FluxFiles\LicenseServer\Client\Ingest;

require_once __DIR__ . '/../../client/Ingest.php';

final class Inbox
{
    /** Never accepts paths supplied by a JSON file, and never opens a raw log. */
    public static function read(string $file): array
    {
        if (
            is_link($file) ||
            !is_file($file) ||
            filesize($file) > 262144 ||
            !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.json$/D', basename($file))
        ) {
            throw new \InvalidArgumentException('invalid inbox file');
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (
            !is_array($data) ||
            !is_string($data['day'] ?? null) ||
            !Ingest::day($data['day']) ||
            basename($file) !== $data['day'] . '.json' ||
            array_diff(array_keys($data), ['day', 'downloads', 'demo'])
        ) {
            throw new \InvalidArgumentException('invalid inbox envelope');
        }
        $requests = [];
        if (isset($data['downloads'])) {
            if (!is_array($data['downloads'])) {
                throw new \InvalidArgumentException();
            }
            $requests[] = [
                'type' => 'downloads_daily',
                'day' => $data['day'],
                'rows' => Ingest::rows('downloads_daily', $data['day'], $data['downloads']),
            ];
        }
        if (isset($data['demo'])) {
            if (!is_array($data['demo'])) {
                throw new \InvalidArgumentException();
            }
            $requests[] = [
                'type' => 'demo_daily',
                'day' => $data['day'],
                'rows' => Ingest::rows('demo_daily', $data['day'], [$data['demo']]),
            ];
        }
        if ($requests === []) {
            throw new \InvalidArgumentException('empty inbox');
        }
        return $requests;
    }

    public static function consume(
        string $directory,
        \Closure $ingest,
        \Closure $canContinue,
    ): array {
        $result = ['ingested' => 0, 'quarantined' => 0];
        if (!is_dir($directory) || is_link($directory)) {
            return $result;
        }
        $files = glob($directory . '/*.json') ?: [];
        sort($files);
        foreach (array_slice($files, 0, 20) as $file) {
            if (!$canContinue()) {
                break;
            }
            try {
                $requests = self::read($file);
            } catch (\InvalidArgumentException $e) {
                if (rename($file, $file . '.bad')) {
                    $result['quarantined']++;
                }
                continue;
            }
            // Validation precedes every request. A failed API call preserves the file;
            // idempotent daily UPSERTs make a partially completed retry safe.
            foreach ($requests as $request) {
                $ingest($request);
            }
            if (!unlink($file)) {
                throw new \RuntimeException('cannot acknowledge inbox file');
            }
            $result['ingested']++;
        }
        return $result;
    }
}
