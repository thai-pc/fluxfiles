CREATE TABLE rate_limits (
  id {{AUTOINCREMENT}},
  identifier VARCHAR(191) NOT NULL,
  bucket VARCHAR(16) NOT NULL,
  ts BIGINT NOT NULL
);

CREATE INDEX idx_rl_identifier_bucket_ts ON rate_limits (identifier, bucket, ts);
