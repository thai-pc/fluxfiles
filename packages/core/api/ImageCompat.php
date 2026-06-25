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
     * Whether AVIF encoding is available at runtime. Needs intervention v3 (v2 has
     * no toAvif) AND a GD build compiled with AVIF support (`imageavif()`).
     */
    public function avifSupported(): bool
    {
        return $this->isV3 && \function_exists('imageavif');
    }

    /**
     * Encode the image as AVIF at the given quality (0-100). Smaller than WebP at
     * the same quality, but slower and not universally supported — callers should
     * check {@see avifSupported()} first (or fall back to WebP on failure).
     */
    public function encodeAvif($image, int $quality = 70): string
    {
        if (!$this->avifSupported()) {
            throw new \RuntimeException('AVIF encoding is not available (needs intervention v3 + GD with AVIF)');
        }
        return (string) $image->toAvif($quality);
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

    /**
     * Composite a logo (binary PNG, ideally with alpha) onto $base at a 9-point
     * position with the given opacity (0.0–1.0). The logo is scaled down to
     * $maxLogoWidth first when wider (never upsized). Watermark needs the v3 API.
     *
     * @return object the mutated base image
     */
    public function placeLogo($base, string $logoData, string $position, float $opacity, int $maxLogoWidth)
    {
        if (!$this->isV3) {
            throw new \RuntimeException('Watermark requires intervention/image v3');
        }
        $logo = $this->manager->read($logoData);
        if ($maxLogoWidth > 0 && $logo->width() > $maxLogoWidth) {
            $logo = $logo->scaleDown($maxLogoWidth);
        }
        $pad = $position === 'center' ? 0 : 20;
        $op = (int) round(max(0.0, min(1.0, $opacity)) * 100);
        return $base->place($logo, $position, $pad, $pad, $op);
    }

    /**
     * Composite a logo at a FREE position (fractional 0–1 of the base) and a free
     * size (logo width as a fraction of the base width) — for a drag-and-drop
     * watermark editor. $xPct/$yPct anchor the logo's TOP-LEFT. Clamped so the logo
     * stays on-canvas. Watermark needs the v3 API.
     *
     * @return object the mutated base image
     */
    public function placeLogoAt($base, string $logoData, float $xPct, float $yPct, float $widthPct, float $opacity)
    {
        if (!$this->isV3) {
            throw new \RuntimeException('Watermark requires intervention/image v3');
        }
        $bw = $base->width();
        $bh = $base->height();
        $logo = $this->manager->read($logoData);
        $targetW = (int) round(max(0.01, min(1.0, $widthPct)) * $bw);
        if ($targetW > 0 && $logo->width() !== $targetW) {
            // scale() (not scaleDown) so a small logo can be enlarged on request.
            $logo = $logo->scale($targetW);
        }
        // Offset from top-left; clamp so the (scaled) logo stays inside the canvas.
        $ox = (int) round(max(0.0, min(1.0, $xPct)) * $bw);
        $oy = (int) round(max(0.0, min(1.0, $yPct)) * $bh);
        $ox = max(0, min($ox, $bw - $logo->width()));
        $oy = max(0, min($oy, $bh - $logo->height()));
        $op = (int) round(max(0.0, min(1.0, $opacity)) * 100);
        return $base->place($logo, 'top-left', $ox, $oy, $op);
    }

    /**
     * Draw $text onto $base at a 9-point position with the given opacity using
     * a bundled TTF. v3's align/valign anchor the text box, so no manual text
     * measurement is needed.
     *
     * @return object the mutated base image
     */
    public function drawText($base, string $text, string $position, float $opacity, int $fontSize, string $ttfPath)
    {
        if (!$this->isV3) {
            throw new \RuntimeException('Watermark requires intervention/image v3');
        }
        $pad = 20;
        $w = $base->width();
        $h = $base->height();
        [$x, $y, $align, $valign] = [
            'top-left'     => [$pad, $pad, 'left', 'top'],
            'top-right'    => [$w - $pad, $pad, 'right', 'top'],
            'bottom-left'  => [$pad, $h - $pad, 'left', 'bottom'],
            'bottom-right' => [$w - $pad, $h - $pad, 'right', 'bottom'],
            'center'       => [intdiv($w, 2), intdiv($h, 2), 'center', 'middle'],
        ][$position] ?? [$w - $pad, $h - $pad, 'right', 'bottom'];

        $alpha = max(0.0, min(1.0, $opacity));
        return $base->text($text, $x, $y, function ($font) use ($ttfPath, $fontSize, $alpha, $align, $valign) {
            $font->filename($ttfPath);
            $font->size($fontSize);
            $font->color('rgba(255, 255, 255, ' . $alpha . ')');
            $font->align($align);
            $font->valign($valign);
        });
    }

    /**
     * Draw $text at a FREE position (fractional 0–1 of the base, anchoring the text
     * top-left) with a hex/rgb $color — for the drag-and-drop watermark editor.
     *
     * @return object the mutated base image
     */
    public function drawTextAt($base, string $text, float $xPct, float $yPct, int $fontSize, float $opacity, string $color, string $ttfPath)
    {
        if (!$this->isV3) {
            throw new \RuntimeException('Watermark requires intervention/image v3');
        }
        $x = (int) round(max(0.0, min(1.0, $xPct)) * $base->width());
        $y = (int) round(max(0.0, min(1.0, $yPct)) * $base->height());
        $alpha = max(0.0, min(1.0, $opacity));
        // Accept #rrggbb (default white); apply opacity via rgba when possible.
        $hex = ltrim($color !== '' ? $color : '#ffffff', '#');
        $rgb = strlen($hex) === 6
            ? [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))]
            : [255, 255, 255];
        return $base->text($text, $x, $y, function ($font) use ($ttfPath, $fontSize, $alpha, $rgb) {
            $font->filename($ttfPath);
            $font->size($fontSize);
            $font->color("rgba({$rgb[0]}, {$rgb[1]}, {$rgb[2]}, {$alpha})");
            $font->align('left');
            $font->valign('top');
        });
    }
}
