import { test, expect, Page } from '@playwright/test';
import { mintToken } from './helpers';
import { CORE_ENDPOINT, REACT_PORT, VUE_PORT } from './secret';

// The modal CHROME (window + header) must follow the `theme` prop, not just the
// iframe content — verified against the REAL React + Vue wrappers. dark → #2b2b2e
// (rgb(43,43,46)); light → #f5f5f7 (rgb(245,245,247)). The header sits just above
// the iframe, so it's the iframe's previous sibling.
async function chromeHeaderBg(page: Page, port: number, theme: 'dark' | 'light') {
  const url = `http://127.0.0.1:${port}/?ui=modal&theme=${theme}&token=${encodeURIComponent(mintToken())}&endpoint=${encodeURIComponent(CORE_ENDPOINT)}`;
  await page.goto(url);
  await expect(page.getByTestId('ready-flag')).toHaveText('READY', { timeout: 30_000 });
  return page.evaluate(() => {
    const iframe = document.querySelector('iframe')!;
    const header = iframe.parentElement!.previousElementSibling as HTMLElement;
    return getComputedStyle(header).backgroundColor;
  });
}

test('react modal chrome is dark under theme=dark', async ({ page }) => {
  expect(await chromeHeaderBg(page, REACT_PORT, 'dark')).toBe('rgb(43, 43, 46)');
});

test('react modal chrome is light under theme=light', async ({ page }) => {
  expect(await chromeHeaderBg(page, REACT_PORT, 'light')).toBe('rgb(245, 245, 247)');
});

test('vue modal chrome is dark under theme=dark', async ({ page }) => {
  expect(await chromeHeaderBg(page, VUE_PORT, 'dark')).toBe('rgb(43, 43, 46)');
});

test('vue modal chrome is light under theme=light', async ({ page }) => {
  expect(await chromeHeaderBg(page, VUE_PORT, 'light')).toBe('rgb(245, 245, 247)');
});

// theme=auto must follow the OS prefers-color-scheme (not hardcode a side).
async function autoChromeBg(page: Page, port: number) {
  const url = `http://127.0.0.1:${port}/?ui=modal&theme=auto&token=${encodeURIComponent(mintToken())}&endpoint=${encodeURIComponent(CORE_ENDPOINT)}`;
  await page.goto(url);
  await expect(page.getByTestId('ready-flag')).toHaveText('READY', { timeout: 30_000 });
  return page.evaluate(() => {
    const iframe = document.querySelector('iframe')!;
    return getComputedStyle(iframe.parentElement!.previousElementSibling as HTMLElement).backgroundColor;
  });
}

test('react modal chrome follows OS scheme when theme=auto', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  expect(await autoChromeBg(page, REACT_PORT)).toBe('rgb(43, 43, 46)');
  await page.emulateMedia({ colorScheme: 'light' });
  expect(await autoChromeBg(page, REACT_PORT)).toBe('rgb(245, 245, 247)');
});

test('vue modal chrome follows OS scheme when theme=auto', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  expect(await autoChromeBg(page, VUE_PORT)).toBe('rgb(43, 43, 46)');
  await page.emulateMedia({ colorScheme: 'light' });
  expect(await autoChromeBg(page, VUE_PORT)).toBe('rgb(245, 245, 247)');
});
