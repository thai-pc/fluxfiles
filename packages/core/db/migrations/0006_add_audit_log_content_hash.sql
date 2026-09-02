ALTER TABLE audit_log ADD COLUMN content_hash CHAR(64) NULL;
CREATE UNIQUE INDEX idx_audit_log_disk_content_hash ON audit_log (disk, content_hash);
