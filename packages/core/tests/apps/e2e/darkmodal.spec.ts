import { test, expect } from '@playwright/test';
import { mintToken } from './helpers';
import { CORE_ENDPOINT, REACT_PORT } from './secret';

// The modal CHROME (window + header) must follow theme=dark, not just the iframe.
test('react modal chrome is dark under theme=dark', async ({ page }) => {
  const url = `http://127.0.0.1:${REACT_PORT}/?ui=modal&theme=dark&token=${encodeURIComponent(mintToken())}&endpoint=${encodeURIComponent(CORE_ENDPOINT)}`;
  await page.goto(url);
  await expect(page.getByTestId('ready-flag')).toHaveText('READY', { timeout: 30_000 });
  // The header sits just above the iframe; its background should be the dark chrome.
  const headerBg = await page.evaluate(() => {
    const iframe = document.querySelector('iframe')!;
    const header = iframe.parentElement!.previousElementSibling as HTMLElement; // header div
    return getComputedStyle(header).backgroundColor;
  });
  // #2b2b2e → rgb(43, 43, 46)
  expect(headerBg).toBe('rgb(43, 43, 46)');
});

test('react modal chrome is light under theme=light', async ({ page }) => {
  const url = `http://127.0.0.1:${REACT_PORT}/?ui=modal&theme=light&token=${encodeURIComponent(mintToken())}&endpoint=${encodeURIComponent(CORE_ENDPOINT)}`;
  await page.goto(url);
  await expect(page.getByTestId('ready-flag')).toHaveText('READY', { timeout: 30_000 });
  const headerBg = await page.evaluate(() => {
    const iframe = document.querySelector('iframe')!;
    const header = iframe.parentElement!.previousElementSibling as HTMLElement;
    return getComputedStyle(header).backgroundColor;
  });
  expect(headerBg).toBe('rgb(245, 245, 247)'); // #f5f5f7
});
