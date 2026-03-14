<?php

declare(strict_types=1);

namespace FluxFiles;

use PDO;

class RateLimiter
{
    private PDO $db;
    private int $readLimit;
    private int $writeLimit;
    private int $windowSeconds;

    public function __construct(PDO $db, int $readLimit = 60, int $writeLimit = 10, int $windowSeconds = 60)
    {
        $this->db = $db;
        $this->readLimit = $readLimit;
        $this->writeLimit = $writeLimit;
        $this->windowSeconds = $windowSeconds;
        $this->migrate();
    }

    public function check(string $userId, string $actionType): void
    {
        $limit = $actionType === 'read' ? $this->readLimit : $this->writeLimit;
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        // Clean old entries
        $this->db->prepare('DELETE FROM rate_limits WHERE expires_at < ?')->execute([$now]);

        // Count requests in window
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as cnt FROM rate_limits WHERE user_id = ? AND action_type = ? AND created_at > ?'
        );
        $stmt->execute([$userId, $actionType, $windowStart]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $limit) {
            $retryAfter = $this->windowSeconds;
            header("Retry-After: {$retryAfter}");
            throw new ApiException('Too many requests. Please try again later.', 429);
        }

        // Record this request
        $stmt = $this->db->prepare(
            'INSERT INTO rate_limits (user_id, action_type, created_at, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $actionType, $now, $now + $this->windowSeconds]);
    }

    private function migrate(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS rate_limits (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     TEXT NOT NULL,
                action_type TEXT NOT NULL,
                created_at  INTEGER NOT NULL,
                expires_at  INTEGER NOT NULL
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_user ON rate_limits(user_id, action_type, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_expire ON rate_limits(expires_at)');
    }
}
