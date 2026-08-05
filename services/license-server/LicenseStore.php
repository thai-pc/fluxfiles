<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;

/**
 * The vendor customer/license DB (SQLite). This is the stateful back-office — kept
 * OUTSIDE the stateless FluxFiles core. One row per issued license key.
 */
final class LicenseStore
{
    private PDO $db;

    public function __construct(?string $path = null)
    {
        $path = $path ?? (getenv('FLUXFILES_LICENSE_DB') ?: __DIR__ . '/data/licenses.sqlite');
        if ($path !== ':memory:') {
            @mkdir(dirname($path), 0700, true);
        }
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS licenses (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            jti         TEXT UNIQUE NOT NULL,
            email       TEXT NOT NULL,
            customer    TEXT,
            plan        TEXT,
            edition     TEXT,
            modules     TEXT,
            sites       INTEGER DEFAULT 0,
            enforcement TEXT,
            issued      INTEGER,
            expires     INTEGER,
            license_key TEXT NOT NULL,
            gateway     TEXT,
            order_id    TEXT,
            status      TEXT DEFAULT "active",
            created_at  INTEGER
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_email ON licenses(email)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_order ON licenses(gateway, order_id)');

        // When the key was actually delivered to the buyer. NULL means "issued but the
        // buyer has not been told", which is the state a mail outage leaves behind and
        // the reason the webhook can retry delivery without minting a second licence.
        // Added after the first deploys, hence the guarded ALTER rather than a column
        // in the CREATE above.
        $cols = $this->db->query('PRAGMA table_info(licenses)')->fetchAll(\PDO::FETCH_COLUMN, 1) ?: [];
        if (!in_array('mailed_at', $cols, true)) {
            $this->db->exec('ALTER TABLE licenses ADD COLUMN mailed_at INTEGER');
        }
    }

    /** Mark the licence as delivered. Returns false when the row is gone. */
    public function markMailed(string $jti, ?int $at = null): bool
    {
        $s = $this->db->prepare('UPDATE licenses SET mailed_at = ? WHERE jti = ?');
        $s->execute([$at ?? time(), $jti]);
        return $s->rowCount() > 0;
    }

    /**
     * Record an issued license. Idempotent on (gateway, order_id): a repeated webhook
     * for the same order returns the existing row instead of minting a duplicate.
     *
     * @param array<string,mixed> $rec
     * @return array<string,mixed> the stored row
     */
    public function record(array $rec): array
    {
        if (!empty($rec['gateway']) && !empty($rec['order_id'])) {
            $existing = $this->findByOrder((string) $rec['gateway'], (string) $rec['order_id']);
            if ($existing !== null) {
                return $existing;
            }
        }
        $stmt = $this->db->prepare('INSERT INTO licenses
            (jti,email,customer,plan,edition,modules,sites,enforcement,issued,expires,license_key,gateway,order_id,status,created_at)
            VALUES (:jti,:email,:customer,:plan,:edition,:modules,:sites,:enforcement,:issued,:expires,:license_key,:gateway,:order_id,:status,:created_at)');
        $row = [
            'jti'         => (string) $rec['jti'],
            'email'       => (string) $rec['email'],
            'customer'    => (string) ($rec['customer'] ?? ''),
            'plan'        => (string) ($rec['plan'] ?? ''),
            'edition'     => (string) ($rec['edition'] ?? ''),
            'modules'     => implode(',', (array) ($rec['modules'] ?? [])),
            'sites'       => (int) ($rec['sites'] ?? 0),
            'enforcement' => (string) ($rec['enforcement'] ?? 'perpetual'),
            'issued'      => (int) ($rec['issued'] ?? time()),
            'expires'     => isset($rec['expires']) ? (int) $rec['expires'] : null,
            'license_key' => (string) $rec['license_key'],
            'gateway'     => (string) ($rec['gateway'] ?? 'manual'),
            'order_id'    => (string) ($rec['order_id'] ?? ''),
            'status'      => (string) ($rec['status'] ?? 'active'),
            'created_at'  => time(),
        ];
        $stmt->execute($row);
        return $this->findByJti($row['jti']) ?? $row;
    }

    /** @return array<string,mixed>|null */
    public function findByJti(string $jti): ?array
    {
        $s = $this->db->prepare('SELECT * FROM licenses WHERE jti = ?');
        $s->execute([$jti]);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public function findByOrder(string $gateway, string $orderId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM licenses WHERE gateway = ? AND order_id = ?');
        $s->execute([$gateway, $orderId]);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    /** @return array<int,array<string,mixed>> */
    public function findByEmail(string $email): array
    {
        $s = $this->db->prepare('SELECT * FROM licenses WHERE email = ? ORDER BY created_at DESC');
        $s->execute([$email]);
        return $s->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function all(int $limit = 200, int $offset = 0): array
    {
        $s = $this->db->prepare('SELECT * FROM licenses ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->bindValue(2, $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Licences that were issued but never reached the buyer — the backlog a mail
     * outage leaves behind. Revoked/refunded rows are excluded: re-sending a key to
     * someone whose order was reversed is worse than not sending it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function undelivered(int $limit = 100): array
    {
        $s = $this->db->prepare(
            'SELECT * FROM licenses WHERE mailed_at IS NULL AND status = "active"
             ORDER BY created_at ASC LIMIT ?'
        );
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /** Mark a license revoked/refunded (status only — offline verify can't enforce it;
     *  it gates the UPDATE channel + is your record of truth). */
    public function setStatus(string $jti, string $status): bool
    {
        $s = $this->db->prepare('UPDATE licenses SET status = ? WHERE jti = ?');
        $s->execute([$status, $jti]);
        return $s->rowCount() > 0;
    }
}
