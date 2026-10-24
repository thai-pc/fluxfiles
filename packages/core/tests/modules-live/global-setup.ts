import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

// curl, not fetch: a server still booting can drop a socket mid-response, and
// Node 24's undici then dies on an internal assert that no try/catch can catch.
async function up(url: string, tries = 120): Promise<boolean> {
  for (let i = 0; i < tries; i++) {
    try { execFileSync('curl', ['-fsS', '-o', '/dev/null', '--max-time', '10', url], { stdio: 'ignore' }); return true; } catch { /* not yet */ }
    await new Promise((r) => setTimeout(r, 2000));
  }
  return false;
}

export default async function globalSetup() {
  if (process.env.FF_LIVE_REUSE !== '1') {
    execFileSync('bash', ['stage.sh'], { cwd: here, stdio: 'inherit' });
    execFileSync('docker', ['compose', 'up', '-d', '--build'], { cwd: here, stdio: 'inherit' });
  }
  if (!(await up('http://localhost:8190/public/index.html'))) throw new Error('core did not come up on :8190');
  if (!(await up('http://localhost:8191/.well-known/openid-configuration'))) throw new Error('test IdP did not come up');
  // clamd loads its signatures after the container starts; the virus spec needs it.
  for (let i = 0; i < 90; i++) {
    try {
      execFileSync('docker', ['compose', 'exec', '-T', 'core', 'test', '-S', '/run/clamav/clamd.ctl'], { cwd: here, stdio: 'ignore' });
      return;
    } catch { await new Promise((r) => setTimeout(r, 2000)); }
  }
  throw new Error('clamd did not start in the core container');
}
