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
