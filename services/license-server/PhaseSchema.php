<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use PDO;

final class PhaseSchema
{
    public static function migrate(PDO $db): void
    {
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() >= 3) { return; }
        $db->exec('BEGIN IMMEDIATE');
        try {
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() < 3) {
                $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS subscriptions (
 subscription_id TEXT PRIMARY KEY, customer_id TEXT, product_id TEXT, status TEXT,
 cancel_at_period_end INTEGER, current_period_end INTEGER, canceled_at INTEGER, revoked_at INTEGER,
 updated_at INTEGER NOT NULL, last_webhook_id TEXT);
CREATE TABLE IF NOT EXISTS ops_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, kind TEXT NOT NULL,
 ref_jti TEXT, ref_order TEXT, ref_event INTEGER, summary TEXT NOT NULL, resolved_at INTEGER, resolved_by TEXT);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_open_item ON ops_items(kind,COALESCE(ref_jti,''),COALESCE(ref_order,''),COALESCE(ref_event,0)) WHERE resolved_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_ops_item_time ON ops_items(at);
CREATE TABLE IF NOT EXISTS activity_daily (
 day TEXT NOT NULL, jti TEXT NOT NULL, kind TEXT NOT NULL, result TEXT NOT NULL, n INTEGER NOT NULL, last_at INTEGER NOT NULL,
 PRIMARY KEY(day,jti,kind,result)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS downloads_daily (
 day TEXT NOT NULL, module TEXT NOT NULL, version TEXT NOT NULL DEFAULT '', source TEXT NOT NULL, n INTEGER NOT NULL,
 PRIMARY KEY(day,module,version,source)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS public_counters (
 day TEXT NOT NULL, source TEXT NOT NULL, subject TEXT NOT NULL, metric TEXT NOT NULL, value INTEGER NOT NULL,
 PRIMARY KEY(day,source,subject,metric)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS demo_daily (
 day TEXT PRIMARY KEY, sandboxes_created INTEGER, sandbox_limit_hits INTEGER, page_limit_hits INTEGER,
 sandboxes_live INTEGER, uploads_bytes INTEGER);
CREATE TABLE IF NOT EXISTS customer_notes (
 email_lower TEXT PRIMARY KEY, note TEXT NOT NULL, updated_at INTEGER NOT NULL, updated_by TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS advisories (
 id TEXT PRIMARY KEY, component TEXT NOT NULL, affected TEXT NOT NULL, fixed_in TEXT, severity TEXT NOT NULL,
 title TEXT NOT NULL, url TEXT, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notices (
 id INTEGER PRIMARY KEY AUTOINCREMENT, advisory_id TEXT NOT NULL, created_at INTEGER NOT NULL, created_by TEXT NOT NULL,
 subject TEXT NOT NULL, body_text TEXT NOT NULL, recipients INTEGER NOT NULL, sent INTEGER NOT NULL DEFAULT 0,
 failed INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL, include_unknown INTEGER NOT NULL DEFAULT 0, context_json TEXT);
PRAGMA user_version=3;
SQL);
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }
}
