import { writeFileSync, existsSync, copyFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { TEST_SECRET } from './real/env';

// editors/ -> tests -> core
const coreDir = join(__dirname, '..', '..');
const envPath = join(coreDir, '.env');
const backupPath = join(coreDir, '.env.editors-e2e.bak');

// The real-picker specs need a live core that signs with the same throwaway key the
// specs mint tokens with. Any pre-existing core .env is backed up and restored by
// global-teardown, so real S3/R2 credentials are untouched.
export default async function globalSetup() {
  if (existsSync(envPath)) copyFileSync(envPath, backupPath);
  else if (existsSync(backupPath)) rmSync(backupPath); // stale backup from a crashed run
  writeFileSync(
    envPath,
    `FLUXFILES_SECRET=${TEST_SECRET}\nFLUXFILES_RATE_LIMIT_READ=10000\nFLUXFILES_RATE_LIMIT_WRITE=10000\n`
  );
}
