<?php

defined('ABSPATH') || exit;

/**
 * Creates/upgrades the wp_fluxfiles_* tables for the "db" storage backend via
 * dbDelta() — idempotent and diff-aware, so no separate migrations-tracking
 * table is needed here (unlike core standalone/Laravel). Column shapes mirror
 * packages/core/db/migrations/0001-0004_*.sql exactly (MySQL-resolved —
 * WordPress is always MySQL/MariaDB, no dialect branching needed).
 *
 * dbDelta()'s regex-based parser is strict: two spaces before
 * PRIMARY KEY/KEY/UNIQUE KEY, one column/key per line, never an inline
 * PRIMARY KEY inside a column definition, lowercase unquoted identifiers (no
 * backticks). Getting any of these wrong makes dbDelta silently skip the
 * offending line instead of erroring.
 */
class FluxFilesDbSchema
{
    /** Bump whenever the SQL below changes; drives the admin_init upgrade check. */
    public const VERSION = '1.1.0';

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $tFileMeta = $wpdb->prefix . 'fluxfiles_file_metadata';
        $tDirs = $wpdb->prefix . 'fluxfiles_directories';
        $tTrash = $wpdb->prefix . 'fluxfiles_trash';
        $tAudit = $wpdb->prefix . 'fluxfiles_audit_log';

        $sql = "CREATE TABLE {$tFileMeta} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  disk varchar(64) NOT NULL,
  owner varchar(191) NULL,
  path TEXT COLLATE utf8mb4_bin NOT NULL,
  path_hash char(64) NOT NULL,
  title text NULL,
  alt_text text NULL,
  caption text NULL,
  tags text NULL,
  mime varchar(191) NULL,
  size bigint(20) NULL,
  width int(11) NULL,
  height int(11) NULL,
  file_hash varchar(64) NULL,
  watermarked smallint(6) NULL,
  object_uuid varchar(64) NULL,
  created_at bigint(20) NULL,
  modified_at bigint(20) NULL,
  extra JSON NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY disk_path_hash (disk,path_hash),
  KEY disk_owner (disk,owner),
  KEY disk_file_hash (disk,file_hash),
  KEY disk_path (disk,path(191))
) {$charsetCollate};

CREATE TABLE {$tDirs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  disk varchar(64) NOT NULL,
  path TEXT COLLATE utf8mb4_bin NOT NULL,
  path_hash char(64) NOT NULL,
  created_at bigint(20) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY disk_path_hash (disk,path_hash),
  KEY disk_path (disk,path(191))
) {$charsetCollate};

CREATE TABLE {$tTrash} (
  disk varchar(64) NOT NULL,
  id varchar(64) NOT NULL,
  owner varchar(191) NULL,
  original_key text NOT NULL,
  basename varchar(512) NULL,
  is_dir smallint(6) DEFAULT 0,
  size bigint(20) NULL,
  deleted_at bigint(20) NULL,
  variants JSON NULL,
  meta JSON NULL,
  files JSON NULL,
  dirs JSON NULL,
  PRIMARY KEY  (disk,id),
  KEY disk_owner (disk,owner),
  KEY disk_deleted_at (disk,deleted_at)
) {$charsetCollate};

CREATE TABLE {$tAudit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  disk varchar(64) NOT NULL,
  owner varchar(191) NULL,
  action varchar(191) NOT NULL,
  file_key text NULL,
  ip varchar(64) NULL,
  user_agent text NULL,
  detail text NULL,
  created_at bigint(20) NOT NULL,
  content_hash char(64) NULL,
  PRIMARY KEY  (id),
  KEY disk_owner_created_at (disk,owner,created_at),
  KEY disk_created_at (disk,created_at),
  KEY disk_action_created_at (disk,action,created_at),
  UNIQUE KEY disk_content_hash (disk,content_hash)
) {$charsetCollate};";

        dbDelta($sql);

        update_option('fluxfiles_db_version', self::VERSION);
    }

    /**
     * admin_init fallback — dbDelta never re-runs automatically after a
     * plugin update (WordPress doesn't reliably re-fire activation hooks on
     * auto-update), so this catches schema upgrades on the next admin page
     * load instead of requiring a manual deactivate/reactivate.
     */
    public static function maybeUpgrade(): void
    {
        if (get_option('fluxfiles_db_version') !== self::VERSION) {
            self::install();
        }
    }
}
