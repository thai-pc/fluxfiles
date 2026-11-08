<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;

final class AdminSchema
{
    public static function migrate(PDO $db): void
    {
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() >= 2) {
            return;
        }
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Recheck after acquiring the writer lock: two fresh pools can start together.
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() < 2) {
                $cols = $db->query('PRAGMA table_info(licenses)')->fetchAll(PDO::FETCH_COLUMN, 1);
                foreach (['is_test' => 'INTEGER NOT NULL DEFAULT 0', 'archived_at' => 'INTEGER', 'note' => 'TEXT', 'product_id' => 'TEXT', 'first_seen_at' => 'INTEGER', 'last_seen_at' => 'INTEGER'] as $name => $type) {
                    if (!in_array($name, $cols, true)) {
                        $db->exec("ALTER TABLE licenses ADD COLUMN $name $type");
                    }
                }
                $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS license_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT, jti TEXT NOT NULL, at INTEGER NOT NULL, kind TEXT NOT NULL,
 from_status TEXT, to_status TEXT, actor TEXT NOT NULL, ref TEXT);
CREATE INDEX IF NOT EXISTS idx_hist_jti ON license_history(jti, at);
CREATE TABLE IF NOT EXISTS webhook_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL DEFAULT 'polar', webhook_id TEXT,
 received_at INTEGER NOT NULL, last_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 1,
 type TEXT, verify TEXT NOT NULL, outcome TEXT NOT NULL, http_status INTEGER NOT NULL,
 order_id TEXT, checkout_id TEXT, subscription_id TEXT, customer_id TEXT, product_id TEXT, jti TEXT,
 error TEXT, body BLOB, body_expires_at INTEGER, resolved_at INTEGER, resolved_by TEXT);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_hook ON webhook_events(source, webhook_id) WHERE verify = 'ok';
CREATE INDEX IF NOT EXISTS idx_hook_time ON webhook_events(received_at);
CREATE INDEX IF NOT EXISTS idx_hook_order ON webhook_events(order_id);
CREATE TABLE IF NOT EXISTS mail_log (
 id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, kind TEXT NOT NULL, jti TEXT,
 recipient TEXT NOT NULL, transport TEXT NOT NULL, ok INTEGER NOT NULL, error TEXT, provider_id TEXT,
 status TEXT NOT NULL, status_at INTEGER, actor TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_mail_time ON mail_log(at);
CREATE INDEX IF NOT EXISTS idx_mail_jti ON mail_log(jti);
CREATE INDEX IF NOT EXISTS idx_mail_provider ON mail_log(provider_id);
CREATE TABLE IF NOT EXISTS admin_audit (
 id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, actor TEXT NOT NULL, client TEXT NOT NULL,
 action TEXT NOT NULL, target TEXT, result TEXT NOT NULL, reason TEXT, meta TEXT);
CREATE INDEX IF NOT EXISTS idx_audit_time ON admin_audit(at);
CREATE TABLE IF NOT EXISTS orders (
 order_id TEXT PRIMARY KEY, gateway TEXT NOT NULL DEFAULT 'polar', created_at INTEGER, paid_at INTEGER, refunded_at INTEGER,
 status TEXT NOT NULL, customer_id TEXT, email_lower TEXT, product_id TEXT, plan TEXT, billing_reason TEXT, subscription_id TEXT,
 currency TEXT NOT NULL, subtotal_amount INTEGER, discount_amount INTEGER, net_amount INTEGER, tax_amount INTEGER,
 total_amount INTEGER, refunded_amount INTEGER NOT NULL DEFAULT 0, refunded_tax_amount INTEGER NOT NULL DEFAULT 0,
 discount_id TEXT, discount_code TEXT, jti TEXT, updated_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_orders_paid ON orders(paid_at);
CREATE INDEX IF NOT EXISTS idx_orders_sub ON orders(subscription_id);
CREATE INDEX IF NOT EXISTS idx_orders_discount ON orders(discount_code);
CREATE TABLE IF NOT EXISTS install_pings (
 day TEXT NOT NULL, jti TEXT NOT NULL, module TEXT NOT NULL, net TEXT NOT NULL DEFAULT '', version TEXT, core_version TEXT,
 install INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL, n INTEGER NOT NULL, first_at INTEGER NOT NULL, last_at INTEGER NOT NULL,
 PRIMARY KEY(day,jti,module,net)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS ops_outbox (
 id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER NOT NULL, channel TEXT NOT NULL, kind TEXT NOT NULL, severity TEXT NOT NULL,
 dedup_key TEXT NOT NULL, text TEXT NOT NULL, payload TEXT, attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL,
 lease_until INTEGER, sent_at INTEGER, dropped_at INTEGER, last_error TEXT, suppressed INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_outbox_due ON ops_outbox(channel,sent_at,dropped_at,next_at);
CREATE INDEX IF NOT EXISTS idx_outbox_dedup ON ops_outbox(dedup_key,created_at);
CREATE TABLE IF NOT EXISTS ops_state (key TEXT PRIMARY KEY, value TEXT, updated_at INTEGER);
CREATE TABLE IF NOT EXISTS health_checks (
 target TEXT NOT NULL, at INTEGER NOT NULL, ok INTEGER NOT NULL, ms INTEGER, tls_days INTEGER, detail TEXT,
 PRIMARY KEY(target,at)) WITHOUT ROWID;
PRAGMA user_version=2;
SQL);
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}
