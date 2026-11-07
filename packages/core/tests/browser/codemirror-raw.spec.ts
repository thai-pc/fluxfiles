import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager } from './helpers';

// Regression: the CodeMirror instance must NOT live in Alpine reactive state.
// Alpine wraps it in a Proxy, and setValue/getValue through the proxy corrupted
// the document, so typing mangled text and Save wrote the wrong bytes. These
// tests use the REAL CodeMirror (not the textarea fallback) against the real API.

async function openFile(page: import('@playwright/test').Page, name: string, initial: string) {
  const token = mintTokenWithClaims({ allow_code_edit: true });
  await openManager(page, token);
  // Seed the file through the real API.
  await page.evaluate(async ({ name, initial }) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    await c.api('PUT', '/api/fm/content', { disk: 'local', path: name, content: initial }).catch(async () => {
      const fd = new FormData();
      fd.append('disk', 'local'); fd.append('path', '');
      fd.append('file', new Blob([initial], { type: 'text/plain' }), name);
      await c.api('POST', '/api/fm/upload', fd);
    });
    await c.openEditor({ name, key: name, type: 'file' });
  }, { name, initial });
  await expect(page.locator('.ff-editor-modal .CodeMirror')).toBeVisible();
}

const cmValue = (page: import('@playwright/test').Page) =>
  page.evaluate(() => (document.querySelector('.ff-editor-modal .CodeMirror') as any).CodeMirror.getValue());
const diskContent = (page: import('@playwright/test').Page, name: string) =>
  page.evaluate(async (name) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    return (await c.api('GET', '/api/fm/content?disk=local&path=' + encodeURIComponent(name))).content;
  }, name);

test('code editor: type, undo, save and reload are byte-exact', async ({ page }) => {
  const name = `cm-raw-${Date.now()}.conf`;
  await openFile(page, name, 'alpha\nbeta\n');
  expect(await cmValue(page)).toBe('alpha\nbeta\n');

  const cm = page.locator('.ff-editor-modal .CodeMirror');
  await cm.click();
  await page.keyboard.press('Control+End');
  await page.keyboard.type('gamma {\n  delta;\n}\n', { delay: 0 });
  const typed = 'alpha\nbeta\ngamma {\n  delta;\n}\n';
  // CM auto-indent may alter whitespace, so compare against the editor's own value.
  const live = await cmValue(page);
  expect(live.startsWith('alpha\nbeta\ngamma {')).toBe(true);
  const c = await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).editorContent);
  expect(c).toBe(live);

  // Undo must change the doc and keep state in sync.
  await page.evaluate(() => (document.querySelector('.ff-editor-modal .CodeMirror') as any).CodeMirror.undo());
  const afterUndo = await cmValue(page);
  expect(afterUndo.length).toBeLessThan(live.length);
  expect(await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).editorContent)).toBe(afterUndo);
  await page.evaluate(() => (document.querySelector('.ff-editor-modal .CodeMirror') as any).CodeMirror.redo());
  expect(await cmValue(page)).toBe(live);
  void typed;

  await page.locator('.ff-editor-modal').getByRole('button', { name: 'Save' }).click();
  await expect.poll(() => diskContent(page, name)).toBe(live);

  // Reopen (reuses the cached instance via setValue) and compare.
  await page.evaluate(async (name) => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    c.editorContent = c.editorOriginal; // not dirty, no confirm
    c.closeEditor();
    await c.openEditor({ name, key: name, type: 'file' });
  }, name);
  await expect.poll(() => cmValue(page)).toBe(live);
});

test('code editor: the CodeMirror instance is not reactive state', async ({ page }) => {
  await openFile(page, `cm-raw2-${Date.now()}.conf`, 'x\n');
  const isProxyFree = await page.evaluate(() => {
    const A = (window as any).Alpine;
    const c = A.$data(document.querySelector('.ff-app'));
    const cm = (document.querySelector('.ff-editor-modal .CodeMirror') as any).CodeMirror;
    return c._cm === undefined || c._cm === cm || A.raw(c._cm) === c._cm;
  });
  expect(isProxyFree).toBe(true);
});
