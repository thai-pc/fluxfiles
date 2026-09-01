CREATE TABLE file_metadata (
  id {{AUTOINCREMENT}},
  disk VARCHAR(64) NOT NULL,
  owner VARCHAR(191) NULL,
  path TEXT NOT NULL{{BINCOLLATE}},
  path_hash CHAR(64) NOT NULL,
  title TEXT NULL,
  alt_text TEXT NULL,
  caption TEXT NULL,
  tags TEXT NULL,
  mime VARCHAR(191) NULL,
  size BIGINT NULL,
  width INT NULL,
  height INT NULL,
  file_hash VARCHAR(64) NULL,
  watermarked SMALLINT NULL,
  object_uuid VARCHAR(64) NULL,
  created_at BIGINT NULL,
  modified_at BIGINT NULL,
  extra {{JSON}} NULL
);

CREATE UNIQUE INDEX idx_file_metadata_disk_path_hash ON file_metadata (disk, path_hash);
CREATE INDEX idx_file_metadata_disk_owner ON file_metadata (disk, owner);
CREATE INDEX idx_file_metadata_disk_file_hash ON file_metadata (disk, file_hash);
CREATE INDEX idx_file_metadata_disk_path ON file_metadata (disk, {{PATH_IDX}});
