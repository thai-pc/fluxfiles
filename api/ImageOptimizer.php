<?php

declare(strict_types=1);

namespace FluxFiles;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use League\Flysystem\Filesystem;

class ImageOptimizer
{
    private ImageManager $manager;

    private const VARIANTS = [
        'thumb'  => 150,
        'medium' => 768,
        'large'  => 1920,
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function __construct()
    {
        $this->manager = new ImageManager(new GdDriver());
    }

    public function isImage(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Crop an image and return the encoded result.
     *
     * @param string $imageData  Raw image bytes (from Flysystem read)
     * @param int    $x          Crop X offset
     * @param int    $y          Crop Y offset
     * @param int    $width      Crop width
     * @param int    $height     Crop height
     * @param string $format     Output format: original extension or 'webp'/'png'/'jpg'
     * @param int    $quality    Encode quality (1-100)
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

        $encoded = match ($format) {
            'webp'         => $image->toWebp(quality: $quality),
            'jpg', 'jpeg'  => $image->toJpeg(quality: $quality),
            default        => $image->toPng(),
        };

        $mime = match ($format) {
            'webp'         => 'image/webp',
            'jpg', 'jpeg'  => 'image/jpeg',
            default        => 'image/png',
        };

        return [
            'data'   => (string) $encoded,
            'mime'   => $mime,
            'width'  => $image->width(),
            'height' => $image->height(),
        ];
    }

    /**
     * Process uploaded image: create variants and optionally convert to WebP.
     * Returns array of variant URLs keyed by size name.
     */
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
        $originalHeight = $image->height();

        $variants = [];

        foreach (self::VARIANTS as $name => $maxWidth) {
            // Skip variant if original is smaller than target
            if ($originalWidth <= $maxWidth && $name !== 'thumb') {
                continue;
            }

            $resized = $this->manager->read($tmpFile);

            // Resize maintaining aspect ratio
            $resized = $resized->scaleDown(width: $maxWidth);

            // Encode as WebP
            $encoded = $resized->toWebp(quality: 80);
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
