import { writeFileSync, readdirSync, rmSync } from 'node:fs';
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

  // Specs never clean up what they upload/create, so the shared `local` disk root
  // accumulates entries run after run. Once the root passes the UI's `listLimit`
  // (1000, dirs sorted before files), newly uploaded files stop appearing in the
  // first page of /api/fm/list — every spec that waits on a freshly-uploaded
  // card then times out. Wipe it before each run so the count never grows unbounded.
  const uploadsDir = join(coreDir, 'storage', 'uploads');
  try {
    for (const entry of readdirSync(uploadsDir)) {
      rmSync(join(uploadsDir, entry), { recursive: true, force: true });
    }
  } catch { /* directory doesn't exist yet — fine */ }
}
