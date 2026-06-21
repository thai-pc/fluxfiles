import { test, expect } from '@playwright/test';
import { mintToken, mintTokenWithClaims, openManager } from './helpers';

// Config/code editor modal (M2). The read/write *logic* is covered by the PHP
// integration + SFTP HTTP e2e; here we verify the UI: the Edit button gate
// (allow_code_edit + text file), and that the modal loads content and PUTs the
// edit. The /content endpoint is mocked, and CodeMirror's CDN is blocked so the
// bound <textarea> fallback drives the test deterministically.

async function blockCodeMirror(page: import('@playwright/test').Page) {
  await page.route('**/cdnjs.cloudflare.com/**/codemirror/**', (route) => route.abort());
}

async function mockContent(page: import('@playwright/test').Page, initial: string) {
  let put: Record<string, unknown> | null = null;
  await page.route('**/api/fm/content*', async (route) => {
    if (route.request().method() === 'PUT') {
      put = route.request().postDataJSON();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { path: 'x', size: String(put.content).length } }) });
    } else {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { path: 'x', content: initial, size: initial.length } }) });
    }
  });
  return () => put;
}

test('canEditFile: needs allow_code_edit AND a text file', async ({ page }) => {
  // Default token → allow_code_edit unset (false).
  await openManager(page, mintToken());
  const off = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return c.canEditFile({ name: 'app.conf', key: 'app.conf', type: 'file' });
  });
  expect(off).toBe(false);

  // allow_code_edit=true → text files editable, images/dirs not.
  await openManager(page, mintTokenWithClaims({ allow_code_edit: true }));
  const r = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return {
      conf: c.canEditFile({ name: 'nginx.conf', key: 'nginx.conf', type: 'file' }),
      env: c.canEditFile({ name: '.env', key: '.env', type: 'file' }),
      php: c.canEditFile({ name: 'wp-config.php', key: 'wp-config.php', type: 'file' }),
      dockerfile: c.canEditFile({ name: 'Dockerfile', key: 'Dockerfile', type: 'file' }),
      png: c.canEditFile({ name: 'photo.png', key: 'photo.png', type: 'file' }),
      dir: c.canEditFile({ name: 'folder', key: 'folder', type: 'dir' }),
    };
  });
  expect(r).toEqual({ conf: true, env: true, php: true, dockerfile: true, png: false, dir: false });
});

test('editor modal: loads content, edits, Save PUTs the new content', async ({ page }) => {
  await blockCodeMirror(page);
  await openManager(page, mintTokenWithClaims({ allow_code_edit: true }));
  const getPut = await mockContent(page, 'listen 80;\n');

  await page.evaluate(async () => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    await c.openEditor({ name: 'nginx.conf', key: 'conf/nginx.conf', type: 'file' });
  });

  const modal = page.locator('.ff-editor-modal');
  await expect(modal).toBeVisible();
  await expect(modal).toContainText('nginx.conf');

  const ta = modal.locator('#ff-editor-ta');
  await expect(ta).toHaveValue('listen 80;\n');

  // Save is disabled until the content changes.
  const save = modal.getByRole('button', { name: 'Save' });
  await expect(save).toBeDisabled();

  await ta.fill('listen 443 ssl;\n');
  await expect(save).toBeEnabled();
  await save.click();

  await expect.poll(() => getPut()).toMatchObject({ disk: 'local', path: 'conf/nginx.conf', content: 'listen 443 ssl;\n' });
  // After a successful save the editor is no longer dirty.
  await expect(save).toBeDisabled();
});

test('editor modal: load failure surfaces an error', async ({ page }) => {
  await blockCodeMirror(page);
  await openManager(page, mintTokenWithClaims({ allow_code_edit: true }));
  await page.route('**/api/fm/content*', (route) =>
    route.fulfill({ status: 413, contentType: 'application/json', body: JSON.stringify({ error: 'File is too large to edit' }) }));

  await page.evaluate(async () => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    await c.openEditor({ name: 'big.log', key: 'big.log', type: 'file' });
  });

  const modal = page.locator('.ff-editor-modal');
  await expect(modal).toBeVisible();
  await expect(modal.locator('.ff-new-folder-error')).toContainText('too large');
});
