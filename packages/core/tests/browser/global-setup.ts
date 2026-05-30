import { writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TEST_SECRET } from './secret';

// packages/core — writing .env here takes precedence over the repo-root .env
// (index.php loads the package-root .env first), so the real S3/R2 creds are untouched.
const coreDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export default async function globalSetup() {
  writeFileSync(
    join(coreDir, '.env'),
    `FLUXFILES_SECRET=${TEST_SECRET}\nFLUXFILES_RATE_LIMIT_READ=10000\nFLUXFILES_RATE_LIMIT_WRITE=10000\n`
  );
}
