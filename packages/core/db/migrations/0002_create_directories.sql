CREATE TABLE directories (
  id {{AUTOINCREMENT}},
  disk VARCHAR(64) NOT NULL,
  path TEXT NOT NULL{{BINCOLLATE}},
  path_hash CHAR(64) NOT NULL,
  created_at BIGINT NULL
);

CREATE UNIQUE INDEX idx_directories_disk_path_hash ON directories (disk, path_hash);
CREATE INDEX idx_directories_disk_path ON directories (disk, {{PATH_IDX}});
