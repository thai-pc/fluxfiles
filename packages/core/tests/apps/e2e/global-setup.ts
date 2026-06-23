import { writeFileSync, existsSync, copyFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TEST_SECRET } from './secret';

// e2e/ -> apps -> tests -> core
const coreDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const envPath = join(coreDir, '.env');
const backupPath = join(coreDir, '.env.adapter-e2e.bak');

// Write a minimal core .env (test secret + relaxed rate limits) so the iframe
// backend signs with the same key the wrappers mint against. Any pre-existing
// .env is backed up and restored in teardown so real S3/R2 creds are untouched.
export default async function globalSetup() {
  if (existsSync(envPath)) copyFileSync(envPath, backupPath);
  else if (existsSync(backupPath)) rmSync(backupPath); // stale backup from a crashed run
  writeFileSync(
    envPath,
    `FLUXFILES_SECRET=${TEST_SECRET}\nFLUXFILES_RATE_LIMIT_READ=10000\nFLUXFILES_RATE_LIMIT_WRITE=10000\n`
  );
}
