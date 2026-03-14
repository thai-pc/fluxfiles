<?php

declare(strict_types=1);

namespace FluxFiles;

use PDO;

class AuditLog
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->migrate();
    }

    public function log(
        string $userId,
        string $action,
        string $disk,
        string $fileKey,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO fm_audit (user_id, action, disk, file_key, ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $disk,
            $fileKey,
            $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            time(),
        ]);
    }

    public function list(int $limit = 100, int $offset = 0, ?string $userId = null): array
    {
        $sql = 'SELECT * FROM fm_audit';
        $params = [];

        if ($userId !== null) {
            $sql .= ' WHERE user_id = ?';
            $params[] = $userId;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function migrate(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS fm_audit (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     TEXT NOT NULL,
                action      TEXT NOT NULL,
                disk        TEXT NOT NULL,
                file_key    TEXT NOT NULL,
                ip          TEXT DEFAULT NULL,
                user_agent  TEXT DEFAULT NULL,
                created_at  INTEGER NOT NULL
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fm_audit_user ON fm_audit(user_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fm_audit_time ON fm_audit(created_at)');
    }
}
