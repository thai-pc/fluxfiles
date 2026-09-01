CREATE TABLE trash (
  disk VARCHAR(64) NOT NULL,
  id VARCHAR(64) NOT NULL,
  owner VARCHAR(191) NULL,
  original_key TEXT NOT NULL,
  basename VARCHAR(512) NULL,
  is_dir SMALLINT NOT NULL DEFAULT 0,
  size BIGINT NULL,
  deleted_at BIGINT NULL,
  variants {{JSON}} NULL,
  meta {{JSON}} NULL,
  files {{JSON}} NULL,
  dirs {{JSON}} NULL,
  PRIMARY KEY (disk, id)
);

CREATE INDEX idx_trash_disk_owner ON trash (disk, owner);
CREATE INDEX idx_trash_disk_deleted_at ON trash (disk, deleted_at);
