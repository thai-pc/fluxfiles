import { rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const coreDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
export default async function globalTeardown() {
  try { rmSync(join(coreDir, '.env')); } catch { /* ignore */ }
}
