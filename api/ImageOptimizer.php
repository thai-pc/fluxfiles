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
