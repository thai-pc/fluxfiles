<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Client;

final class Mask
{
    public const KEY = '/[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/';
    public const EMAIL = '/[A-Z0-9.!#$%&\x27+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    public static function email(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '***' : mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }

    public static function id(string $id): string
    {
        return strlen($id) <= 14 ? $id : substr($id, 0, 6) . '…' . substr($id, -6);
    }

    public static function clean(string $text): string
    {
        $text = (string) preg_replace(self::KEY, '[redacted]', $text);
        $text = (string) preg_replace('/bot[0-9]+:[A-Za-z0-9_-]+/', 'bot[redacted]', $text);
        return (string) preg_replace_callback(
            self::EMAIL,
            static fn(array $m): string => self::email($m[0]),
            $text,
        );
    }

    public static function safe(string $text, bool $emails = false): string
    {
        // The raw-address pattern excludes stars, so masked addresses do not match.
        // Do not remove masked substrings first: that could hide an adjacent raw address.
        if (preg_match(self::KEY, $text) || ($emails && preg_match(self::EMAIL, $text))) {
            throw new \InvalidArgumentException('masking');
        }
        return $text;
    }

    public static function row(array $row): array
    {
        if (isset($row['license_key'])) {
            $row['fingerprint'] = substr(hash('sha256', (string) $row['license_key']), 0, 10);
            unset($row['license_key']);
        }
        foreach ($row as $k => $v) {
            if (is_string($v)) {
                $row[$k] = (string) preg_replace(self::KEY, '[redacted]', $v);
            } elseif (is_array($v)) {
                $row[$k] = self::row($v);
            }
        }
        return $row;
    }
}
