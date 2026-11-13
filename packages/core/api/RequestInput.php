<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Validated reads of request-controlled server variables.
 *
 * Core is WordPress-independent, so this validates with plain PHP: type-check,
 * then a format check (IP) or a control-character strip + length cap (UA).
 */
final class RequestInput
{
    private const USER_AGENT_MAX = 512;

    /** The peer address, or '' when absent / not a valid IPv4/IPv6 literal. */
    public static function remoteIp(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- framework-agnostic core: validated by ip() below (FILTER_VALIDATE_IP, else '')
        return self::ip($raw);
    }

    /** The User-Agent header, control characters stripped and capped. */
    public static function userAgent(): string
    {
        $raw = $_SERVER['HTTP_USER_AGENT'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- framework-agnostic core: sanitized by cleanUserAgent() below (string only, control chars stripped, 512-byte cap)
        return self::cleanUserAgent($raw);
    }

    /** @param mixed $value */
    public static function ip($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : '';
    }

    /** @param mixed $value */
    public static function cleanUserAgent($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        return substr(trim($value), 0, self::USER_AGENT_MAX);
    }

    /** A scalar-string query value, or null when absent or an array. */
    public static function queryString(string $key): ?string
    {
        $v = $_GET[$key] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- read-only display option (e.g. ?lang); string-only here, callers allow-list the value
        return is_string($v) ? $v : null;
    }
}
