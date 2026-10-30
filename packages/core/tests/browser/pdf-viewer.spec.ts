import { test, expect, Page } from '@playwright/test';
import { cardByName, mintToken, mintTokenWithClaims, openManager, uploadFile } from './helpers';

// Full-window PDF viewer: the detail panel's thumbnail-sized <embed> gets an
// "Expand PDF" button that opens the same PDF in an overlay filling the window,
// with full screen / new tab / download / close. Touch devices open a new tab.

const PDF = Buffer.from(
  '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n'
  + '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
  + '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n'
  + 'trailer<</Root 1 0 R>>\n%%EOF\n'
);

async function openPdfDetail(page: Page, name: string) {
  await uploadFile(page, { name, mimeType: 'application/pdf', buffer: PDF });
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await expect(page.locator('.ff-pdf-expand')).toBeVisible({ timeout: 10_000 });
}

test('expand opens a full-window viewer with full screen, new tab, download and close', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `doc-${Date.now()}.pdf`;
  await openPdfDetail(page, name);

  await page.evaluate(() => {
    (window as any).__pdf = { fs: 0, opened: [] as string[] };
    Element.prototype.requestFullscreen = function () {
      (window as any).__pdf.fs++;
      (window as any).__pdf.fsClass = (this as Element).className;
      return Promise.resolve();
    };
    window.open = ((url: string) => { (window as any).__pdf.opened.push(url); return null; }) as typeof window.open;
  });

  await page.locator('.ff-pdf-expand').click();
  const viewer = page.locator('.ff-pdf-viewer');
  await expect(viewer).toBeVisible();
  await expect(viewer.locator('.ff-pdf-viewer-name')).toHaveText(name);
  // The viewer fills the window and shows the same file.
  const box = await viewer.boundingBox();
  const vp = page.viewportSize()!;
  expect(box!.width).toBeGreaterThanOrEqual(vp.width - 1);
  expect(box!.height).toBeGreaterThanOrEqual(vp.height - 1);
  await expect(viewer.locator('embed.ff-pdf-viewer-doc')).toHaveAttribute('src', new RegExp(name.replace('.', '\\.')));

  // Full screen targets the viewer itself, not the whole app.
  await viewer.getByRole('button', { name: 'Full screen' }).click();
  expect(await page.evaluate(() => (window as any).__pdf.fs)).toBe(1);
  expect(await page.evaluate(() => (window as any).__pdf.fsClass)).toContain('ff-pdf-viewer');

  await viewer.getByRole('button', { name: 'Open in new tab' }).click();
  expect((await page.evaluate(() => (window as any).__pdf.opened))[0]).toContain(name);

  await expect(viewer.getByRole('button', { name: 'Download' })).toBeVisible();

  await viewer.getByRole('button', { name: 'Close' }).click();
  await expect(viewer).toHaveCount(0);
});

test('a token that cannot download gets no PDF preview, so no viewer either', async ({ page }) => {
  // A browser PDF viewer can save the file, so viewing it is downloading it: with
  // allow_download=false the listing carries no URL and nothing is embedded.
  await openManager(page, mintTokenWithClaims({ allow_download: false }));
  const name = `nodl-${Date.now()}.pdf`;
  await uploadFile(page, { name, mimeType: 'application/pdf', buffer: PDF });
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await expect(page.locator('.detail-thumb')).toBeVisible();
  await expect(page.locator('.ff-pdf-expand')).toHaveCount(0);
  await expect(page.locator('.detail-thumb embed')).toHaveCount(0);
});

test('touch devices open the PDF in a new tab instead of the overlay', async ({ page }) => {
  await openManager(page, mintToken());
  const name = `touch-${Date.now()}.pdf`;
  await openPdfDetail(page, name);
  await page.evaluate(() => {
    (window as any).__opened = [] as string[];
    window.open = ((url: string) => { (window as any).__opened.push(url); return null; }) as typeof window.open;
    const real = window.matchMedia.bind(window);
    window.matchMedia = ((q: string) => (q === '(pointer: coarse)'
      ? ({ matches: true, media: q } as MediaQueryList) : real(q))) as typeof window.matchMedia;
  });
  await page.locator('.ff-pdf-expand').click();
  await expect.poll(() => page.evaluate(() => (window as any).__opened.length)).toBe(1);
  expect((await page.evaluate(() => (window as any).__opened))[0]).toContain(name);
  await expect(page.locator('.ff-pdf-viewer')).toHaveCount(0);
});
