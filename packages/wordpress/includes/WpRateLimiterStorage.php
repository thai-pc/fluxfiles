<?php

defined('ABSPATH') || exit;

use FluxFiles\ApiException;
use FluxFiles\RateLimiterStorageInterface;

/**
 * Sliding-window rate limiter over `$wpdb` — the db-mode twin of core's
 * Db\RateLimiterDbStorage (same hits table shape, same semantics).
 *
 * Core's class needs a PDO connection and an unprefixed `rate_limits` table;
 * WordPress only hands out `$wpdb` and keeps every plugin table under
 * `$wpdb->prefix`, so this adapter implements the same RateLimiterStorageInterface
 * on `{prefix}fluxfiles_rate_limits`. In json mode the plugin uses
 * RateLimiterFactory::makeFile() instead — this class exists only so the limiter
 * lives in the database (not on disk) when fluxfiles_storage_backend = 'db'.
 */
class WpRateLimiterStorage implements RateLimiterStorageInterface
{
    private $wpdb;
    private string $table;
    private int $readLimit;
    private int $writeLimit;
    private int $windowSeconds;
    private ?RateLimiterStorageInterface $fallback;
    private static bool $healTried = false;
    private static int $lastLogged = 0;

    /**
     * @param ?RateLimiterStorageInterface $fallback Used when the table can't be
     *        used even after a self-heal attempt (so a missing table never turns
     *        every API call into a 500). Null = fail closed with a 500.
     */
    public function __construct(int $readLimit, int $writeLimit, int $windowSeconds = 60, ?RateLimiterStorageInterface $fallback = null)
    {
        $this->fallback = $fallback;
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'fluxfiles_rate_limits';
        $this->readLimit = $readLimit;
        $this->writeLimit = $writeLimit;
        $this->windowSeconds = $windowSeconds;
    }

    public function check(string $identifier, string $actionType): void
    {
        $r = $this->attempt($identifier, $actionType);
        if ($r === null && !self::$healTried && class_exists('FluxFilesDbSchema')) {
            // The table is gone although the schema version says it exists
            // (dropped, restored from a partial backup): recreate it once and retry.
            self::$healTried = true;
            try {
                \FluxFilesDbSchema::install();
            } catch (\Throwable $e) {
                // fall through to the fallback below
            }
            $r = $this->attempt($identifier, $actionType);
        }
        if ($r === null) {
            if ($this->fallback === null) {
                throw new ApiException('Rate limiter unavailable', 500);
            }
            if (time() - self::$lastLogged >= 60) {
                self::$lastLogged = time();
                error_log('FluxFiles: DB rate limiter unavailable (' . $this->table . '), using the file limiter: ' . $this->wpdb->last_error);
            }
            $this->fallback->check($identifier, $actionType);
            return;
        }
        if ($r === true) {
            if (!headers_sent()) {
                header('Retry-After: ' . $this->windowSeconds);
            }
            throw new ApiException('Too many requests. Please try again later.', 429, 'rate_limited');
        }
        // 1%-probabilistic global sweep, best-effort.
        if (random_int(1, 100) === 1) {
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->table} WHERE ts < %d", time() - $this->windowSeconds));
        }
    }

    /** @return ?bool true = over the limit, false = counted, null = the table could not be used */
    private function attempt(string $identifier, string $actionType): ?bool
    {
        $limit = $actionType === 'read' ? $this->readLimit : $this->writeLimit;
        $now = time();
        $windowStart = $now - $this->windowSeconds;
        $db = $this->wpdb;

        $db->query('START TRANSACTION');
        $limited = false;
        $ok = $db->query($db->prepare(
            "DELETE FROM {$this->table} WHERE identifier = %s AND bucket = %s AND ts <= %d",
            $identifier,
            $actionType,
            $windowStart
        )) !== false;
        if ($ok) {
            // FOR UPDATE: two concurrent requests must not both read "limit - 1" and both insert.
            $count = $db->get_var($db->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE identifier = %s AND bucket = %s FOR UPDATE",
                $identifier,
                $actionType
            ));
            $ok = $count !== null;
            if ($ok) {
                if ((int) $count >= $limit) {
                    $limited = true;
                } else {
                    $ok = $db->query($db->prepare(
                        "INSERT INTO {$this->table} (identifier, bucket, ts) VALUES (%s, %s, %d)",
                        $identifier,
                        $actionType,
                        $now
                    )) !== false;
                }
            }
        }
        if (!$ok) {
            $db->query('ROLLBACK');
            return null;
        }
        $db->query('COMMIT');
        return $limited;
    }
}
