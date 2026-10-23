<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * The environment handed to every external tool FluxFiles runs (Ghostscript,
 * ffmpeg, ssh, …). proc_open() with a null $env passes the WHOLE parent
 * environment through — and in a container or 12-factor deploy that is where
 * FLUXFILES_SECRET, cloud keys and database passwords live. Several of those
 * tools parse untrusted uploads, so one exploited parser would read every secret.
 * They get only what they need to run instead.
 */
final class ProcessEnv
{
    /** @return array<string,string> */
    public static function minimal(): array
    {
        $tmp = sys_get_temp_dir();
        $path = getenv('PATH');
        $home = getenv('HOME');
        return [
            'PATH'   => is_string($path) && $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin',
            // ssh reads ~/.ssh from HOME; the others only need somewhere writable.
            'HOME'   => is_string($home) && $home !== '' ? $home : $tmp,
            'TMPDIR' => $tmp,
            'LANG'   => 'C.UTF-8',
        ];
    }
}
