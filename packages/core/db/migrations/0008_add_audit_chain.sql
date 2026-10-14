ALTER TABLE audit_log ADD COLUMN prev_hash CHAR(64) NULL;
ALTER TABLE audit_log ADD COLUMN entry_hash CHAR(64) NULL;
CREATE TABLE audit_chain_head (
  disk VARCHAR(64) NOT NULL PRIMARY KEY,
  head_hash CHAR(64) NOT NULL
);
