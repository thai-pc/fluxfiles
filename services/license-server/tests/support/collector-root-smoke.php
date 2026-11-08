<?php

declare(strict_types=1);

// CI-only: generated fixture paths, executed by sudo on the disposable runner.
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Run on a disposable runner as root.\n");
    exit(1);
}
$uid = (int) ($argv[1] ?? 0);
$gid = (int) ($argv[2] ?? 0);
if ($uid <= 0 || $gid <= 0 || !posix_getpwuid($uid)) {
    exit(1);
}
$dir = sys_get_temp_dir() . '/ff-collector-root-' . bin2hex(random_bytes(8));
mkdir($dir, 0755);
mkdir($dir . '/inbox', 0750);
chown($dir . '/inbox', $uid);
chgrp($dir . '/inbox', $gid);
$day = gmdate('Y-m-d', time() - 86400);
$file = $dir . '/inbox/' . $day . '.json';
$log = $dir . '/private.log';
try {
    file_put_contents(
        $log,
        json_encode([
            'uri' => '/dl/m/share-1.2.3.zip?private=secret',
            'status' => 200,
            'time_iso8601' => $day . 'T12:00:00+07:00',
        ]) . "\n",
    );
    chmod($log, 0600);
    $run = static function () use ($day, $log, $file): int {
        $p = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/collect-ops.php',
                '--day=' . $day,
                '--update-log=' . $log,
                '--output=' . $file,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin'],
        );
        foreach ($pipes as $f) {
            stream_get_contents($f);
            fclose($f);
        }
        return proc_close($p);
    };
    if ($run() !== 0) {
        throw new RuntimeException('root collector failed');
    }
    clearstatcache();
    if (
        fileowner($file) !== $uid ||
        (fileperms($file) & 0777) !== 0640 ||
        json_decode(file_get_contents($file), true)['downloads'][0]['n'] !== 1
    ) {
        throw new RuntimeException('privilege drop or mode failed');
    }
    unlink($file);
    symlink($log, $file);
    $before = hash_file('sha256', $log);
    if ($run() !== 1 || hash_file('sha256', $log) !== $before) {
        throw new RuntimeException('symlink output accepted');
    }
    echo "Root collector smoke passed: private input, privilege drop, 0640 output, symlink refusal.\n";
} finally {
    if (is_link($file) || is_file($file)) {
        unlink($file);
    }
    unlink($log);
    rmdir($dir . '/inbox');
    rmdir($dir);
}
