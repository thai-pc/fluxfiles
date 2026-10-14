<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Resumable uploads — the tus 1.0.0 core protocol + `creation` and
 * `termination` extensions — for disks the S3-multipart chunk API can't serve
 * (local, SFTP), so a large file is no longer capped by PHP's
 * upload_max_filesize/post_max_size and a dropped connection resumes instead
 * of starting over.
 *
 *   POST   /api/fm/tus        Upload-Length + Upload-Metadata (filename, path, disk)
 *   HEAD   /api/fm/tus/<id>   → Upload-Offset / Upload-Length
 *   PATCH  /api/fm/tus/<id>   Upload-Offset + application/offset+octet-stream body
 *   DELETE /api/fm/tus/<id>   abandon
 *
 * Partial bytes are staged on the SERVER (`<storage>/tus/<id>.part` + `.json`),
 * the same class of ephemeral runtime state as rate_limit.json — never in the
 * user's storage, and swept after EXPIRY_SEC. A multi-node deployment needs
 * that directory shared, or sticky sessions. When the last byte arrives the
 * staged file goes through FileManager::upload() — the exact pipeline a normal
 * upload takes (extension rules, quota, virus/DLP scan, strip_exif, variants,
 * metadata, ownership) — so tus is a transport, not a second write path.
 *
 * An upload belongs to the user (and disk) that created it; any other caller
 * gets 404, never a hint that the id exists.
 */
final class TusServer
{
    public const VERSION = '1.0.0';
    public const EXPIRY_SEC = 86400;
    private const MAX_ACTIVE_PER_USER = 20;
    private const ID_RE = '/^[a-f0-9]{32}$/';

    private string $dir;

    public function __construct(string $stateDir)
    {
        $this->dir = rtrim($stateDir, '/') . '/tus';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    /**
     * @param array<string,string> $headers lower-cased request headers
     * @param resource             $body    request body stream (PATCH)
     * @return array{0:int,1:array<string,string>,2:array<string,mixed>|null} status, headers, finished-upload result
     */
    public function handle(string $method, string $id, array $headers, $body, FileManager $fm, Claims $claims): array
    {
        $base = ['Tus-Resumable' => self::VERSION, 'Cache-Control' => 'no-store'];
        if ($method === 'OPTIONS') {
            return [204, $base + ['Tus-Version' => self::VERSION, 'Tus-Extension' => 'creation,termination',
                'Tus-Max-Size' => (string) ($claims->maxUploadMb * 1024 * 1024)], null];
        }
        if (($headers['tus-resumable'] ?? '') !== self::VERSION) {
            return [412, $base + ['Tus-Version' => self::VERSION], null];
        }

        if ($method === 'POST' && $id === '') {
            return $this->create($headers, $fm, $claims, $base);
        }
        if (!preg_match(self::ID_RE, $id)) {
            return [404, $base, null];
        }
        $state = $this->load($id);
        if ($state === null || $state['user'] !== $claims->userId || $state['expires'] < time()) {
            return [404, $base, null];
        }
        $base['Upload-Expires'] = gmdate('D, d M Y H:i:s \G\M\T', $state['expires']);

        if ($method === 'HEAD') {
            return [200, $base + ['Upload-Offset' => (string) $state['offset'], 'Upload-Length' => (string) $state['length']], null];
        }
        if ($method === 'DELETE') {
            $this->remove($id);
            return [204, $base, null];
        }
        if ($method === 'PATCH') {
            return $this->patch($id, $state, $headers, $body, $fm, $base);
        }
        return [405, $base, null];
    }

    /** @param array<string,string> $base */
    private function create(array $headers, FileManager $fm, Claims $claims, array $base): array
    {
        if (!isset($headers['upload-length']) || !ctype_digit($headers['upload-length'])) {
            return [400, $base, null]; // Upload-Defer-Length is not supported
        }
        $length = (int) $headers['upload-length'];
        $meta = self::parseMetadata($headers['upload-metadata'] ?? '');
        $name = trim((string) ($meta['filename'] ?? ''));
        $disk = (string) ($meta['disk'] ?? 'local');
        $path = (string) ($meta['path'] ?? '');
        if ($name === '' || $length <= 0 || preg_match('/[<>:"\/\\\\|?*\x00-\x1f]/', $name)) {
            return [400, $base, null];
        }
        // Fail fast on what upload() would refuse at the end anyway (disk, perm,
        // extension, dangerous name, size, path scope) — before any byte is staged.
        if (!$claims->hasDisk($disk) || !$claims->hasPerm('write')) {
            throw new ApiException('Permission denied: write', 403, 'permission_denied');
        }
        $fm->validateUploadName($name, $length);
        $fm->validateUserPath(rtrim($path, '/') . '/' . $name, $disk);

        $this->sweep();
        if ($this->activeFor($claims->userId) >= self::MAX_ACTIVE_PER_USER) {
            throw new ApiException('Too many unfinished uploads', 429, 'rate_limited');
        }
        $id = bin2hex(random_bytes(16));
        $state = ['user' => $claims->userId, 'disk' => $disk, 'path' => $path, 'name' => $name,
            'length' => $length, 'offset' => 0, 'expires' => time() + self::EXPIRY_SEC];
        touch($this->part($id));
        @chmod($this->part($id), 0600);
        $this->save($id, $state);
        return [201, $base + ['Location' => '/api/fm/tus/' . $id, 'Upload-Offset' => '0',
            'Upload-Expires' => gmdate('D, d M Y H:i:s \G\M\T', $state['expires'])], null];
    }

    /**
     * @param array<string,mixed>  $state
     * @param array<string,string> $headers
     * @param resource             $body
     * @param array<string,string> $base
     */
    private function patch(string $id, array $state, array $headers, $body, FileManager $fm, array $base): array
    {
        if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/offset+octet-stream') {
            return [415, $base, null];
        }
        if (!isset($headers['upload-offset']) || (int) $headers['upload-offset'] !== (int) $state['offset']) {
            return [409, $base + ['Upload-Offset' => (string) $state['offset']], null];
        }
        $fh = fopen($this->part($id), 'r+b');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            return [423, $base, null]; // another PATCH for this upload is still running
        }
        try {
            // Re-read under the lock: a concurrent PATCH may have advanced it.
            $state = $this->load($id) ?? $state;
            if ((int) $headers['upload-offset'] !== (int) $state['offset']) {
                return [409, $base + ['Upload-Offset' => (string) $state['offset']], null];
            }
            fseek($fh, (int) $state['offset']);
            $remaining = (int) $state['length'] - (int) $state['offset'];
            while (!feof($body)) {
                $chunk = fread($body, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if (strlen($chunk) > $remaining) {
                    // More bytes than Upload-Length promised — keep what fits, refuse.
                    fwrite($fh, substr($chunk, 0, $remaining));
                    $state['offset'] += $remaining;
                    $this->save($id, $state);
                    return [413, $base + ['Upload-Offset' => (string) $state['offset']], null];
                }
                fwrite($fh, $chunk);
                $state['offset'] += strlen($chunk);
                $remaining -= strlen($chunk);
            }
            fflush($fh);
            $this->save($id, $state);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $headersOut = $base + ['Upload-Offset' => (string) $state['offset']];
        if ((int) $state['offset'] < (int) $state['length']) {
            return [204, $headersOut, null];
        }
        // Complete → the normal upload pipeline. A refusal there (scan, quota,
        // collision…) propagates as the usual JSON error; the staged bytes go either way.
        try {
            $result = $fm->upload($state['disk'], $state['path'], [
                'name' => $state['name'], 'tmp_name' => $this->part($id),
                'size' => (int) $state['length'], 'type' => '', 'error' => 0,
            ]);
        } finally {
            $this->remove($id);
        }
        $result['disk'] = $state['disk'];
        return [204, $headersOut, $result];
    }

    /** tus Upload-Metadata: `key base64value,key2 base64value2`. @return array<string,string> */
    public static function parseMetadata(string $raw): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            $parts = preg_split('/\s+/', $pair, 2);
            $k = (string) ($parts[0] ?? '');
            if ($k === '') {
                continue;
            }
            $v = isset($parts[1]) ? base64_decode($parts[1], true) : '';
            $out[$k] = $v === false ? '' : $v;
        }
        return $out;
    }

    /** Drop expired uploads (opportunistic, on every create). */
    private function sweep(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $s = json_decode((string) @file_get_contents($f), true);
            if (!is_array($s) || (int) ($s['expires'] ?? 0) < time()) {
                $this->remove(basename($f, '.json'));
            }
        }
    }

    private function activeFor(string $user): int
    {
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $s = json_decode((string) @file_get_contents($f), true);
            if (is_array($s) && ($s['user'] ?? null) === $user) {
                $n++;
            }
        }
        return $n;
    }

    private function part(string $id): string { return $this->dir . '/' . $id . '.part'; }

    /** @return array<string,mixed>|null */
    private function load(string $id): ?array
    {
        $s = json_decode((string) @file_get_contents($this->dir . '/' . $id . '.json'), true);
        return is_array($s) ? $s : null;
    }

    /** @param array<string,mixed> $state */
    private function save(string $id, array $state): void
    {
        $f = $this->dir . '/' . $id . '.json';
        file_put_contents($f, (string) json_encode($state), LOCK_EX);
        @chmod($f, 0600);
    }

    private function remove(string $id): void
    {
        @unlink($this->part($id));
        @unlink($this->dir . '/' . $id . '.json');
    }
}
