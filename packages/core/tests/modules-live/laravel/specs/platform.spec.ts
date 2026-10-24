import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { openHost, raw, LARAVEL } from './helpers';

// The Laravel host itself: a default Composer install (no FLUXFILES_BASE_PATH)
// must serve everything the <x-fluxfiles> component needs, the license must
// reach the modules, and the routes the proxy deliberately does NOT expose stay
// unexposed (an API-coverage fact, asserted so it can't drift silently).

test('component boot: /fluxfiles.js is served and the UI renders translated labels (not i18n keys)', async ({ page }) => {
  const sdk = await page.request.get(`${LARAVEL}/fluxfiles.js`);
  expect(sdk.status()).toBe(200);
  expect(sdk.headers()['content-type']).toContain('javascript');
  expect(await sdk.text()).toContain('FluxFiles');

  const { fm } = await openHost(page, `lboot-${Date.now()}`);
  await expect(fm.getByRole('button', { name: 'New folder' })).toBeVisible();
  await expect(fm.locator('body')).not.toContainText('toolbar.upload');
});

test('license: the proxied /api/fm/license reports all 13 modules licensed', async ({ page }) => {
  const { frame } = await openHost(page, `llic-${Date.now()}`);
  const r = await raw(frame, 'GET', '/api/fm/license');
  expect(r.status).toBe(200);
  const info = r.json.data as { edition: string; modules?: string[] };
  expect(info.edition).toBe('enterprise');
  for (const m of ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr', 'virus', 'backup', 'c2pa', 'audit-export', 'dlp', 'legal-hold']) {
    expect(JSON.stringify(info)).toContain(m);
  }
});

test('not proxied by design: SSO bridge, tus and metadata export/import are 404 on the Laravel host', async ({ page }) => {
  const { frame } = await openHost(page, `lunproxied-${Date.now()}`);
  for (const [method, path] of [
    ['GET', '/api/fm/sso/login'], ['GET', '/api/fm/sso/callback?code=x&state=y'], ['POST', '/api/fm/sso/exchange'],
    ['POST', '/api/fm/tus'], ['GET', '/api/fm/metadata/export'], ['POST', '/api/fm/metadata/import'],
  ] as const) {
    const r = await raw(frame, method, path, method === 'POST' ? {} : undefined);
    expect(r.status, `${method} ${path}`).toBe(404);
  }
});

test('config:cache (license + kill switch only in .env): modules stay licensed, FLUXFILES_TERMINAL_DISABLED still bites', async () => {
  // fixtures/config-cache-probe.sh: a second Laravel server with an EMPTY process
  // environment after `php artisan config:cache`; prints one JSON line, no secrets.
  const out = execFileSync('docker', ['compose', '-p', 'ff-live-laravel', 'exec', '-T', 'laravel', 'bash', '/fixtures-laravel/config-cache-probe.sh']).toString().trim();
  const probe = JSON.parse(out.split('\n').pop()!);
  expect(probe).toEqual({ edition: 'enterprise', status: 'active', terminal_error_code: 'terminal_disabled' });
});
