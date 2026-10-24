import { test, expect } from '@playwright/test';
import { token, openManager, uploadAndSee, cardByName, pngFile, textFile, api } from './helpers';

// Share + Intake, real modules: links are minted by the share/intake modules,
// opened as an anonymous recipient on /public/share.html and /public/intake.html,
// and the bytes really move (download / upload into the operator's folder).

test('share: create a link → anonymous recipient sees + downloads the file → revoke kills it', async ({ page, browser }) => {
  const user = `share-${Date.now()}`;
  await openManager(page, token(user, { allow_share: true, prefix: user }));
  const file = pngFile(`report-${Date.now()}.png`);
  await uploadAndSee(page, file);
  await cardByName(page, file.name).click();
  await page.getByRole('button', { name: 'Share link', exact: true }).click();
  await page.getByRole('button', { name: 'Create share link' }).click();
  const field = page.locator('[x-ref="linkUrl"]');
  await expect(field).toHaveValue(/\/public\/share\.html/);
  const url = await field.inputValue();

  // The recipient: a fresh browser context, no token, no cookies.
  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(url);
  await expect(p.locator('#name')).toContainText(file.name, { timeout: 15_000 });
  const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('#download').click()]);
  const bytes = await (await import('node:fs')).promises.readFile((await dl.path())!);
  expect(bytes.equals(file.buffer)).toBe(true);

  // Revoke from the operator side → the same link is dead.
  page.once('dialog', (d) => d.accept());
  await page.locator('.ff-links-done-close').click().catch(() => {});
  const list = await api(page, 'GET', '/api/fm/share/list?disk=local');
  const jti = (list as Array<{ jti: string; path: string }>).find((r) => r.path.endsWith(file.name))!.jti;
  await api(page, 'POST', '/api/fm/share/revoke', { disk: 'local', jti });
  await p.reload();
  await expect(p.locator('#gate, #dlerr').first()).toBeVisible({ timeout: 15_000 });
  await expect(p.locator('#download')).toBeHidden();
  await anon.close();
});

test('intake: create a portal on a folder → anonymous upload lands in that folder', async ({ page, browser }) => {
  const user = `intake-${Date.now()}`;
  await openManager(page, token(user, { allow_intake: true, prefix: user }));
  const folder = 'inbox';
  await page.getByRole('button', { name: 'New folder' }).click();
  await page.locator('[x-ref="newFolderInput"]').fill(folder);
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(cardByName(page, folder)).toBeVisible();
  await cardByName(page, folder).click();
  await page.getByRole('button', { name: 'Upload portal', exact: true }).click();
  await page.getByRole('button', { name: 'Create upload portal' }).click();
  const url = await page.locator('[x-ref="linkUrl"]').inputValue();
  expect(url).toMatch(/\/public\/intake\.html/);

  const anon = await browser.newContext();
  const p = await anon.newPage();
  await p.goto(url);
  const sent = textFile(`from-client-${Date.now()}.txt`, 'hello from a client\n');
  await p.locator('#file').setInputFiles(sent);
  await expect(p.locator('.st-ok')).toHaveText('✓', { timeout: 20_000 });
  await anon.close();

  // The operator sees it in the folder, with the client's bytes.
  const content = await api(page, 'GET', `/api/fm/content?disk=local&path=${encodeURIComponent(`${folder}/${sent.name}`)}`);
  expect((content as { content: string }).content).toBe('hello from a client\n');
  await openManager(page, token(user, { allow_intake: true, prefix: user }));   // ?token= is stripped on boot, so no reload
  await cardByName(page, folder).dblclick();
  await expect(cardByName(page, sent.name)).toBeVisible({ timeout: 15_000 });
});
