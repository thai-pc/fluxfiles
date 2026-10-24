import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const compose = ['compose', '-p', 'ff-live-laravel', '-f', 'docker-compose.laravel.yml'];

// curl, not fetch: a server still booting can drop a socket mid-response, and
// Node 24's undici then dies on an internal assert that no try/catch can catch.
async function up(url: string, tries = 120): Promise<boolean> {
  for (let i = 0; i < tries; i++) {
    try { execFileSync('curl', ['-fsS', '-o', '/dev/null', '--max-time', '10', url], { stdio: 'ignore' }); return true; } catch { /* not yet */ }
    await new Promise((r) => setTimeout(r, 2000));
  }
  return false;
}

function hasImage(name: string): boolean {
  try { execFileSync('docker', ['image', 'inspect', name], { stdio: 'ignore' }); return true; } catch { return false; }
}

export default async function globalSetup() {
  if (process.env.FF_LIVE_REUSE !== '1') {
    // The Laravel image is built FROM the base rig's engine image.
    if (!hasImage('ff-modules-live:latest')) {
      execFileSync('bash', ['../stage.sh'], { cwd: here, stdio: 'inherit' });
      execFileSync('docker', ['compose', 'build', 'core'], { cwd: `${here}/..`, stdio: 'inherit' });
    }
    execFileSync('bash', ['stage.sh'], { cwd: here, stdio: 'inherit' });
    execFileSync('docker', [...compose, 'up', '-d', '--build'], { cwd: here, stdio: 'inherit' });
  }
  if (!(await up('http://localhost:8290/public/index.html'))) throw new Error('Laravel did not come up on :8290');
  if (!(await up('http://localhost:8293/events'))) throw new Error('webhook receiver did not come up on :8293');
  // clamd loads its signatures after the container starts; the virus spec needs it.
  for (let i = 0; i < 90; i++) {
    try {
      execFileSync('docker', [...compose, 'exec', '-T', 'laravel', 'test', '-S', '/run/clamav/clamd.ctl'], { cwd: here, stdio: 'ignore' });
      return;
    } catch { await new Promise((r) => setTimeout(r, 2000)); }
  }
  throw new Error('clamd did not start in the Laravel container');
}
