import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { promises as fs } from 'node:fs';
import { loginAdmin, openFiles, uploadAndSee, cardByName, pngFile, textFile, api, apiTry, hookEvents, HOOKS, BASE_OVERRIDES, grantAuditViaSettings } from './helpers';

// Versioning, Webhooks, Legal hold, Audit export — real modules behind the WP proxy.
// Legal hold / retention / audit export need the `audit` permission, granted the
// operator's way (Settings → FluxFiles) before the suite.
test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage();
  await grantAuditViaSettings(page);
  await page.close();
});

test('versioning: every proxied save is kept; restoring v1 brings its bytes back', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const f = textFile(`notes-${Date.now()}.txt`, 'v1\n');
  await uploadAndSee(m, f);
  await api(m, 'PUT', '/api/fm/content', { disk: 'local', path: f.name, content: 'v2\n' });
  await api(m, 'PUT', '/api/fm/content', { disk: 'local', path: f.name, content: 'v3\n' });

  await cardByName(m.fm, f.name).click();
  await m.fm.getByRole('button', { name: 'Version history', exact: true }).click();
  const modal = m.fm.locator('.ff-versions-modal');
  await expect(modal.locator('tbody tr')).toHaveCount(2, { timeout: 15_000 });
  page.once('dialog', (d) => d.accept());
  await modal.locator('tbody tr').last().getByRole('button', { name: 'Restore' }).click();
  await expect(m.fm.getByText('Version restored')).toBeVisible();
  const now = await api(m, 'GET', `/api/fm/content?disk=local&path=${f.name}`);
  expect((now as { content: string }).content).toBe('v1\n');
});

test('webhooks: proxied upload / trash / test ping each POST a signed event', async ({ page }) => {
  await fetch(`${HOOKS}/events`, { method: 'DELETE' });
  await loginAdmin(page);
  const m = await openFiles(page);
  const f = pngFile(`hooked-${Date.now()}.png`);
  await uploadAndSee(m, f);
  await api(m, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
  await api(m, 'POST', '/api/fm/webhooks/test', {});

  await expect.poll(async () => (await hookEvents()).length, { timeout: 20_000 }).toBeGreaterThanOrEqual(3);
  const parsed = (await hookEvents()).map((e) => ({ ...e, json: JSON.parse(e.body) }));
  const names = parsed.map((e) => e.json.event);
  expect(names).toEqual(expect.arrayContaining(['upload', 'trash']));
  expect(names.some((n) => /test|ping/.test(String(n)))).toBe(true);
  for (const e of parsed) {
    expect(e.headers['x-fluxfiles-signature']).toBe('sha256=' + createHmac('sha256', BASE_OVERRIDES.webhook_secret).update(e.body).digest('hex'));
  }
  for (const e of parsed.filter((x) => ['upload', 'trash'].includes(x.json.event))) {
    expect(JSON.stringify(e.json)).toContain(f.name);
  }
});

test('legal hold: place via the UI blocks trash; status/list report it; release unblocks', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const f = pngFile(`evidence-${Date.now()}.png`);
  await uploadAndSee(m, f);

  await cardByName(m.fm, f.name).click();
  await m.fm.getByRole('button', { name: 'Legal Hold', exact: true }).click();
  await m.fm.getByRole('button', { name: 'Place legal hold…' }).click();
  await m.fm.getByPlaceholder('Why is this item being held? (required)').fill('Litigation 2026-117');
  await m.fm.getByRole('button', { name: 'Place legal hold…' }).click();
  await expect(cardByName(m.fm, f.name).locator('.ff-hold-badge')).toBeVisible({ timeout: 15_000 });
  await page.keyboard.press('Escape');

  const refused = await apiTry(m, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
  expect(refused.ok).toBe(false);
  expect(String(refused.code)).toMatch(/hold/i);
  const status = await api(m, 'GET', `/api/fm/hold/status?disk=local&path=${encodeURIComponent(f.name)}`);
  expect(status).toMatchObject({ on_hold: true, reason: 'Litigation 2026-117' });
  const holds = await api(m, 'GET', '/api/fm/hold/list?disk=local');
  expect(JSON.stringify(holds)).toContain(f.name);

  await cardByName(m.fm, f.name).click();
  await m.fm.getByRole('button', { name: 'Legal Hold', exact: true }).click();
  await m.fm.getByRole('button', { name: 'Release hold…' }).click();
  const reason = m.fm.getByPlaceholder('Why is this item being held? (required)');
  if (await reason.isVisible()) await reason.fill('Case closed');
  page.once('dialog', (d) => d.accept());
  await m.fm.getByRole('button', { name: 'Release hold…' }).last().click();
  await expect(cardByName(m.fm, f.name).locator('.ff-hold-badge')).toBeHidden({ timeout: 15_000 });
  await api(m, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
});

test('retention: a dry run through the proxy reports, touches nothing (legal-hold module)', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const f = textFile(`keep-${Date.now()}.txt`, 'fresh\n');
  await uploadAndSee(m, f);
  const report = await api(m, 'POST', '/api/fm/retention/run', { disk: 'local', dry_run: true });
  expect(report).toHaveProperty('items');
  expect(JSON.stringify(report)).not.toContain(`"path":"${f.name}","result":"trashed"`);
  expect(await api(m, 'GET', `/api/fm/content?disk=local&path=${f.name}`)).toMatchObject({ content: 'fresh\n' });
});

test('audit export: CSV via the Activity panel lists our upload; chain verifies; fixity baselines', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const f = pngFile(`audited-${Date.now()}.png`);
  await uploadAndSee(m, f);

  await m.fm.getByRole('button', { name: 'Activity' }).first().click();
  const [dl] = await Promise.all([page.waitForEvent('download'), m.fm.getByRole('button', { name: 'Export CSV' }).click()]);
  const csv = await fs.readFile((await dl.path())!, 'utf8');
  expect(csv.split('\n')[0]).toMatch(/action/);
  expect(csv).toContain(f.name);

  await m.fm.getByRole('button', { name: 'Verify log' }).click();
  await expect(m.fm.locator('.ff-integrity')).toContainText('Log intact', { timeout: 20_000 });
  await m.fm.getByRole('button', { name: 'Integrity check' }).click();
  await expect(m.fm.locator('.ff-integrity')).not.toContainText('error', { timeout: 30_000 });
  await expect(m.fm.locator('.ff-integrity')).toContainText(/checked|baseline|file/i);
  // NDJSON export over the same proxied route.
  const nd = await m.frame.evaluate(async () => {
    const c = (document.querySelector('.ff-app') as any)._x_dataStack[0];
    const r = await fetch(c.joinUrl('/api/fm/audit/export?disk=local&format=ndjson'), { headers: { Authorization: 'Bearer ' + c.token } });
    return { status: r.status, type: r.headers.get('content-type') || '', body: await r.text() };
  });
  expect(nd.status).toBe(200);
  expect(nd.body).toContain(f.name);
});

test('audit purge: the admin-only proxied route needs a cutoff, and a pre-history cutoff removes nothing', async ({ page }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const noCutoff = await apiTry(m, 'POST', '/api/fm/audit/purge', { disk: 'local' });
  expect(noCutoff.code).toBe('audit_purge_no_cutoff');
  const res = await api(m, 'POST', '/api/fm/audit/purge', { disk: 'local', before: 946684800 });   // 2000-01-01
  expect(res).toEqual({ archives_deleted: 0, live_lines_removed: 0 });
  await m.fm.getByRole('button', { name: 'Activity' }).first().click();
  await m.fm.getByRole('button', { name: 'Verify log' }).click();
  await expect(m.fm.locator('.ff-integrity')).toContainText('Log intact', { timeout: 20_000 });
});
