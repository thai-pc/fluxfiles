<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Short-lived, single-file token for on-demand WebP transforms (`/api/fm/img`).
 *
 * An `<img>` element can't send an Authorization header, so the token rides the
 * query string — scoped to one disk+path with a short TTL, like the media stream
 * token. It additionally carries the per-tenant `mw` (max width) so the serving
 * endpoint can clamp resize requests without needing the main access JWT. A
 * distinct `t=img` type keeps it from being usable on the raw `/stream` endpoint
 * (and vice versa) — an img token only ever yields a transformed WebP, never the
 * raw original bytes of an arbitrary file.
 */
final class ImageToken
{
    private const TYPE = 'img';
    public const MAX_TTL = 86400; // 24h

    /**
     * @param int $maxWidth Per-tenant clamp on the requested resize width.
     * @param int $defaultQuality WebP quality used when a request omits it (0 = 80).
     * @param array|null $watermark Per-tenant watermark config (embedded only when
     *        enabled): type, text, logo_path, position, opacity, font_size. The
     *        serve endpoint applies it; the source file is never modified.
     */
    public static function mint(
        string $disk,
        string $path,
        string $sub,
        int $ttl,
        string $secret,
        int $maxWidth,
        int $defaultQuality = 0,
        ?array $watermark = null
    ): string {
        $ttl = max(1, min($ttl, self::MAX_TTL));
        $now = time();
        $payload = [
            't'    => self::TYPE,
            'disk' => $disk,
            'path' => $path,
            'mw'   => max(0, $maxWidth),
            'dq'   => max(0, $defaultQuality),
            'sub'  => $sub,
            'iat'  => $now,
            'exp'  => $now + $ttl,
        ];
        if ($watermark !== null && !empty($watermark['enabled'])) {
            $payload['wm'] = $watermark;
        }
        return JwtCompat::encode($payload, $secret);
    }

    /**
     * @return array{disk:string,path:string,maxWidth:int,defaultQuality:int,sub:string,watermark:array|null}
     */
    public static function verify(string $token, string $secret): array
    {
        if ($token === '' || $secret === '') {
            throw new ApiException('Image token required', 403, 'img_token_invalid');
        }
        try {
            $p = JwtCompat::decode($token, $secret);
        } catch (\Throwable $e) {
            throw new ApiException('Image token invalid or expired', 403, 'img_token_invalid');
        }
        if (($p->t ?? null) !== self::TYPE) {
            throw new ApiException('Not an image token', 403, 'img_token_invalid');
        }
        $disk = (string) ($p->disk ?? '');
        $path = (string) ($p->path ?? '');
        if ($disk === '' || $path === '') {
            throw new ApiException('Image token missing scope', 403, 'img_token_invalid');
        }
        return [
            'disk'           => $disk,
            'path'           => $path,
            'maxWidth'       => max(0, (int) ($p->mw ?? 0)),
            'defaultQuality' => max(0, (int) ($p->dq ?? 0)),
            'sub'            => (string) ($p->sub ?? ''),
            'watermark'      => isset($p->wm) ? (array) json_decode(json_encode($p->wm), true) : null,
        ];
    }
}
