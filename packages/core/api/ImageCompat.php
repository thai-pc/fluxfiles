<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Compatibility shim for intervention/image versions 2.x → 3.x (→ 4.x).
 *
 * Intervention v3 rewrote the API:
 *   - ImageManager construction takes a Driver instance, not an array config
 *   - $manager->make() became $manager->read()
 *   - $image->encode('webp', q) was split into $image->toWebp(q), toJpeg(q), toPng()
 *   - $image->resize($w, null, fn($c) => { $c->aspectRatio(); $c->upsize(); })
 *     became $image->scaleDown($w)
 *
 * We pick whichever API is present at runtime so composer can resolve to v2
 * on PHP 7.4 (v3 declares `^8.1`) and to v3+ on PHP 8.1+. crop() and
 * width()/height() stayed compatible, so callers can use them on the raw
 * image instance returned from read().
 */
final class ImageCompat
{
    /** @var object Intervention\Image\ImageManager instance (v2 or v3). */
    private $manager;

    /** @var bool true when the installed library is v3+ (uses Driver objects). */
    private $isV3;

    public function __construct()
    {
        // v3+: a Gd Driver class lives at Intervention\Image\Drivers\Gd\Driver.
        // v2 has no such class; we fall back to the array-config constructor.
        $gdDriver = '\\Intervention\\Image\\Drivers\\Gd\\Driver';
        if (\class_exists($gdDriver)) {
            $this->isV3 = true;
            $managerClass = '\\Intervention\\Image\\ImageManager';
            $this->manager = new $managerClass(new $gdDriver());
            return;
        }
        $this->isV3 = false;
        $managerClass = '\\Intervention\\Image\\ImageManager';
        $this->manager = new $managerClass(['driver' => 'gd']);
    }

    /**
     * Load an image from a path or raw binary. Returns the library's native
     * Image object so the caller can chain ->crop()/->width()/->height() which
     * stayed compatible between v2 and v3.
     *
     * @param string $input file path or binary data
     * @return object Intervention Image instance
     */
    public function read(string $input)
    {
        return $this->isV3 ? $this->manager->read($input) : $this->manager->make($input);
    }

    /**
     * Encode the image as WebP at the given quality (0-100).
     */
    public function encodeWebp($image, int $quality = 80): string
    {
        return (string) ($this->isV3 ? $image->toWebp($quality) : $image->encode('webp', $quality));
    }

    /**
     * Encode the image as JPEG.
     */
    public function encodeJpeg($image, int $quality = 85): string
    {
        return (string) ($this->isV3 ? $image->toJpeg($quality) : $image->encode('jpg', $quality));
    }

    /**
     * Encode the image as PNG.
     */
    public function encodePng($image): string
    {
        return (string) ($this->isV3 ? $image->toPng() : $image->encode('png'));
    }

    /**
     * Resize the image so its longest side does not exceed $maxWidth while
     * keeping aspect ratio and refusing to upsize. v3 has scaleDown(); v2 needs
     * the callback form on resize().
     *
     * @return object Intervention Image instance (mutated/new — depends on lib)
     */
    public function scaleDown($image, int $maxWidth)
    {
        if ($this->isV3) {
            return $image->scaleDown($maxWidth);
        }
        return $image->resize($maxWidth, null, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
    }
}
