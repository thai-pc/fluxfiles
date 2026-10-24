import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { promises as fs } from 'node:fs';
import { openHost, uploadAndSee, cardByName, pngFile, textFile, api, raw, hookEvents, auditNdjson, HOOKS } from './helpers';

// Versioning, Webhooks, Legal hold (+ retention), Audit export — real modules,
// every call through FluxFilesController (Laravel proxy).

const LOCAL_ROOT = '/srv/laravel/storage/app/fluxfiles-uploads';
function inLaravel(...cmd: string[]) {
  return execFileSync('docker', ['compose', '-p', 'ff-live-laravel', 'exec', '-T', 'laravel', ...cmd]).toString();
}

test('versioning: every save is kept (keeper wired in the proxy); restoring an old version brings its bytes back', async ({ page }) => {
  const user = `lver-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_versioning: true, allow_code_edit: true, prefix: user });
  const f = textFile('notes.txt', 'v1\n');
  await uploadAndSee(fm, f);
  await api(frame, 'PUT', '/api/fm/content', { disk: 'local', path: f.name, content: 'v2\n' });
  await api(frame, 'PUT', '/api/fm/content', { disk: 'local', path: f.name, content: 'v3\n' });

  const versions = await api(frame, 'GET', `/api/fm/versions?disk=local&path=${f.name}`);
  expect(JSON.stringify(versions)).toBeTruthy();

  await cardByName(fm, f.name).click();
  await fm.getByRole('button', { name: 'Version history', exact: true }).click();
  const modal = fm.locator('.ff-versions-modal');
  await expect(modal.locator('tbody tr')).toHaveCount(2, { timeout: 15_000 });   // v1 and v2 (v3 is current)
  page.once('dialog', (d) => d.accept());
  await modal.locator('tbody tr').last().getByRole('button', { name: 'Restore' }).click();   // oldest = v1
  await expect(fm.getByText('Version restored')).toBeVisible();
  const now = await api(frame, 'GET', `/api/fm/content?disk=local&path=${f.name}`);
  expect((now as { content: string }).content).toBe('v1\n');
});

test('webhooks: proxied writes (upload, mkdir, trash) and webhooks/test each POST a signed event', async ({ page }) => {
  await fetch(`${HOOKS}/events`, { method: 'DELETE' });
  const user = `lhook-${Date.now()}`;
  const secret = 'live-hook-secret';
  const { fm, frame } = await openHost(page, user, {
    allow_webhooks: true, webhook_url: 'http://ff-hooks:8193/hook', webhook_secret: secret, prefix: user,
  });
  const f = pngFile(`hooked-${Date.now()}.png`);
  await uploadAndSee(fm, f);
  await api(frame, 'POST', '/api/fm/mkdir', { disk: 'local', path: 'hooked-dir' });
  await api(frame, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
  const ping = await api(frame, 'POST', '/api/fm/webhooks/test', {});
  expect((ping as { sent: boolean }).sent).toBe(true);

  await expect.poll(async () => (await hookEvents()).length, { timeout: 15_000 }).toBeGreaterThanOrEqual(4);
  const parsed = (await hookEvents()).map((e) => ({ ...e, json: JSON.parse(e.body) }));
  const names = parsed.map((e) => e.json.event);
  expect(names).toEqual(expect.arrayContaining(['upload', 'mkdir', 'trash', 'ping']));
  for (const e of parsed) {
    expect(e.headers['x-fluxfiles-signature']).toBe('sha256=' + createHmac('sha256', secret).update(e.body).digest('hex'));
  }
  // The proxy scopes the path like core does: the tenant prefix is in the event.
  const up = parsed.find((e) => e.json.event === 'upload')!;
  expect(JSON.stringify(up.json)).toContain(`${user}/${f.name}`);
});

test('legal hold: place via the UI → trash refused (and audited) → hold/status + hold/list → release → trash works', async ({ page }) => {
  const user = `lhold-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_legal_hold: true, prefix: user, perms: ['read', 'write', 'delete', 'audit'] });
  const f = pngFile(`evidence-${Date.now()}.png`);
  await uploadAndSee(fm, f);

  await cardByName(fm, f.name).click();
  await fm.getByRole('button', { name: 'Legal Hold', exact: true }).click();
  await fm.getByRole('button', { name: 'Place legal hold…' }).click();
  await fm.getByPlaceholder('Why is this item being held? (required)').fill('Litigation 2026-117');
  await fm.getByRole('button', { name: 'Place legal hold…' }).click();
  await expect(cardByName(fm, f.name).locator('.ff-hold-badge')).toBeVisible({ timeout: 15_000 });
  await page.keyboard.press('Escape');

  const refused = await raw(frame, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
  expect(refused.status).toBe(403);
  expect(refused.json.error_code).toBe('legal_hold_active');

  const status = await api(frame, 'GET', `/api/fm/hold/status?disk=local&path=${encodeURIComponent(f.name)}`);
  expect(status).toMatchObject({ on_hold: true, reason: 'Litigation 2026-117' });
  const holds = await api(frame, 'GET', '/api/fm/hold/list?disk=local');
  expect(JSON.stringify(holds)).toContain(f.name);

  await cardByName(fm, f.name).click();
  await fm.getByRole('button', { name: 'Legal Hold', exact: true }).click();
  await fm.getByRole('button', { name: 'Release hold…' }).click();
  const reason = fm.getByPlaceholder('Why is this item being held? (required)');
  if (await reason.isVisible()) await reason.fill('Case closed');
  page.once('dialog', (d) => d.accept());
  await fm.getByRole('button', { name: 'Release hold…' }).last().click();
  await expect(cardByName(fm, f.name).locator('.ff-hold-badge')).toBeHidden({ timeout: 15_000 });
  await api(frame, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
});

test('legal hold: a refused trash on a held file is written to the audit log as legal_hold_blocked (core parity)', async ({ page }) => {
  const user = `lholdaudit-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, {
    allow_legal_hold: true, allow_audit_export: true, prefix: user, perms: ['read', 'write', 'delete', 'audit'],
  });
  const f = textFile(`held-${Date.now()}.txt`, 'keep\n');
  await uploadAndSee(fm, f);
  await api(frame, 'POST', '/api/fm/hold', { disk: 'local', path: f.name, reason: 'Audit parity' });
  const refused = await raw(frame, 'POST', '/api/fm/trash', { disk: 'local', path: f.name });
  expect(refused.json.error_code).toBe('legal_hold_active');
  const rows = await auditNdjson(frame);
  const blocked = rows.filter((r) => r.action === 'legal_hold_blocked');
  expect(blocked.length).toBe(1);
  expect(JSON.stringify(blocked[0])).toContain(`${user}/${f.name}`);   // scoped key, like every proxy audit line
});

test('retention/run: dry run reports, apply trashes the stale file, the held stale file is never touched', async ({ page }) => {
  const user = `lret-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, {
    allow_legal_hold: true, prefix: user, perms: ['read', 'write', 'delete', 'audit'],
    retention_rules: [{ path: '', days: 7, action: 'trash' }],
  });
  const stale = textFile('stale.txt', 'old\n');
  const held = textFile('held-stale.txt', 'old but held\n');
  const fresh = textFile('fresh.txt', 'new\n');
  for (const f of [stale, held, fresh]) await uploadAndSee(fm, f);
  inLaravel('touch', '-d', '30 days ago', `${LOCAL_ROOT}/${user}/stale.txt`, `${LOCAL_ROOT}/${user}/held-stale.txt`);
  await api(frame, 'POST', '/api/fm/hold', { disk: 'local', path: held.name, reason: 'Retention must lose' });

  const dry = await api(frame, 'POST', '/api/fm/retention/run', { disk: 'local' }) as Record<string, any>;
  expect(dry.dry_run).toBe(true);
  expect(dry.held).toBe(1);
  const names = await api(frame, 'GET', '/api/fm/list?disk=local&path=&limit=500');
  expect((names as { items: Array<{ name: string }> }).items.map((i) => i.name)).toEqual(expect.arrayContaining(['stale.txt', 'held-stale.txt', 'fresh.txt']));

  const applied = await api(frame, 'POST', '/api/fm/retention/run', { disk: 'local', dry_run: false }) as Record<string, any>;
  expect(applied.dry_run).toBeFalsy();
  expect(applied.acted).toBe(1);
  expect(applied.held).toBe(1);
  const after = ((await api(frame, 'GET', '/api/fm/list?disk=local&path=&limit=500')) as { items: Array<{ name: string }> }).items.map((i) => i.name);
  expect(after).not.toContain('stale.txt');
  expect(after).toEqual(expect.arrayContaining(['held-stale.txt', 'fresh.txt']));
});

test('audit export: CSV download lists our upload; the HMAC chain verifies; fixity baselines', async ({ page }) => {
  const user = `laudit-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_audit_export: true, perms: ['read', 'write', 'audit'] });
  const f = pngFile(`audited-${Date.now()}.png`);
  await uploadAndSee(fm, f);

  await fm.getByRole('button', { name: 'Activity' }).first().click();
  const [dl] = await Promise.all([page.waitForEvent('download'), fm.getByRole('button', { name: 'Export CSV' }).click()]);
  const csv = await fs.readFile((await dl.path())!, 'utf8');
  expect(csv.split('\n')[0]).toMatch(/action/);
  expect(csv).toContain(f.name);
  expect(csv).toContain(user);

  await fm.getByRole('button', { name: 'Verify log' }).click();
  await expect(fm.locator('.ff-integrity')).toContainText('Log intact', { timeout: 20_000 });
  await fm.getByRole('button', { name: 'Integrity check' }).click();
  await expect(fm.locator('.ff-integrity')).not.toContainText('error', { timeout: 30_000 });
  await expect(fm.locator('.ff-integrity')).toContainText(/checked|baseline|file/i);

  const rows = await auditNdjson(frame);
  expect(rows.some((r) => r.action === 'upload' && JSON.stringify(r).includes(f.name))).toBe(true);
  const verify = await api(frame, 'GET', '/api/fm/audit/verify?disk=local');
  expect(JSON.stringify(verify)).toMatch(/"(ok|valid|intact)":true/);
});

test('audit purge: refused for a prefix-scoped token, allowed for an unscoped admin with an explicit cutoff', async ({ page }) => {
  const user = `lpurge-${Date.now()}`;
  let { frame } = await openHost(page, user, { allow_audit_export: true, prefix: user, perms: ['read', 'write', 'audit'] });
  const scoped = await raw(frame, 'POST', '/api/fm/audit/purge', { disk: 'local', before: 946684800 });
  expect(scoped.status).toBe(403);

  ({ frame } = await openHost(page, `${user}-admin`, { allow_audit_export: true, perms: ['read', 'write', 'audit'] }));
  const noCutoff = await raw(frame, 'POST', '/api/fm/audit/purge', { disk: 'local' });
  expect(noCutoff.status).toBe(400);
  expect(noCutoff.json.error_code).toBe('audit_purge_no_cutoff');
  const ok = await raw(frame, 'POST', '/api/fm/audit/purge', { disk: 'local', before: 946684800 });   // 2000-01-01: purges nothing real
  expect(ok.status).toBe(200);
});

test('audit export without the claim: same JSON error envelope as every other proxied route', async ({ page }) => {
  const user = `lnoexport-${Date.now()}`;
  const { frame } = await openHost(page, user, { perms: ['read', 'write', 'audit'] });
  const r = await raw(frame, 'GET', '/api/fm/audit/export?format=ndjson');
  expect(r.status).toBe(403);
  expect(r.json).toMatchObject({ data: null, error_code: 'allow_audit_export_forbidden' });
});
