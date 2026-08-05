<?php

defined('ABSPATH') || exit;

/**
 * Plugin updates in wp-admin, served by the FluxFiles update server.
 *
 * The plugin ships as a zip (scripts/build-wordpress.sh), not through
 * wordpress.org, so without this a site has no way to learn that a new version
 * exists — including a security release. Every other install path has one
 * (`fluxfiles update` for Laravel/standalone); WordPress had none.
 *
 * How it works, and why each piece is there:
 *
 *   - The server returns a **signed** manifest {module, version, url, sha256}, verified
 *     here with the same `UpdateClient` and embedded release public key the CLI uses.
 *     An unsigned or tampered manifest is discarded, so nobody who merely controls the
 *     network can point a site at their own zip.
 *   - WordPress performs the download itself, so it does NOT re-check the sha256 the
 *     manifest carries. The protection that matters is therefore the signature on the
 *     URL, not the checksum — an attacker cannot substitute a URL without the release
 *     private key.
 *   - Results are cached in a transient. This runs on every admin page load, and a
 *     wp-admin that pauses for a network round trip is a worse bug than a stale
 *     version number.
 */
class FluxFilesUpdater
{
    private const TRANSIENT = 'fluxfiles_update_manifest';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const MODULE = 'wordpress';

    public static function boot(): void
    {
        // No endpoint configured → the site simply never checks. Deliberately silent:
        // a self-hosted install with no update server is a supported setup, not an
        // error to nag about.
        if (self::endpoint() === '') {
            return;
        }
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject']);
        add_filter('plugins_api', [self::class, 'info'], 20, 3);
        add_action('upgrader_process_complete', [self::class, 'flush'], 10, 0);
    }

    /** Where to ask. Filterable so a site can point at its own mirror. */
    private static function endpoint(): string
    {
        $url = (string) (getenv('FLUXFILES_UPDATE_URL') ?: get_option('fluxfiles_update_url', ''));
        return (string) apply_filters('fluxfiles_update_url', $url);
    }

    private static function slug(): string
    {
        return plugin_basename(FLUXFILES_PLUGIN_FILE);   // e.g. fluxfiles/fluxfiles.php
    }

    /**
     * Fetch + verify the manifest, or null. Cached either way: a server that is down
     * must not mean a request on every single admin page view.
     *
     * @return array{version:string,url:string,sha256:string}|null
     */
    private static function manifest(bool $force = false): ?array
    {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT);
            if ($cached !== false) {
                return is_array($cached) ? $cached : null;   // '' caches a known failure
            }
        }

        $url = add_query_arg(
            ['module' => self::MODULE, 'current' => FLUXFILES_VERSION,
             'license' => FluxFilesPlugin::licenseKey()],
            self::endpoint()
        );
        $res = wp_remote_get($url, ['timeout' => 8, 'headers' => ['Accept' => 'text/plain']]);

        $manifest = null;
        if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
            $token = trim((string) wp_remote_retrieve_body($res));
            if ($token !== '' && class_exists('\\FluxFiles\\UpdateClient')) {
                $payload = (new \FluxFiles\UpdateClient())->verifyManifest($token);
                // Check the manifest is for THIS artifact: a valid signature over some
                // other module's release would otherwise install the wrong zip.
                if (is_array($payload) && ($payload['module'] ?? '') === self::MODULE) {
                    $manifest = [
                        'version' => (string) ($payload['version'] ?? ''),
                        'url' => (string) ($payload['url'] ?? ''),
                        'sha256' => (string) ($payload['sha256'] ?? ''),
                    ];
                }
            }
        }

        set_transient(self::TRANSIENT, $manifest ?? '', self::CACHE_TTL);
        return $manifest;
    }

    /**
     * Tell WordPress an update exists.
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function inject($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $m = self::manifest();
        if ($m === null || $m['version'] === '' || $m['url'] === '') {
            return $transient;
        }
        if (version_compare(ltrim($m['version'], 'vV'), FLUXFILES_VERSION, '<=')) {
            return $transient;      // nothing newer
        }

        $slug = self::slug();
        $transient->response[$slug] = (object) [
            'slug' => dirname($slug),
            'plugin' => $slug,
            'new_version' => $m['version'],
            'package' => $m['url'],
            'url' => 'https://github.com/thai-pc/fluxfiles',
            'tested' => get_bloginfo('version'),
            'requires_php' => '8.1',
        ];
        return $transient;
    }

    /**
     * Fill the "View details" modal, so the update notice does not lead to a WordPress
     * error page — plugins_api otherwise asks wordpress.org, which has never heard of
     * this plugin.
     *
     * @param mixed  $result
     * @param string $action
     * @param mixed  $args
     * @return mixed
     */
    public static function info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== dirname(self::slug())) {
            return $result;
        }
        $m = self::manifest();
        return (object) [
            'name' => 'FluxFiles',
            'slug' => $args->slug,
            'version' => $m['version'] ?? FLUXFILES_VERSION,
            'requires_php' => '8.1',
            'download_link' => $m['url'] ?? '',
            'sections' => [
                'description' => 'Multi-storage file manager with Local/S3/R2/SFTP support, '
                    . 'image optimization and full-text search.',
            ],
        ];
    }

    /** After any update, re-ask rather than serve a version number that just changed. */
    public static function flush(): void
    {
        delete_transient(self::TRANSIENT);
    }
}
