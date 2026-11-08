<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

use PDO;
use FluxFiles\LicenseServer\Client\Config;

require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/../../client/Config.php';

final class Auth
{
    public PDO $db;
    public ?array $session = null;
    private const DUMMY_ARGON = '$argon2id$v=19$m=65536,t=4,p=1$eVJ4c0U2UUk2bTlQcTlHQw$JSJ2S1shSCyrC04MWrfhSAk5EDhbcSNVWn8fp7hGOPc';
    private const DUMMY_BCRYPT = '$2y$12$61kJbLZkZy.K/43ULf2c9.5KZN5TQeYNQ3C1B5MEYn9iAudsxN2nO';

    public function __construct(?string $path = null)
    {
        $path ??= Config::get(
            'FLUXFILES_ADMIN_DB',
            '/var/lib/fluxfiles-license' .
                (Config::sandbox() ? '-sbx' : '') .
                '-admin/admin.sqlite',
        );
        $mask = umask(0077);
        try {
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            $this->db = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=2000');
            $this
                ->db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,name TEXT UNIQUE NOT NULL,pass_hash TEXT NOT NULL,totp_secret TEXT NOT NULL,totp_last_step INTEGER NOT NULL DEFAULT -1,disabled INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS sessions(id_hash TEXT PRIMARY KEY,user_id INTEGER,created_at INTEGER NOT NULL,last_seen INTEGER NOT NULL,stepup_at INTEGER,csrf TEXT NOT NULL,lang TEXT NOT NULL,theme TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS login_hits(bucket TEXT NOT NULL,win INTEGER NOT NULL,n INTEGER NOT NULL,locked_until INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(bucket,win));');
            $columns = $this->db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('role', $columns, true)) {
                $this->db->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'admin'");
            }
        } finally {
            umask($mask);
        }
    }

    public static function hashPassword(string $password): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID)
            : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function now(): int
    {
        // Only the self-booting development server can move the clock.
        if (
            PHP_SAPI === 'cli-server' &&
            Config::get('FLUXFILES_ADMIN_TEST_CLOCK') === '1' &&
            ctype_digit((string) ($_SERVER['HTTP_X_FLUXFILES_TEST_TIME'] ?? ''))
        ) {
            return (int) $_SERVER['HTTP_X_FLUXFILES_TEST_TIME'];
        }
        return time();
    }

    public function load(): void
    {
        $cookie = (string) ($_COOKIE[Config::sandbox() ? 'fxadm_sbx' : 'fxadm'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $cookie)) {
            return;
        }
        $s = $this->db->prepare(
            'SELECT s.*,u.name,u.disabled,u.role FROM sessions s LEFT JOIN users u ON u.id=s.user_id WHERE s.id_hash=?',
        );
        $s->execute([hash('sha256', $cookie)]);
        $r = $s->fetch();
        $now = self::now();
        if (!$r) {
            return;
        }
        if (
            (int) $r['last_seen'] <
                $now - max(1, (int) Config::get('FLUXFILES_ADMIN_SESSION_IDLE', '1800')) ||
            (int) $r['created_at'] <
                $now - max(1, (int) Config::get('FLUXFILES_ADMIN_SESSION_ABSOLUTE', '43200')) ||
            $r['disabled'] ||
            ($r['user_id'] !== null && $r['name'] === null)
        ) {
            $this->db->prepare('DELETE FROM sessions WHERE id_hash=?')->execute([$r['id_hash']]);
            return;
        }
        $this->session = $r;
        $this->db
            ->prepare('UPDATE sessions SET last_seen=? WHERE id_hash=?')
            ->execute([$now, $r['id_hash']]);
    }

    public function create(?int $user = null, ?int $stepup = null): void
    {
        $lang = $this->session['lang'] ?? Config::get('FLUXFILES_ADMIN_LANG', 'vi');
        $theme = $this->session['theme'] ?? 'auto';
        if ($this->session) {
            $this->db
                ->prepare('DELETE FROM sessions WHERE id_hash=?')
                ->execute([$this->session['id_hash']]);
        }
        $id = bin2hex(random_bytes(32));
        $hash = hash('sha256', $id);
        $now = self::now();
        $this->db
            ->prepare('INSERT INTO sessions VALUES(?,?,?,?,?,?,?,?)')
            ->execute([
                $hash,
                $user,
                $now,
                $now,
                $stepup,
                bin2hex(random_bytes(32)),
                $lang,
                $theme,
            ]);
        setcookie(Config::sandbox() ? 'fxadm_sbx' : 'fxadm', $id, [
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[Config::sandbox() ? 'fxadm_sbx' : 'fxadm'] = $id;
        $this->load();
        $this->db
            ->prepare('DELETE FROM sessions WHERE last_seen<? OR created_at<?')
            ->execute([
                $now - max(1, (int) Config::get('FLUXFILES_ADMIN_SESSION_IDLE', '1800')),
                $now - max(1, (int) Config::get('FLUXFILES_ADMIN_SESSION_ABSOLUTE', '43200')),
            ]);
    }

    public function login(string $name, string $password, string $code): bool
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $now = self::now();
            $bucket = 'user:' . hash('sha256', strtolower($name));
            $q = $this->db->prepare('SELECT * FROM users WHERE name=?');
            $q->execute([$name]);
            $u = $q->fetch();
            $valid = password_verify(
                $password,
                $u['pass_hash'] ??
                    (defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON : self::DUMMY_BCRYPT),
            );
            $hit = $this->db->prepare(
                'SELECT MAX(locked_until) FROM login_hits WHERE bucket IN (?,?)',
            );
            $hit->execute([$bucket, 'global']);
            $locked = (int) $hit->fetchColumn() > $now;
            $step = $u
                ? Totp::verify($u['totp_secret'], $code, $now, (int) $u['totp_last_step'])
                : null;
            if (!$locked && $valid && $u && !$u['disabled'] && $step !== null) {
                $this->db
                    ->prepare('UPDATE users SET totp_last_step=? WHERE id=?')
                    ->execute([$step, $u['id']]);
                $this->db->prepare('DELETE FROM login_hits WHERE bucket=?')->execute([$bucket]);
                $this->db->exec('COMMIT');
                $this->create((int) $u['id']);
                return true;
            }
            foreach ([[$bucket, 900, 5], ['global', 3600, 20]] as [$key, $period, $max]) {
                $this->failure($key, $period, $max, $now);
            }
            $this->db
                ->prepare('DELETE FROM login_hits WHERE win<? AND locked_until<?')
                ->execute([$now - 7200, $now]);
            $this->db->exec('COMMIT');
            return false;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    private function failure(string $bucket, int $period, int $max, int $now): void
    {
        $this->db
            ->prepare(
                'INSERT INTO login_hits(bucket,win,n,locked_until) VALUES(?,?,1,0) ON CONFLICT(bucket,win) DO UPDATE SET n=n+1',
            )
            ->execute([$bucket, $now]);
        $s = $this->db->prepare('SELECT SUM(n) FROM login_hits WHERE bucket=? AND win>?');
        $s->execute([$bucket, $now - $period]);
        if ((int) $s->fetchColumn() >= $max) {
            $this->db
                ->prepare('UPDATE login_hits SET locked_until=? WHERE bucket=? AND win=?')
                ->execute([$now + 900, $bucket, $now]);
        }
    }

    public function stepup(string $code): bool
    {
        if (!$this->session || !$this->session['user_id']) {
            return false;
        }
        if ((int) $this->session['stepup_at'] >= self::now() - 600) {
            return true;
        }
        // Opening an action while step-up is stale is a prompt, not a guessed code.
        if ($code === '') {
            return false;
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $s = $this->db->prepare('SELECT * FROM users WHERE id=? AND disabled=0');
            $s->execute([$this->session['user_id']]);
            $u = $s->fetch();
            $bucket = 'stepup:' . $this->session['user_id'];
            $hits = $this->db->prepare('SELECT MAX(locked_until) FROM login_hits WHERE bucket=?');
            $hits->execute([$bucket]);
            $locked = (int) $hits->fetchColumn() > self::now();
            $step =
                $u && !$locked
                    ? Totp::verify(
                        $u['totp_secret'],
                        $code,
                        self::now(),
                        (int) $u['totp_last_step'],
                    )
                    : null;
            if ($step === null) {
                $this->failure($bucket, 900, 5, self::now());
                $this->db->exec('COMMIT');
                return false;
            }
            $this->db
                ->prepare('UPDATE users SET totp_last_step=? WHERE id=?')
                ->execute([$step, $u['id']]);
            $this->db
                ->prepare('UPDATE sessions SET stepup_at=? WHERE id_hash=?')
                ->execute([self::now(), $this->session['id_hash']]);
            $this->db->exec('COMMIT');
            return true;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function logout(): void
    {
        if ($this->session) {
            $this->db
                ->prepare('DELETE FROM sessions WHERE id_hash=?')
                ->execute([$this->session['id_hash']]);
        }
        setcookie(Config::sandbox() ? 'fxadm_sbx' : 'fxadm', '', [
            'expires' => 1,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $this->session = null;
    }

    public function preference(string $key, string $value): void
    {
        if (!in_array($value, $key === 'lang' ? ['vi', 'en'] : ['auto', 'light', 'dark'], true)) {
            throw new \InvalidArgumentException('invalid_input');
        }
        $this->db
            ->prepare("UPDATE sessions SET $key=? WHERE id_hash=?")
            ->execute([$value, $this->session['id_hash']]);
    }
}
