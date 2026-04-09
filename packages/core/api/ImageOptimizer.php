<?php

declare(strict_types=1);

namespace FluxFiles;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use League\Flysystem\Filesystem;

class ImageOptimizer
{
    /** @var ImageManager */
    private $manager;

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
                $encoded = $image->toWebp($quality);
                $mime = 'image/webp';
                break;
            case 'jpg':
            case 'jpeg':
                $encoded = $image->toJpeg($quality);
                $mime = 'image/jpeg';
                break;
            default:
                $encoded = $image->toPng();
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

        foreach (self::VARIANTS as $name => $maxWidth) {
            if ($originalWidth <= $maxWidth && $name !== 'thumb') {
                continue;
            }

            $resized = $this->manager->read($tmpFile);
            $resized = $resized->scaleDown($maxWidth);
            $encoded = $resized->toWebp(80);
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
