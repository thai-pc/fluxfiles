CREATE TABLE audit_log (
  id {{AUTOINCREMENT}},
  disk VARCHAR(64) NOT NULL,
  owner VARCHAR(191) NULL,
  action VARCHAR(191) NOT NULL,
  file_key TEXT NULL,
  ip VARCHAR(64) NULL,
  user_agent TEXT NULL,
  detail TEXT NULL,
  created_at BIGINT NOT NULL
);

CREATE INDEX idx_audit_log_disk_owner_created_at ON audit_log (disk, owner, created_at);
CREATE INDEX idx_audit_log_disk_created_at ON audit_log (disk, created_at);
CREATE INDEX idx_audit_log_disk_action_created_at ON audit_log (disk, action, created_at);
