import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const COMPOSE = ['compose', '-p', 'ff-live-wp', '-f', 'docker-compose.wordpress.yml'];

// curl, not fetch: while WordPress boots it can drop a socket mid-response, and
// Node 24's undici then dies on an internal assert that no try/catch can catch.
async function up(url: string, tries = 150): Promise<boolean> {
  for (let i = 0; i < tries; i++) {
    try { execFileSync('curl', ['-fsS', '-o', '/dev/null', '--max-time', '10', url], { stdio: 'ignore' }); return true; } catch { /* not yet */ }
    await new Promise((r) => setTimeout(r, 2000));
  }
  return false;
}

export default async function globalSetup() {
  if (process.env.FF_LIVE_REUSE !== '1') {
    // The WP image is FROM ff-modules-live:latest (PHP + ClamAV + Tesseract + c2patool).
    try { execFileSync('docker', ['image', 'inspect', 'ff-modules-live:latest'], { stdio: 'ignore' }); }
    catch { throw new Error('ff-modules-live:latest missing — build the base rig first: (cd .. && bash stage.sh && docker compose build)'); }
    execFileSync('bash', ['stage.sh'], { cwd: here, stdio: 'inherit' });
    execFileSync('docker', [...COMPOSE, 'up', '-d', '--build'], { cwd: here, stdio: 'inherit' });
  }
  if (!(await up('http://localhost:8390/files/'))) throw new Error('WordPress did not come up on :8390');
  if (!(await up('http://localhost:8393/events'))) throw new Error('webhook receiver did not come up on :8393');
  for (let i = 0; i < 90; i++) {
    try {
      execFileSync('docker', [...COMPOSE, 'exec', '-T', 'wp', 'test', '-S', '/run/clamav/clamd.ctl'], { cwd: here, stdio: 'ignore' });
      return;
    } catch { await new Promise((r) => setTimeout(r, 2000)); }
  }
  throw new Error('clamd did not start in the wp container');
}
