<?php

declare(strict_types=1);

use FluxFiles\LicenseServer\Admin\Auth;
use FluxFiles\LicenseServer\Admin\Totp;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require_once __DIR__ . '/../src/Auth.php';

try {
    $action = $argv[1] ?? '';
    $name = $argv[2] ?? '';
    if (
        !in_array(
            $action,
            ['add', 'passwd', 'totp-reset', 'disable', 'logout-all', 'role'],
            true,
        ) ||
        !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)
    ) {
        throw new RuntimeException(
            'Usage: admin-user.php add|passwd|totp-reset|disable|logout-all|role NAME [admin|viewer]',
        );
    }
    if ($action === 'role' && !isset($argv[3])) {
        throw new RuntimeException('Provide admin or viewer');
    }
    $role = $argv[3] ?? 'admin';
    if (!in_array($role, ['admin', 'viewer'], true)) {
        throw new RuntimeException('Role must be admin or viewer');
    }
    $auth = new Auth();
    $db = $auth->db;
    $q = $db->prepare('SELECT id FROM users WHERE name=?');
    $q->execute([$name]);
    $id = $q->fetchColumn();
    if ($action === 'add' && $id !== false) {
        throw new RuntimeException('User exists');
    }
    if ($action !== 'add' && $id === false) {
        throw new RuntimeException('User not found');
    }
    $password = '';
    $secret = '';
    if (in_array($action, ['add', 'passwd'], true)) {
        fwrite(STDERR, 'Password (at least 12 characters; stdin, never an argument): ');
        $tty = function_exists('stream_isatty') && stream_isatty(STDIN);
        $terminalEcho = static function (bool $enabled): void {
            $binary = is_executable('/bin/stty') ? '/bin/stty' : '/usr/bin/stty';
            $process = proc_open(
                [$binary, $enabled ? 'echo' : '-echo'],
                [0 => STDIN, 1 => STDERR, 2 => STDERR],
                $pipes,
                null,
                ['PATH' => '/usr/bin:/bin', 'LANG' => 'C'],
            );
            if (!is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('Cannot change terminal echo');
            }
        };
        if ($tty) {
            $terminalEcho(false);
        }
        try {
            $password = rtrim((string) fgets(STDIN), "\r\n");
        } finally {
            if ($tty) {
                $terminalEcho(true);
                fwrite(STDERR, "\n");
            }
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Password too short');
        }
    }
    if (in_array($action, ['add', 'totp-reset'], true)) {
        $secret = Totp::secret();
    }
    $db->beginTransaction();
    if ($action === 'add') {
        $db->prepare(
            'INSERT INTO users(name,pass_hash,totp_secret,created_at,role) VALUES(?,?,?,?,?)',
        )->execute([$name, Auth::hashPassword($password), $secret, time(), $role]);
    } elseif ($action === 'passwd') {
        $db->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([
            Auth::hashPassword($password),
            $id,
        ]);
    } elseif ($action === 'totp-reset') {
        $db->prepare('UPDATE users SET totp_secret=?,totp_last_step=-1 WHERE id=?')->execute([
            $secret,
            $id,
        ]);
    } elseif ($action === 'role') {
        $db->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $id]);
    } elseif ($action === 'disable') {
        $db->prepare('UPDATE users SET disabled=1 WHERE id=?')->execute([$id]);
    }
    if ($id !== false) {
        $db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$id]);
    }
    $db->commit();
    if ($secret !== '') {
        $label =
            'FluxFiles' . (\FluxFiles\LicenseServer\Client\Config::sandbox() ? ' SANDBOX' : '');
        echo 'otpauth://totp/' .
            rawurlencode($label . ':' . $name) .
            '?secret=' .
            $secret .
            '&issuer=' .
            rawurlencode($label) .
            "&algorithm=SHA1&digits=6&period=30\n";
    }
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
