import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TEST_SECRET } from './secret';

const coreDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

function mintToken(): string {
  const php =
    `require '${coreDir}/embed.php';` +
    `$_ENV['FLUXFILES_SECRET']='${TEST_SECRET}';` +
    `echo fluxfiles_token('e2e-user', ['read','write','delete'], ['local'], '', 50, null, 86400);`;
  // execFileSync → no shell, so $_ENV is not interpolated away.
  return execFileSync('php', ['-r', php]).toString().trim();
}

test('UI assets are cache-busted with a content-hash ?v= and the HTML is no-cache', async ({ page }) => {
  const token = mintToken();
  const resp = await page.goto(`/public/index.html?token=${token}&disk=local`);
  // The dynamic HTML must not be cached, or it would keep the old ?v= URLs.
  expect((resp?.headers()['cache-control'] || '')).toContain('no-cache');
  // fm.js / fm.css are referenced with a version query so a core update invalidates them.
  const versioned = await page.evaluate(() => ({
    js: document.querySelector('script[src*="/fm."]')?.getAttribute('src') || '',
    css: document.querySelector('link[href*="/fm."]')?.getAttribute('href') || '',
  }));
  expect(versioned.js).toMatch(/fm(\.min)?\.js\?v=[a-f0-9]+/);
  expect(versioned.css).toMatch(/fm(\.min)?\.css\?v=[a-f0-9]+/);
});

test('valid token → file manager UI renders (no auth screen)', async ({ page }) => {
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('.ff-search-box').first()).toBeVisible();
  await expect(page.locator('.ff-auth-screen')).toBeHidden();
});

test('no token → authentication screen is shown', async ({ page }) => {
  await page.goto('/public/index.html');
  await expect(page.locator('.ff-auth-screen')).toBeVisible({ timeout: 10_000 });
});

test('the server sets <html lang/dir> for the requested locale (and keeps translate="no")', async ({ page }) => {
  await page.goto(`/public/index.html?token=${mintToken()}&disk=local&lang=ar`);
  const html = page.locator('html');
  await expect(html).toHaveAttribute('lang', 'ar');
  await expect(html).toHaveAttribute('dir', 'rtl');
  await expect(html).toHaveAttribute('translate', 'no');
});

test('page loads the minified bundle + vendored Alpine, with no third-party script', async ({ page }) => {
  const hosts = new Set<string>();
  page.on('request', (r) => hosts.add(new URL(r.url()).host));
  const token = mintToken();
  await page.goto(`/public/index.html?token=${token}&disk=local`);
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 10_000 });
  const srcs = await page.evaluate(() => Array.from(document.scripts).map((s) => s.getAttribute('src') || ''));
  expect(srcs.some((s) => /assets\/fm\.min\.js\?v=/.test(s))).toBe(true);
  expect(srcs.some((s) => /assets\/vendor\/alpine\/alpine\.min\.js\?v=/.test(s))).toBe(true);
  // fm.js is defined before Alpine starts (both deferred, in order).
  expect(srcs.findIndex((s) => /fm\.min\.js/.test(s))).toBeLessThan(srcs.findIndex((s) => /alpine/.test(s)));
  expect([...hosts].filter((h) => !h.startsWith('127.0.0.1'))).toEqual([]);
});
