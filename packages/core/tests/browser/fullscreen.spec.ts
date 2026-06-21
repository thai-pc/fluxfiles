import { test, expect } from '@playwright/test';
import { mintToken, openManager } from './helpers';

// Whole-app fullscreen toggle (the "YouTube fullscreen" button). The real
// Fullscreen API needs a user gesture and is flaky headless, so we stub
// request/exitFullscreen to record calls + drive the fullscreenchange event,
// and assert the button wiring + icon/state.

test('fullscreen toggle: button enters/exits and reflects state', async ({ page }) => {
  await openManager(page, mintToken());

  await page.evaluate(() => {
    (window as any).__fs = { req: 0, exit: 0 };
    const setEl = (el: Element | null) =>
      Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => el });
    setEl(null);
    document.documentElement.requestFullscreen = function () {
      (window as any).__fs.req++; setEl(document.documentElement);
      document.dispatchEvent(new Event('fullscreenchange'));
      return Promise.resolve();
    };
    document.exitFullscreen = function () {
      (window as any).__fs.exit++; setEl(null);
      document.dispatchEvent(new Event('fullscreenchange'));
      return Promise.resolve();
    };
  });

  const btn = page.getByRole('button', { name: 'Fullscreen', exact: true });
  await expect(btn).toBeVisible();
  const state = () => page.evaluate(() => (window as any).Alpine.$data(document.querySelector('.ff-app')).isFullscreen);

  // enter
  await btn.click();
  expect(await page.evaluate(() => (window as any).__fs.req)).toBe(1);
  expect(await state()).toBe(true);

  // exit
  await btn.click();
  expect(await page.evaluate(() => (window as any).__fs.exit)).toBe(1);
  expect(await state()).toBe(false);
});

test('fullscreen: a blocked request (rejected promise) surfaces a toast', async ({ page }) => {
  await openManager(page, mintToken());
  await page.evaluate(() => {
    Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => null });
    document.documentElement.requestFullscreen = () => Promise.reject(new Error('blocked'));
  });
  await page.getByRole('button', { name: 'Fullscreen', exact: true }).click();
  await expect(page.locator('.ff-toast')).toContainText('Fullscreen');
});
