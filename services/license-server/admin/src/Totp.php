<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

final class Totp
{
    public static function secret(): string
    {
        $bits = '';
        foreach (unpack('C*', random_bytes(20)) as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $v) {
            $out .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec($v)];
        }
        return $out;
    }

    public static function code(string $secret, int $step, int $digits = 6): string
    {
        if (!preg_match('/^[A-Z2-7]{16,128}$/D', $secret) || $step < 0) {
            return '';
        }
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(
                decbin((int) strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c)),
                5,
                '0',
                STR_PAD_LEFT,
            );
        }
        $key = '';
        foreach (str_split($bits, 8) as $v) {
            if (strlen($v) === 8) {
                $key .= chr(bindec($v));
            }
        }
        $h = hash_hmac(
            'sha1',
            pack('N2', intdiv($step, 4294967296), $step % 4294967296),
            $key,
            true,
        );
        $offset = ord($h[19]) & 15;
        $n = unpack('N', substr($h, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($n % 10 ** $digits), $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $now, int $lastStep = -1): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }
        $step = intdiv($now, 30);
        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if ($candidate > $lastStep && hash_equals(self::code($secret, $candidate), $code)) {
                return $candidate;
            }
        }
        return null;
    }
}
