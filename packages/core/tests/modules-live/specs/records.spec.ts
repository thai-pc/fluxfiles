import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { promises as fs } from 'node:fs';
import { token, openManager, uploadAndSee, cardByName, pngFile, textFile, api, hookEvents, HOOKS } from './helpers';

// Versioning, Webhooks, Legal hold, Audit export — real modules, real storage.

test('versioning: every save is kept; restoring an old version brings its bytes back', async ({ page }) => {
  const user = `ver-${Date.now()}`;
  await openManager(page, token(user, { allow_versioning: true, allow_code_edit: true, prefix: user }));
  const f = textFile('notes.txt', 'v1\n');
  await uploadAndSee(page, f);
  await api(page, 'PUT', '/api/fm/content', { disk: 'local', path: f.name, content: 'v2\n' });
  await api(page, 'PUT', '/api/fm/content', { disk: 'local', path: f.name, content: 'v3\n' });

  await cardByName(page, f.name).click();
  await page.getByRole('button', { name: 'Version history', exact: true }).click();
  const modal = page.locator('.ff-versions-modal');
  await expect(modal.locator('tbody tr')).toHaveCount(2, { timeout: 15_000 });   // v1 and v2 (v3 is current)
  page.once('dialog', (d) => d.accept());
  await modal.locator('tbody tr').last().getByRole('button', { name: 'Restore' }).click();   // oldest = v1
  await expect(page.getByText('Version restored')).toBeVisible();
  const now = await api(page, 'GET', `/api/fm/content?disk=local&path=${f.name}`);
  expect((now as { content: string }).content).toBe('v1\n');
});

test('webhooks: an upload and a trash each POST a signed event to the receiver', async ({ page }) => {
  await fetch(`${HOOKS}/events`, { method: 'DELETE' });
  const user = `hook-${Date.now()}`;
  const secret = 'live-hook-secret';
  await openManager(page, token(user, {
    allow_webhooks: true, webhook_url: 'http://ff-hooks:8193/hook', webhook_secret: secret, prefix: user,
  }));
  const f = pngFile(`hooked-${Date.now()}.png`);
  await uploadAndSee(page, f);
  await api(page, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });

  await expect.poll(async () => (await hookEvents()).length, { timeout: 15_000 }).toBeGreaterThanOrEqual(2);
  const events = await hookEvents();
  const parsed = events.map((e) => ({ ...e, json: JSON.parse(e.body) }));
  const names = parsed.map((e) => e.json.event);
  expect(names).toContain('upload');
  expect(names).toContain('trash');
  for (const e of parsed) {
    const sig = e.headers['x-fluxfiles-signature'];
    expect(sig).toBe('sha256=' + createHmac('sha256', secret).update(e.body).digest('hex'));
    expect(JSON.stringify(e.json)).toContain(f.name);
  }
});

test('legal hold: a held file cannot be trashed; after release it can', async ({ page }) => {
  const user = `hold-${Date.now()}`;
  await openManager(page, token(user, { allow_legal_hold: true, prefix: user }, ['read', 'write', 'delete', 'audit']));
  const f = pngFile(`evidence-${Date.now()}.png`);
  await uploadAndSee(page, f);

  await cardByName(page, f.name).click();
  await page.getByRole('button', { name: 'Legal Hold', exact: true }).click();
  await page.getByRole('button', { name: 'Place legal hold…' }).click();
  await page.getByPlaceholder('Why is this item being held? (required)').fill('Litigation 2026-117');
  await page.getByRole('button', { name: 'Place legal hold…' }).click();
  await expect(cardByName(page, f.name).locator('.ff-hold-badge')).toBeVisible({ timeout: 15_000 });
  await page.keyboard.press('Escape');                       // close the hold dialog

  const refused = await page.evaluate(async (name) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    try { await c.api('POST', '/api/fm/trash', { disk: 'local', path: name }); return 'trashed'; }
    catch (e: any) { return e.code || e.message; }
  }, f.name);
  expect(String(refused)).toMatch(/hold/i);

  await cardByName(page, f.name).click();
  await page.getByRole('button', { name: 'Legal Hold', exact: true }).click();
  await page.getByRole('button', { name: 'Release hold…' }).click();
  const reason = page.getByPlaceholder('Why is this item being held? (required)');
  if (await reason.isVisible()) await reason.fill('Case closed');
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Release hold…' }).last().click();
  await expect(cardByName(page, f.name).locator('.ff-hold-badge')).toBeHidden({ timeout: 15_000 });
  await api(page, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
});

test('audit export: CSV download lists our upload; the HMAC chain verifies; fixity baselines', async ({ page }) => {
  const user = `audit-${Date.now()}`;
  await openManager(page, token(user, { allow_audit_export: true }, ['read', 'write', 'audit']));
  const f = pngFile(`audited-${Date.now()}.png`);
  await uploadAndSee(page, f);

  await page.getByRole('button', { name: 'Activity' }).first().click();
  const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Export CSV' }).click()]);
  const csv = await fs.readFile((await dl.path())!, 'utf8');
  expect(csv.split('\n')[0]).toMatch(/action/);
  expect(csv).toContain(f.name);
  expect(csv).toContain(user);

  await page.getByRole('button', { name: 'Verify log' }).click();
  await expect(page.locator('.ff-integrity')).toContainText('Log intact', { timeout: 20_000 });
  await page.getByRole('button', { name: 'Integrity check' }).click();
  await expect(page.locator('.ff-integrity')).not.toContainText('error', { timeout: 30_000 });
  await expect(page.locator('.ff-integrity')).toContainText(/checked|baseline|file/i);
});
