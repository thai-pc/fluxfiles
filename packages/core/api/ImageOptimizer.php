<?php

declare(strict_types=1);

namespace FluxFiles;

use League\Flysystem\Filesystem;

class ImageOptimizer
{
    /** @var ImageCompat */
    private $manager;

    /** Default variant widths (px). Overridable per-tenant via the JWT `variants` claim. */
    private const DEFAULT_VARIANTS = [
        'thumb'  => 150,
        'medium' => 768,
        'large'  => 1920,
    ];

    /** @var array<string,int> Effective variant widths for this instance. */
    private $variants;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /** Raster formats we'll transform on demand. SVG (vector) and animated GIF are skipped. */
    private const TRANSFORMABLE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_BMP, IMAGETYPE_WEBP];

    /**
     * Decompression-bomb guard: refuse to decode a source whose pixel count is
     * absurd (a few-KB file can claim 30000×30000). 30 MP ≈ a 6720×4480 photo —
     * well above any real upload, far below a memory-exhausting bomb.
     */
    private const MAX_SOURCE_PIXELS = 30000000;

    /**
     * @param array<string,int>|null $variants Per-tenant width overrides for the
     *        known size names (thumb/medium/large). Unset names keep the default.
     */
    public function __construct(?array $variants = null)
    {
        $this->manager = new ImageCompat();
        $this->variants = self::DEFAULT_VARIANTS;
        if ($variants !== null) {
            foreach (self::DEFAULT_VARIANTS as $name => $_) {
                if (isset($variants[$name]) && (int) $variants[$name] > 0) {
                    $this->variants[$name] = (int) $variants[$name];
                }
            }
        }
    }

    public function isImage(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * @return array{data: string, mime: string, width: int, height: int}
     */
    public function crop(
        string $imageData,
        int $x,
        int $y,
        int $width,
        int $height,
        string $format = 'png',
        int $quality = 90
    ): array {
        $image = $this->manager->read($imageData);
        $image = $image->crop($width, $height, $x, $y);

        switch ($format) {
            case 'webp':
                $encoded = $this->manager->encodeWebp($image, $quality);
                $mime = 'image/webp';
                break;
            case 'jpg':
            case 'jpeg':
                $encoded = $this->manager->encodeJpeg($image, $quality);
                $mime = 'image/jpeg';
                break;
            default:
                $encoded = $this->manager->encodePng($image);
                $mime = 'image/png';
                break;
        }

        return [
            'data'   => (string) $encoded,
            'mime'   => $mime,
            'width'  => $image->width(),
            'height' => $image->height(),
        ];
    }

    /**
     * Convert raster image bytes to a resized WebP (on-demand transform).
     *
     * Returns null — meaning "serve the original unchanged" — when the source
     * shouldn't or can't be safely transformed: not a decodable raster (SVG,
     * corrupt, non-image), an animated GIF (would be flattened), or a
     * decompression bomb. `$width <= 0` keeps the original dimensions; never
     * upsizes (scaleDown).
     *
     * @return array{data: string, width: int, height: int}|null
     */
    public function transform(string $sourceData, int $width, int $quality): ?array
    {
        $info = @getimagesizefromstring($sourceData);
        if ($info === false) {
            return null; // SVG / corrupt / not a raster image → leave it alone
        }
        [$srcW, $srcH] = $info;
        if ($srcW <= 0 || $srcH <= 0 || ($srcW * $srcH) > self::MAX_SOURCE_PIXELS) {
            return null; // decompression-bomb guard
        }
        if (!in_array($info[2] ?? 0, self::TRANSFORMABLE_TYPES, true)) {
            return null;
        }
        if (($info[2] ?? 0) === IMAGETYPE_GIF && self::isAnimatedGif($sourceData)) {
            return null; // don't flatten an animated GIF to a single frame
        }

        $image = $this->manager->read($sourceData);
        if ($width > 0) {
            $image = $this->manager->scaleDown($image, $width);
        }
        $encoded = $this->manager->encodeWebp($image, $quality);

        return [
            'data'   => (string) $encoded,
            'width'  => $image->width(),
            'height' => $image->height(),
        ];
    }

    /**
     * Cache key for an on-demand WebP transform. Lives inside the file's
     * `_variants/` directory so the existing delete/trash cleanup (which removes
     * the whole `_variants` subtree) invalidates it for free. `$ver` (the source
     * file's mtime or hash) is embedded so a re-upload produces a new key and the
     * stale cache is never matched again.
     */
    public static function transformCacheKey(string $key, int $width, int $quality, string $ver): string
    {
        $dir = dirname($key);
        $basename = pathinfo($key, PATHINFO_FILENAME);
        $variantsDir = ($dir !== '.' && $dir !== '') ? $dir . '/_variants' : '_variants';
        $ver = substr(preg_replace('/[^A-Za-z0-9]/', '', $ver) ?? '', 0, 12);
        return $variantsDir . '/' . $basename . '_w' . $width . '_q' . $quality . '_' . $ver . '.webp';
    }

    /**
     * Detect a multi-frame (animated) GIF by counting Graphic Control Extension
     * blocks. A static GIF has at most one; two or more means animation.
     */
    public static function isAnimatedGif(string $bytes): bool
    {
        if (strncmp($bytes, 'GIF', 3) !== 0) {
            return false;
        }
        $frames = 0;
        $offset = 0;
        // 0x21 0xF9 0x04 = Graphic Control Extension (one per frame).
        while (($pos = strpos($bytes, "\x21\xF9\x04", $offset)) !== false) {
            $frames++;
            if ($frames > 1) {
                return true;
            }
            $offset = $pos + 3;
        }
        return false;
    }

    public function process(
        Filesystem $fs,
        string $filePath,
        string $tmpFile
    ): array {
        $dir = dirname($filePath);
        $basename = pathinfo($filePath, PATHINFO_FILENAME);
        $variantsDir = ($dir !== '.' && $dir !== '' ? $dir . '/' : '') . '_variants';

        $image = $this->manager->read($tmpFile);
        $originalWidth = $image->width();

        $variants = [];

        foreach ($this->variants as $name => $maxWidth) {
            if ($originalWidth <= $maxWidth && $name !== 'thumb') {
                continue;
            }

            $resized = $this->manager->read($tmpFile);
            $resized = $this->manager->scaleDown($resized, $maxWidth);
            $encoded = $this->manager->encodeWebp($resized, 80);
            $variantPath = $variantsDir . '/' . $basename . '_' . $name . '.webp';

            $fs->write($variantPath, (string) $encoded);

            $variants[$name] = [
                'key'    => $variantPath,
                'width'  => $resized->width(),
                'height' => $resized->height(),
            ];
        }

        return $variants;
    }
}
