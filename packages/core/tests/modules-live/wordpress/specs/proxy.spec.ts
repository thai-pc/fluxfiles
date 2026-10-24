import { test, expect } from '@playwright/test';
import { loginAdmin, openFiles, api, apiTry, endpoint, setOverrides, wp, grantAuditViaSettings } from './helpers';

// How the paid modules are switched on in WordPress: the licence lives in the plugin
// OPTION (no FLUXFILES_LICENSE_KEY env in this container), the claims come through
// the `fluxfiles_token_overrides` filter, and the 3-layer gate still holds per claim.

test.afterEach(() => setOverrides());

test('the embed talks to the WP REST proxy; the option-stored licence covers all 13 modules', async ({ page }) => {
  expect(wp('eval', 'echo getenv("FLUXFILES_LICENSE_KEY") === false ? "unset" : "set";')).toBe('unset');
  await loginAdmin(page);
  const m = await openFiles(page);
  expect(await endpoint(m)).toContain('/wp-json/fluxfiles/v1/api/fm/list');
  const lic = await api(m, 'GET', '/api/fm/license');
  expect(lic.edition).toBe('enterprise');
  expect(lic.modules).toEqual(expect.arrayContaining(['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr', 'virus',
    'backup', 'c2pa', 'audit-export', 'dlp', 'legal-hold']));
});

test('claim layer: without allow_share the proxied share route answers 403 allow_share_forbidden', async ({ page }) => {
  setOverrides({ allow_share: false, allow_ocr: false });
  await loginAdmin(page);
  const m = await openFiles(page);
  const share = await apiTry(m, 'GET', '/api/fm/share/list?disk=local');
  expect(share.ok).toBe(false);
  expect(share.code).toBe('allow_share_forbidden');
  const ocr = await apiTry(m, 'POST', '/api/fm/ocr', { disk: 'local', path: 'nope.png' });
  expect(ocr.code).toBe('allow_ocr_forbidden');
  // …while a module still on in the same token answers normally.
  expect(Array.isArray(await api(m, 'GET', '/api/fm/intake/list?disk=local'))).toBe(true);
});

test('settings: an Administrator\'s embed token carries `audit` only once it is ticked in Settings → FluxFiles', async ({ page }) => {
  wp('option', 'update', 'fluxfiles_default_perms', '["read","write","delete"]', '--format=json');
  await loginAdmin(page);
  let m = await openFiles(page);
  const denied = await apiTry(m, 'GET', '/api/fm/hold/list?disk=local');
  expect(denied.ok).toBe(false);
  expect(denied.code).toBe('forbidden');
  await grantAuditViaSettings(page);
  m = await openFiles(page);
  expect(Array.isArray(await api(m, 'GET', '/api/fm/hold/list?disk=local'))).toBe(true);
});

test('compliance scorecard over the proxy sees the licensed modules', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const card = await api(m, 'GET', '/api/fm/compliance/scorecard');
  expect(JSON.stringify(card)).toMatch(/legal|audit/i);
});

test('module engines: configured through the plugin option; a real env var still coexists (and wins)', async ({ page }) => {
  // Not in the process env: they only reach the modules through seedModuleEnv().
  for (const k of ['FLUXFILES_AIVISION_ENDPOINT', 'FLUXFILES_DLP_ENDPOINT', 'FLUXFILES_WEBHOOK_ALLOW_INTERNAL']) {
    expect(wp('eval', `echo getenv("${k}") === false ? "unset" : "set";`)).toBe('unset');
  }
  expect(wp('eval', 'echo getenv("FLUXFILES_C2PA_MANIFEST") === false ? "unset" : "set";')).toBe('set');
  expect(wp('option', 'get', 'fluxfiles_module_env', '--format=json')).toContain('ff-presidio');
  // The settings screen marks the env-provided key as winning and offers the rest.
  await loginAdmin(page);
  await page.goto('/wp-admin/options-general.php?page=fluxfiles');
  await expect(page.getByText('Set by the FLUXFILES_C2PA_MANIFEST environment variable')).toBeVisible();
  await expect(page.locator('input[name="fluxfiles_module_env[FLUXFILES_DLP_ENDPOINT]"]')).toHaveValue('http://ff-presidio:3000');
  await expect(page.locator('input[name="fluxfiles_module_env[FLUXFILES_C2PA_MANIFEST]"]')).toHaveCount(0);
});
