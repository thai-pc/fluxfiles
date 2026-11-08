<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;

/** Additive review fixes: retain refund increments without rewriting buyer keys. */
final class ReviewSchema
{
    public static function migrate(PDO $db): void
    {
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() >= 4) {
            return;
        }
        $db->exec('BEGIN IMMEDIATE');
        try {
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() < 4) {
                $db->exec(
                    <<<'SQL'
                    CREATE TABLE IF NOT EXISTS order_refunds (
                     order_id TEXT NOT NULL, total_amount INTEGER NOT NULL, total_tax INTEGER NOT NULL,
                     amount INTEGER NOT NULL, tax_amount INTEGER NOT NULL, at INTEGER NOT NULL,
                     PRIMARY KEY(order_id,total_amount,total_tax)) WITHOUT ROWID;
                    INSERT OR IGNORE INTO order_refunds
                     SELECT order_id,refunded_amount,refunded_tax_amount,refunded_amount,refunded_tax_amount,refunded_at
                     FROM orders WHERE refunded_at IS NOT NULL AND refunded_amount>0;
                    CREATE INDEX IF NOT EXISTS idx_refund_at ON order_refunds(at);
                    CREATE INDEX IF NOT EXISTS idx_webhook_identity ON webhook_events(source,webhook_id,verify);
                    PRAGMA user_version=4;
                    SQL
                    ,
                );
                $db->prepare(
                    "INSERT OR IGNORE INTO ops_state(key,value,updated_at) VALUES('telemetry_migration',?,?)",
                )->execute([json_encode(['at' => time()]), time()]);
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}
