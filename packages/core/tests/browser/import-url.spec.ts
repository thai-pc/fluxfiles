import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager } from './helpers';

// Import-from-URL validation: empty/whitespace must not submit; a malformed URL
// must surface a visible error (real backend — no mock).

async function openImport(page: import('@playwright/test').Page) {
  await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).openUrlImport());
  await expect(page.locator('.ff-import-input')).toBeVisible({ timeout: 10_000 });
}

test('empty / whitespace URL → Import button stays disabled', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_url_import: true }));
  await openImport(page);
  const btn = page.getByRole('button', { name: 'Import', exact: true });
  await expect(btn).toBeDisabled();                 // empty
  await page.locator('.ff-import-input').fill('   ');
  await expect(btn).toBeDisabled();                 // whitespace-only
  await page.locator('.ff-import-input').fill('https://x');
  await expect(btn).toBeEnabled();                  // has content
});

test('malformed URL → submit shows a validation error (real)', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_url_import: true }));
  await openImport(page);
  await page.locator('.ff-import-input').fill('notaurl');
  await page.getByRole('button', { name: 'Import', exact: true }).click();
  // Backend rejects → UI flips to error state and shows a non-empty message.
  const err = page.locator('.ff-import-error');
  await expect(err).toBeVisible({ timeout: 15_000 });
  await expect(err).not.toHaveText('');
  // Button relabels to "Try again" in the error state.
  await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
});

test('Enter key on empty input does not submit (no error, stays input)', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_url_import: true }));
  await openImport(page);
  await page.locator('.ff-import-input').press('Enter');
  await expect(page.locator('.ff-import-error')).toBeHidden(); // x-show false (no error)
  const state = await page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).urlImportState);
  expect(state).toBe('input');
});
