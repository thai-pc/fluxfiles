import { existsSync, copyFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';

const coreDir = join(__dirname, '..', '..');
const envPath = join(coreDir, '.env');
const backupPath = join(coreDir, '.env.editors-e2e.bak');

// Restore the pre-test .env (or remove the test one if there was none).
export default async function globalTeardown() {
  if (existsSync(backupPath)) {
    copyFileSync(backupPath, envPath);
    rmSync(backupPath);
  } else if (existsSync(envPath)) {
    rmSync(envPath);
  }
}
