import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

export default async function globalTeardown() {
  if (process.env.FF_LIVE_KEEP === '1' || process.env.FF_LIVE_REUSE === '1') return;
  execFileSync('docker', ['compose', '-p', 'ff-live-wp', '-f', 'docker-compose.wordpress.yml', 'down', '-v'],
    { cwd: dirname(fileURLToPath(import.meta.url)), stdio: 'inherit' });
}
