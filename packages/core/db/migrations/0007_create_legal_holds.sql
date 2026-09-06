CREATE TABLE legal_holds (
  disk VARCHAR(64) NOT NULL,
  id VARCHAR(64) NOT NULL,
  path TEXT NOT NULL,
  is_dir SMALLINT NOT NULL DEFAULT 0,
  reason TEXT NULL,
  placed_by VARCHAR(191) NULL,
  placed_at BIGINT NULL,
  released_at BIGINT NULL,
  released_by VARCHAR(191) NULL,
  release_reason TEXT NULL,
  PRIMARY KEY (disk, id)
);

CREATE INDEX idx_legal_holds_disk_released_at ON legal_holds (disk, released_at);
