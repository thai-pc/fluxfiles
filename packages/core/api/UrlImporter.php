<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Upload-from-URL (v1, synchronous). Fetches a user-supplied public URL on the
 * server, validates it hard, and hands the result to the existing upload pipeline
 * (FileManager::upload) so it gets the same dedup / variants / AI-tag / metadata.
 *
 * Security model (see SsrfGuard):
 *   - Pre-fetch: SsrfGuard::assertSafeUrl on the URL and on EVERY redirect hop.
 *   - Per connection: SsrfGuard::assertConnectedIpSafe (DNS-rebinding backstop).
 *   - Streaming size cap (progress callback aborts past max), never buffer to RAM.
 *   - Magic-byte MIME (finfo) — never trust the remote Content-Type — plus a hard
 *     deny-list for executable / markup types, and SVG denied unless enabled.
 *
 * Redirects are followed manually (curl's FOLLOWLOCATION can't re-validate each
 * hop's IP in PHP), max 3.
 */
final class UrlImporter
{
    private const DEFAULT_MAX_BYTES = 52428800;  // 50 MB
    private const MAX_REDIRECTS = 3;

    /** Always rejected regardless of the upload extension policy. */
    private const DENIED_MIME = [
        'text/html', 'application/xhtml+xml',
        'application/x-php', 'application/x-httpd-php', 'text/x-php',
        'application/x-sh', 'text/x-shellscript', 'application/x-csh',
        'application/x-executable', 'application/x-msdos-program', 'application/x-msdownload',
        'application/x-dosexec', 'application/vnd.microsoft.portable-executable',
        'application/javascript', 'text/javascript',
    ];

    /** Detected MIME → canonical extension, so the upload policy sees a coherent name. */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif', 'image/bmp' => 'bmp',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/ogg' => 'ogg',
        'text/plain' => 'txt', 'text/csv' => 'csv', 'text/markdown' => 'md',
        'application/zip' => 'zip', 'application/json' => 'json',
    ];

    public function __construct(private Claims $claims, private FileManager $fm) {}

    /**
     * Import $url into $disk. Returns the same record shape as FileManager::upload.
     *
     * @param array{path?:string,filename?:string,overwrite?:bool} $opts
     */
    public function import(string $disk, string $url, array $opts = []): array
    {
        if (!$this->claims->allowUrlImport) {
            throw new ApiException('URL import is not enabled for this account', 403, 'forbidden');
        }
        if (trim($url) === '') {
            throw new ApiException('A URL is required', 422, 'url_invalid');
        }

        $maxBytes = $this->claims->maxImportSize > 0
            ? $this->claims->maxImportSize
            : (int) ($_ENV['FLUXFILES_IMPORT_MAX_BYTES'] ?? self::DEFAULT_MAX_BYTES);

        $tmp = tempnam(sys_get_temp_dir(), 'ffimp_');
        if ($tmp === false) {
            throw new ApiException('Could not allocate a temp file', 500, 'server_error');
        }

        try {
            $disposition = $this->fetchToTemp($url, $tmp, $maxBytes);
            return $this->finishImport($tmp, $disk, $url, $disposition, $opts);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Stream-fetch with per-hop SSRF re-validation. Returns the final
     * Content-Disposition header (or '') for filename resolution.
     */
    private function fetchToTemp(string $url, string $tmpPath, int $maxBytes): string
    {
        $allowlist = $this->claims->importUrlAllowlist;
        $timeout = (int) ($_ENV['FLUXFILES_IMPORT_TIMEOUT'] ?? 30);
        $current = $url;
        $disposition = '';

        for ($hop = 0; ; $hop++) {
            SsrfGuard::assertSafeUrl($current, $allowlist);   // every hop, before connecting

            $fh = fopen($tmpPath, 'w');
            if ($fh === false) {
                throw new ApiException('Could not open temp file', 500, 'server_error');
            }
            $disposition = '';
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION  => false,             // we follow manually to re-validate
                CURLOPT_FILE            => $fh,
                CURLOPT_CONNECTTIMEOUT  => 10,
                CURLOPT_TIMEOUT         => $timeout,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE     => $maxBytes,         // aborts on a too-big Content-Length
                CURLOPT_NOPROGRESS      => false,
                CURLOPT_PROGRESSFUNCTION => static function ($c, $dlTotal, $dlNow) use ($maxBytes) {
                    return ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0;  // non-zero aborts (chunked cap)
                },
                CURLOPT_HEADERFUNCTION  => static function ($c, $line) use (&$disposition) {
                    if (stripos($line, 'content-disposition:') === 0) {
                        $disposition = trim(substr($line, 20));
                    }
                    return strlen($line);
                },
                CURLOPT_USERAGENT       => 'FluxFiles-URLImport/1.0',
                CURLOPT_ACCEPT_ENCODING => '',                // let curl handle gzip transparently
            ]);

            $ok = curl_exec($ch);
            $errno = curl_errno($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

            // DNS-rebinding backstop: the IP curl actually connected to.
            try {
                SsrfGuard::assertConnectedIpSafe($ch);
            } catch (ApiException $e) {
                curl_close($ch); fclose($fh); throw $e;
            }
            curl_close($ch);
            fclose($fh);

            if ($ok === false) {
                if ($errno === CURLE_FILESIZE_EXCEEDED || $errno === CURLE_ABORTED_BY_CALLBACK) {
                    throw new ApiException('File exceeds the import size limit', 422, 'size_exceeded');
                }
                throw new ApiException('Could not download from this URL', 422, 'fetch_failed');
            }

            // Redirect — re-validate the next hop.
            if ($code >= 300 && $code < 400 && $redirectUrl !== '') {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new ApiException('Too many redirects', 422, 'redirect_loop');
                }
                $current = $redirectUrl;
                continue;
            }
            if ($code === 401 || $code === 403) {
                throw new ApiException('The remote URL requires authentication', 422, 'auth_required');
            }
            if ($code < 200 || $code >= 300) {
                throw new ApiException('Could not download from this URL', 422, 'fetch_failed');
            }
            break;
        }

        // Final size cap (servers without Content-Length / mis-reporting).
        if (filesize($tmpPath) > $maxBytes) {
            throw new ApiException('File exceeds the import size limit', 422, 'size_exceeded');
        }
        if (filesize($tmpPath) === 0) {
            throw new ApiException('The URL returned an empty file', 422, 'fetch_failed');
        }
        return $disposition;
    }

    /**
     * Post-fetch (no network): magic-byte MIME + deny-list, derive a safe filename
     * with the right extension, then hand the temp file to FileManager::upload.
     *
     * @param array{path?:string,filename?:string,overwrite?:bool} $opts
     */
    public function finishImport(string $tmpPath, string $disk, string $sourceUrl, string $disposition = '', array $opts = []): array
    {
        $mime = $this->detectMime($tmpPath);
        if (in_array($mime, self::DENIED_MIME, true)) {
            throw new ApiException('This file type cannot be imported', 422, 'content_denied');
        }
        // SVG only with an explicit opt-in (it can carry script); denied by default in v1.
        if ($mime === 'image/svg+xml' && ($_ENV['FLUXFILES_IMPORT_ALLOW_SVG'] ?? '') !== 'true') {
            throw new ApiException('SVG import is disabled', 422, 'content_denied');
        }

        $name = $this->resolveFilename($sourceUrl, $disposition, $opts['filename'] ?? null, $mime);

        // A path forced by the JWT claim wins over the request path.
        $path = $this->claims->importPath !== null && $this->claims->importPath !== ''
            ? $this->claims->importPath
            : (string) ($opts['path'] ?? '');

        $file = [
            'name'     => $name,
            'type'     => $mime,
            'tmp_name' => $tmpPath,
            'size'     => (int) filesize($tmpPath),
            'error'    => 0,
        ];

        // Same pipeline as a normal upload: extension/quota/dedup/variants/AI-tag.
        return $this->fm->upload($disk, $path, $file, (bool) ($opts['overwrite'] ?? false));
    }

    /** Detect the real MIME from the file's magic bytes, not the remote header. */
    private function detectMime(string $tmpPath): string
    {
        $mime = @mime_content_type($tmpPath);
        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * Filename priority: user input → Content-Disposition → URL path → fallback.
     * Always sanitized to a basename, and given an extension matching the detected
     * MIME so the upload extension policy stays coherent.
     */
    public function resolveFilename(string $url, string $disposition, ?string $userFilename, string $mime): string
    {
        $candidate = '';
        if ($userFilename !== null && trim($userFilename) !== '') {
            $candidate = $userFilename;
        } elseif ($disposition !== '' && preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m)) {
            $candidate = urldecode($m[1]);
        } else {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $candidate = $path !== '' ? rawurldecode(basename($path)) : '';
        }

        $candidate = basename(str_replace(['\\', "\0"], ['/', ''], $candidate)); // strip traversal/null
        $candidate = preg_replace('/[^A-Za-z0-9._-]+/', '-', $candidate) ?? '';
        $candidate = trim($candidate, '-._');
        if ($candidate === '' || $candidate === '.') {
            $candidate = 'import';
        }

        // Ensure an extension that matches the detected MIME.
        $wantExt = self::MIME_EXT[$mime] ?? null;
        $curExt = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        if ($wantExt !== null && $curExt !== $wantExt) {
            // jpeg/jpg are interchangeable; don't rename a valid jpg to jpg twice.
            if (!($wantExt === 'jpg' && in_array($curExt, ['jpg', 'jpeg'], true))) {
                $base = $curExt !== '' ? substr($candidate, 0, -(strlen($curExt) + 1)) : $candidate;
                $candidate = $base . '.' . $wantExt;
            }
        } elseif ($curExt === '' && $wantExt === null) {
            $candidate .= '.bin';
        }

        return $candidate;
    }
}
