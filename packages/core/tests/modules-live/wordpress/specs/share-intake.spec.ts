import { test, expect } from '@playwright/test';
import { promises as fs } from 'node:fs';
import { loginAdmin, openFiles, uploadAndSee, cardByName, pngFile, textFile, api, listNames } from './helpers';

// Share + Intake through the WordPress proxy: links minted by the real modules via
// /wp-json/fluxfiles/v1/api/fm/{share,intake}, opened as an anonymous recipient on the
// plugin's public-link.php page, which talks to the PUBLIC proxied recipient routes.

test('share: create via the WP embed → anonymous recipient downloads → revoke kills it', async ({ page, browser }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const file = pngFile(`report-${Date.now()}.png`);
  await uploadAndSee(m, file);
  await cardByName(m.fm, file.name).click();
  await m.fm.getByRole('button', { name: 'Share link', exact: true }).click();
  await m.fm.getByRole('button', { name: 'Create share link' }).click();
  const field = m.fm.locator('[x-ref="linkUrl"]');
  await expect(field).toHaveValue(/\/wp-content\/plugins\/fluxfiles\/public-link\.php\?page=share&token=/);
  const url = await field.inputValue();

  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(url);
  await expect(p.locator('#name')).toContainText(file.name, { timeout: 15_000 });
  const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('#download').click()]);
  const bytes = await fs.readFile((await dl.path())!);
  expect(bytes.equals(file.buffer)).toBe(true);

  const list = await api(m, 'GET', '/api/fm/share/list?disk=local') as Array<{ jti: string; path: string }>;
  const jti = list.find((r) => r.path.endsWith(file.name))!.jti;
  const analytics = await api(m, 'GET', `/api/fm/share/analytics?disk=local&jti=${jti}`);
  expect(analytics).toBeTruthy();
  await api(m, 'POST', '/api/fm/share/revoke', { disk: 'local', jti });
  expect((await api(m, 'GET', '/api/fm/share/list?disk=local') as Array<{ jti: string; revoked?: boolean }>)
    .find((r) => r.jti === jti && !r.revoked)).toBeFalsy();
  await p.reload();
  await expect(p.locator('#gate, #dlerr').first()).toBeVisible({ timeout: 15_000 });
  await expect(p.locator('#download')).toBeHidden();
  await anon.close();
});

test('intake: portal on a folder → anonymous upload lands there → revoke', async ({ page, browser }) => {
  await loginAdmin(page);
  let m = await openFiles(page);
  const folder = `inbox-${Date.now()}`;
  await m.fm.getByRole('button', { name: 'New folder' }).click();
  await m.fm.locator('[x-ref="newFolderInput"]').fill(folder);
  await m.fm.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(cardByName(m.fm, folder)).toBeVisible();
  await cardByName(m.fm, folder).click();
  await m.fm.getByRole('button', { name: 'Upload portal', exact: true }).click();
  await m.fm.getByRole('button', { name: 'Create upload portal' }).click();
  const url = await m.fm.locator('[x-ref="linkUrl"]').inputValue();
  expect(url).toMatch(/\/wp-content\/plugins\/fluxfiles\/public-link\.php\?page=intake&token=/);

  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(url);
  // intake.html enables the picker before /intake/info answers; a file chosen in
  // that window hits `info === null` and is silently dropped — wait for the info.
  await expect(p.locator('#constraints')).toContainText('MB', { timeout: 15_000 });
  const sent = textFile(`from-client-${Date.now()}.txt`, 'hello from a client\n');
  await p.locator('#file').setInputFiles(sent);
  await expect(p.locator('.st-ok')).toHaveText('✓', { timeout: 30_000 });

  const content = await api(m, 'GET', `/api/fm/content?disk=local&path=${encodeURIComponent(`${folder}/${sent.name}`)}`);
  expect((content as { content: string }).content).toBe('hello from a client\n');
  m = await openFiles(page);
  await cardByName(m.fm, folder).dblclick();
  await expect(cardByName(m.fm, sent.name)).toBeVisible({ timeout: 15_000 });

  const portals = await api(m, 'GET', '/api/fm/intake/list?disk=local') as Array<{ jti: string; path: string }>;
  const jti = portals.find((r) => r.path.replace(/\/$/, '').endsWith(folder))!.jti;
  expect(await api(m, 'GET', `/api/fm/intake/analytics?disk=local&jti=${jti}`)).toBeTruthy();
  await api(m, 'POST', '/api/fm/intake/revoke', { disk: 'local', jti });
  await p.reload();
  await expect(p.locator('#file')).toBeHidden({ timeout: 15_000 });
  await anon.close();
});

test('share: a password-protected link unlocks through the proxied /share/unlock, wrong password refused', async ({ page, browser }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const file = pngFile(`locked-${Date.now()}.png`);
  await uploadAndSee(m, file);
  const res = await api(m, 'POST', '/api/fm/share', { disk: 'local', path: file.name, password: 'open-sesame-42' });
  expect(String(res.url)).toContain('/wp-content/plugins/fluxfiles/public-link.php?page=share&token=');

  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(String(res.url));
  await expect(p.locator('#pw')).toBeVisible({ timeout: 15_000 });
  await p.locator('#pw').fill('wrong-password');
  const [bad] = await Promise.all([p.waitForResponse((r) => r.url().includes('/share/unlock')), p.locator('#unlock').click()]);
  expect(bad.status()).toBeGreaterThanOrEqual(400);
  await expect(p.locator('#download')).toBeHidden();
  await p.locator('#pw').fill('open-sesame-42');
  const [good] = await Promise.all([p.waitForResponse((r) => r.url().includes('/share/unlock')), p.locator('#unlock').click()]);
  expect(good.status()).toBe(200);
  await expect(p.locator('#name')).toContainText(file.name, { timeout: 15_000 });
  const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('#download').click()]);
  expect((await fs.readFile((await dl.path())!)).equals(file.buffer)).toBe(true);
  await anon.close();
});

test('intake: an anonymous EICAR upload is refused by the portal\'s ClamAV scan; a clean one lands', async ({ page, browser }) => {
  await loginAdmin(page);
  const m = await openFiles(page);
  const folder = `dropbox-${Date.now()}`;
  await api(m, 'POST', '/api/fm/mkdir', { disk: 'local', path: folder });
  const portal = await api(m, 'POST', '/api/fm/intake', { disk: 'local', path: folder });
  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(String(portal.url));
  await expect(p.locator('#constraints')).toContainText('MB', { timeout: 15_000 });
  const EICAR = ['X5O!P%@AP[4\\PZX54(P^)7CC)7}', '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'].join('');
  await p.locator('#file').setInputFiles(textFile('invoice.txt', EICAR));
  await expect(p.locator('.st-err')).toContainText(/virus|malware|infected|threat/i, { timeout: 30_000 });
  await p.locator('#file').setInputFiles(textFile('clean.txt', 'all good\n'));
  await expect(p.locator('.st-ok')).toHaveText('✓', { timeout: 30_000 });
  await anon.close();
  expect(await listNames(m, 'local', folder)).toEqual(['clean.txt']);
});
