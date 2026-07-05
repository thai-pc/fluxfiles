<?php

defined('ABSPATH') || exit;

/**
 * Attachment bridge — the "3-in-1" piece that lets FluxFiles replace WP Offload Media.
 *
 * When a user picks a FluxFiles file, we register it as a WordPress attachment whose
 * URL points at the FluxFiles/S3/R2/SFTP location (never copied into wp-content/uploads).
 * URL filters then rewrite wp_get_attachment_url()/_image_src() to serve from FluxFiles,
 * so the file shows in the Media Library and works in posts, themes and Gutenberg exactly
 * like a native attachment — but is "offloaded" to your bucket. This is how WP Offload
 * Media works; here it comes free with the storage FluxFiles already manages.
 *
 * Marker: attachments created by us carry `_fluxfiles_url` (+ `_fluxfiles_key`/`_disk`)
 * post-meta. The filters no-op on every normal attachment (no marker → untouched).
 */
class FluxFilesAttachments
{
    private const META_URL  = '_fluxfiles_url';
    private const META_KEY  = '_fluxfiles_key';
    private const META_DISK = '_fluxfiles_disk';

    /** Register the URL-rewrite filters. Called once on plugins_loaded. */
    public static function register(): void
    {
        if (!apply_filters('fluxfiles_enable_offload', get_option('fluxfiles_offload', '1') === '1')) {
            return;
        }
        add_filter('wp_get_attachment_url', [self::class, 'filterUrl'], 10, 2);
        add_filter('wp_get_attachment_image_src', [self::class, 'filterImageSrc'], 10, 4);
        // Attachment deleted in WP → optionally delete the backing file in FluxFiles
        // storage too. Off by default (safe: the file survives in your bucket).
        add_action('delete_attachment', [self::class, 'onDelete']);
    }

    /**
     * Find an existing FluxFiles attachment by storage key, or create one pointing at
     * the picked file. Idempotent — picking the same file twice reuses the attachment.
     *
     * @param array{url:string,key?:string,disk?:string,name?:string,basename?:string,
     *              mime?:string,width?:int,height?:int,size?:int} $file
     * @return array{id:int,url:string,mime:string}
     */
    public static function findOrCreate(array $file): array
    {
        $url = (string) ($file['url'] ?? '');
        if ($url === '') {
            throw new InvalidArgumentException('A file url is required');
        }
        $key  = (string) ($file['key'] ?? ($file['path'] ?? $url));
        $disk = (string) ($file['disk'] ?? 'local');
        $name = (string) ($file['basename'] ?? ($file['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: $key)));
        $mime = (string) ($file['mime'] ?? (wp_check_filetype($name)['type'] ?: 'application/octet-stream'));
        $alt  = trim((string) ($file['alt'] ?? ''));
        $caption = trim((string) ($file['caption'] ?? ''));

        $existing = self::findByKey($disk, $key);
        if ($existing) {
            // Refresh alt/caption if the pick carries newer metadata.
            if ($alt !== '') { update_post_meta($existing, '_wp_attachment_image_alt', $alt); }
            return ['id' => $existing, 'url' => $url, 'mime' => $mime];
        }

        $attachId = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name(pathinfo($name, PATHINFO_FILENAME) ?: $name),
            'post_content'   => '',
            'post_excerpt'   => $caption, // WP stores the caption as the excerpt
            'post_status'    => 'inherit',
            'guid'           => esc_url_raw($url),
        ], false, 0, true);

        if (is_wp_error($attachId) || !$attachId) {
            throw new RuntimeException('Could not create the attachment');
        }

        update_post_meta($attachId, self::META_URL, esc_url_raw($url));
        update_post_meta($attachId, self::META_KEY, $key);
        update_post_meta($attachId, self::META_DISK, $disk);
        if ($alt !== '') {
            update_post_meta($attachId, '_wp_attachment_image_alt', $alt);
        }

        // Give WP dimensions so responsive images / editors behave (no local sizes —
        // FluxFiles serves resized variants on demand via /img).
        $w = (int) ($file['width'] ?? 0);
        $h = (int) ($file['height'] ?? 0);
        $meta = ['file' => $key, 'sizes' => [], 'image_meta' => []];
        if ($w > 0 && $h > 0) {
            $meta['width'] = $w;
            $meta['height'] = $h;
        }
        wp_update_attachment_metadata($attachId, $meta);

        return ['id' => (int) $attachId, 'url' => $url, 'mime' => $mime];
    }

    /** Look up an attachment id by its FluxFiles disk+key marker. */
    private static function findByKey(string $disk, string $key): int
    {
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => self::META_KEY, 'value' => $key],
                ['key' => self::META_DISK, 'value' => $disk],
            ],
        ]);
        return $q->have_posts() ? (int) $q->posts[0] : 0;
    }

    /** wp_get_attachment_url filter → serve our attachments from FluxFiles. */
    public static function filterUrl($url, $postId)
    {
        $ff = get_post_meta($postId, self::META_URL, true);
        return is_string($ff) && $ff !== '' ? $ff : $url;
    }

    /** wp_get_attachment_image_src filter → point the <img> src at FluxFiles. */
    public static function filterImageSrc($image, $attachmentId, $size, $icon)
    {
        $ff = get_post_meta($attachmentId, self::META_URL, true);
        if (is_string($ff) && $ff !== '' && is_array($image)) {
            $image[0] = $ff;
        }
        return $image;
    }

    /**
     * A STABLE public URL for disk+key, or null when the disk can't produce one that
     * survives (private S3/R2 → expiring presigned; SFTP / gated local → tokened). The
     * offload bridge needs a stable URL, so migration only targets disks that pass this.
     *
     * @param array<string,mixed> $config the disk config
     */
    public static function stableUrl(array $config, string $key): ?string
    {
        $driver = $config['driver'] ?? '';
        if ($driver === 'local') {
            // Gated local (FLUXFILES_LOCAL_PRIVATE) serves through tokened /stream → not stable.
            if (($config['private'] ?? false) === true || ($config['private'] ?? '') === 'true') {
                return null;
            }
            return rtrim((string) ($config['url'] ?? '/storage/uploads'), '/') . '/' . ltrim($key, '/');
        }
        if ($driver === 's3') {
            if (($config['visibility'] ?? 'private') !== 'public') {
                return null; // private bucket → presigned URLs expire
            }
            $base = $config['public_url'] ?? '';
            if ($base === '' && !empty($config['bucket'])) {
                $endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
                $base = $endpoint !== '' ? $endpoint . '/' . $config['bucket'] : '';
            }
            return $base !== '' ? rtrim((string) $base, '/') . '/' . ltrim($key, '/') : null;
        }
        return null; // sftp + anything else → no stable URL
    }

    /** True when this attachment is backed by a FluxFiles file. */
    public static function isFluxFiles(int $postId): bool
    {
        return get_post_meta($postId, self::META_URL, true) !== '';
    }

    /** Disk + key an attachment points at, or null if it isn't a FluxFiles attachment. */
    public static function locate(int $postId): ?array
    {
        $key = get_post_meta($postId, self::META_KEY, true);
        if (!is_string($key) || $key === '') {
            return null;
        }
        return ['disk' => (string) (get_post_meta($postId, self::META_DISK, true) ?: 'local'), 'key' => $key];
    }

    /**
     * A FluxFiles-backed attachment was deleted in WP. By default the storage file is
     * LEFT in place (safe). Set the `fluxfiles_delete_storage` option / filter to also
     * remove it from your bucket. Never throws (a storage hiccup must not block WP).
     */
    public static function onDelete($postId): void
    {
        $loc = self::locate((int) $postId);
        if ($loc === null) {
            return; // not ours → nothing to do
        }
        $delete = apply_filters('fluxfiles_delete_storage', get_option('fluxfiles_delete_storage', '0') === '1', (int) $postId);
        if (!$delete) {
            return;
        }
        try {
            $configs = FluxFilesPlugin::diskConfigs();
            if (!isset($configs[$loc['disk']])) {
                return;
            }
            $fs = (new \FluxFiles\DiskManager($configs))->disk($loc['disk']);
            if ($fs->fileExists($loc['key'])) {
                $fs->delete($loc['key']);
            }
        } catch (\Throwable) {
            // best-effort — the WP record is already gone; leave the file if we can't reach storage
        }
    }
}
