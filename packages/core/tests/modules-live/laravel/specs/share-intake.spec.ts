import { test, expect } from '@playwright/test';
import { promises as fs } from 'node:fs';
import { openHost, uploadAndSee, cardByName, pngFile, textFile, api, raw, newFolder, LARAVEL } from './helpers';

// Share + Intake through the Laravel proxy: links are minted by the real modules
// via FluxFilesController::shareIntake(), the recipient landing pages
// (/public/share.html, /public/intake.html) and their public API
// (share/info|unlock|file, intake/info|upload) are served by LARAVEL
// (publicPage()/publicLink()), never by a standalone core.

test('share: create → anonymous recipient on Laravel\'s share.html downloads the bytes → list/analytics → revoke kills it', async ({ page, browser }) => {
  const user = `lshare-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_share: true, share_analytics: true, prefix: user });
  const file = pngFile(`report-${Date.now()}.png`);
  await uploadAndSee(fm, file);
  await cardByName(fm, file.name).click();
  await fm.getByRole('button', { name: 'Share link', exact: true }).click();
  await fm.getByRole('button', { name: 'Create share link' }).click();
  const field = fm.locator('[x-ref="linkUrl"]');
  await expect(field).toHaveValue(/\/public\/share\.html\?token=/);
  const url = await field.inputValue();
  expect(url.startsWith(`${LARAVEL}/public/share.html`)).toBe(true);   // Laravel's own landing page

  const anon = await browser.newContext();
  const p = await anon.newPage();
  const apiHits: string[] = [];
  p.on('request', (r) => { if (r.url().includes('/api/fm/')) apiHits.push(r.url()); });
  const landing = await p.goto(url);
  expect(landing!.headers()['referrer-policy']).toBe('no-referrer');
  await expect(p.locator('#name')).toContainText(file.name, { timeout: 15_000 });
  const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('#download').click()]);
  const bytes = await fs.readFile((await dl.path())!);
  expect(bytes.equals(file.buffer)).toBe(true);
  // Every recipient API call went to Laravel; share/info + share/file among them.
  for (const h of apiHits) expect(h.startsWith(`${LARAVEL}/api/fm/`)).toBe(true);
  expect(apiHits.some((h) => h.includes('/api/fm/share/info'))).toBe(true);
  expect(apiHits.some((h) => h.includes('/api/fm/share/file'))).toBe(true);

  const list = await api(frame, 'GET', '/api/fm/share/list?disk=local') as Array<{ jti: string; path: string }>;
  const row = list.find((r) => r.path.endsWith(file.name));
  expect(row).toBeTruthy();
  const stats = await api(frame, 'GET', `/api/fm/share/analytics?disk=local&jti=${row!.jti}`);
  expect((stats as { analytics_enabled: boolean }).analytics_enabled).toBe(true);   // share_analytics forwarded
  expect(JSON.stringify(stats)).toMatch(/view|download/);

  await fm.locator('.ff-links-done-close').click().catch(() => {});
  await api(frame, 'POST', '/api/fm/share/revoke', { disk: 'local', jti: row!.jti });
  await p.reload();
  await expect(p.locator('#gate, #dlerr').first()).toBeVisible({ timeout: 15_000 });
  await expect(p.locator('#download')).toBeHidden();
  const token = new URL(url).searchParams.get('token')!;
  const dead = await p.request.get(`${LARAVEL}/api/fm/share/info?token=${encodeURIComponent(token)}`);
  expect(dead.status()).toBeGreaterThanOrEqual(400);
  await anon.close();
});

test('share with a password: wrong password refused, right one unlocks via Laravel\'s public share/unlock POST (no CSRF token, no session)', async ({ page, browser }) => {
  const user = `lsharepw-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_share: true, prefix: user });
  const file = textFile(`secret-${Date.now()}.txt`, 'for your eyes only\n');
  await uploadAndSee(fm, file);
  const res = await api(frame, 'POST', '/api/fm/share', { disk: 'local', path: file.name, password: 'open sesame' }) as { url: string };
  expect(res.url.startsWith(`${LARAVEL}/public/share.html?token=`)).toBe(true);

  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(res.url);
  await expect(p.locator('#pw')).toBeVisible({ timeout: 15_000 });
  await expect(p.locator('#download')).toBeHidden();
  await p.locator('#pw').fill('wrong');
  const [refused] = await Promise.all([
    p.waitForResponse((r) => r.url().startsWith(`${LARAVEL}/api/fm/share/unlock`)),
    p.locator('#unlock').click(),
  ]);
  expect(refused.status()).toBeGreaterThanOrEqual(400);
  expect(refused.status()).not.toBe(419);   // not Laravel's CSRF wall — the module said no
  await expect(p.locator('#lockmeta')).not.toBeEmpty();
  await expect(p.locator('#download')).toBeHidden();
  await p.locator('#pw').fill('open sesame');
  const [unlock] = await Promise.all([
    p.waitForResponse((r) => r.url().startsWith(`${LARAVEL}/api/fm/share/unlock`) && r.status() === 200),
    p.locator('#unlock').click(),
  ]);
  expect(unlock.ok()).toBe(true);
  const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('#download').click()]);
  expect((await fs.readFile((await dl.path())!)).toString()).toBe('for your eyes only\n');
  await anon.close();
});

test('intake: create a portal on a folder → anonymous upload via Laravel lands there → list/analytics → revoke', async ({ page, browser }) => {
  const user = `lintake-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { allow_intake: true, intake_analytics: true, prefix: user });
  await newFolder(fm, 'inbox');
  await cardByName(fm, 'inbox').click();
  await fm.getByRole('button', { name: 'Upload portal', exact: true }).click();
  await fm.getByRole('button', { name: 'Create upload portal' }).click();
  const url = await fm.locator('[x-ref="linkUrl"]').inputValue();
  expect(url.startsWith(`${LARAVEL}/public/intake.html?token=`)).toBe(true);

  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(url);
  const sent = textFile(`from-client-${Date.now()}.txt`, 'hello from a client\n');
  await p.locator('#file').setInputFiles(sent);
  await expect(p.locator('.st-ok')).toHaveText('✓', { timeout: 20_000 });

  const content = await api(frame, 'GET', `/api/fm/content?disk=local&path=${encodeURIComponent(`inbox/${sent.name}`)}`);
  expect((content as { content: string }).content).toBe('hello from a client\n');

  const portals = await api(frame, 'GET', '/api/fm/intake/list?disk=local') as Array<{ jti: string; path: string }>;
  const row = portals.find((r) => r.path.endsWith('inbox'));
  expect(row).toBeTruthy();
  const stats = await api(frame, 'GET', `/api/fm/intake/analytics?disk=local&jti=${row!.jti}`);
  expect((stats as { analytics_enabled: boolean }).analytics_enabled).toBe(true);   // intake_analytics forwarded
  expect(JSON.stringify(stats)).toContain('"type":"received"');
  expect(JSON.stringify(stats)).toContain(sent.name);

  await fm.locator('.ff-links-done-close').click().catch(() => {});
  await api(frame, 'POST', '/api/fm/intake/revoke', { disk: 'local', jti: row!.jti });
  const token = new URL(url).searchParams.get('token')!;
  const dead = await p.request.get(`${LARAVEL}/api/fm/intake/info?token=${encodeURIComponent(token)}`);
  expect(dead.status()).toBeGreaterThanOrEqual(400);
  await anon.close();
});

test('gate through the proxy: a token without allow_share is refused (403 allow_share_forbidden)', async ({ page }) => {
  const user = `lnoshare-${Date.now()}`;
  const { fm, frame } = await openHost(page, user, { prefix: user });
  const f = textFile('x.txt', 'x\n');
  await uploadAndSee(fm, f);
  const r = await raw(frame, 'POST', '/api/fm/share', { disk: 'local', path: f.name });
  expect(r.status).toBe(403);
  expect(r.json.error_code).toBe('allow_share_forbidden');
});
