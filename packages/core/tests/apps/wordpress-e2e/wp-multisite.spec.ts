import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Multisite network activation on a REAL network (wp-env "multisite": true, Docker):
//   bash multisite/setup.sh && npx playwright test --config wordpress-e2e/playwright.multisite.config.ts
// FluxFiles is network-activated with sub-site 2 created BEFORE and sub-site 3 AFTER.
// Before the fix only the main site was initialised, so every sub-site failed with
// "Unable to generate token".
const BASE = 'http://localhost:8890';
const CWD = join(dirname(fileURLToPath(import.meta.url)), 'multisite');
const wp = (...args: string[]) =>
  execFileSync('npx', ['--yes', '@wordpress/env', 'run', 'cli', 'wp', ...args], { cwd: CWD }).toString().trim();

async function loginAdmin(page: Page, path: string) {
  await page.goto(`${BASE}${path}/wp-login.php`, { waitUntil: 'load' });
  // wp-login's own script moves focus back to #user_login after load, which can send
  // typed keys into the wrong field; set both values directly instead of typing.
  await page.waitForTimeout(300);
  await page.evaluate(() => {
    (document.getElementById('user_login') as HTMLInputElement).value = 'admin';
    (document.getElementById('user_pass') as HTMLInputElement).value = 'password';
  });
  await expect(page.locator('#user_login')).toHaveValue('admin');
  await expect(page.locator('#user_pass')).toHaveValue('password');
  await Promise.all([page.waitForURL(/wp-admin/, { waitUntil: 'domcontentloaded' }), page.click('#wp-submit')]);
}

for (const [blog, slug] of [[2, 'before'], [3, 'after']] as const) {
  test(`multisite: sub-site ${blog} (${slug} network activation) has a secret and mints a token`, async ({ page }) => {
    test.setTimeout(120_000); // each wp-cli call goes through wp-env/docker (3-12 s)
    expect(wp('option', 'get', 'fluxfiles_secret', `--url=localhost:8890/${slug}`).length).toBeGreaterThanOrEqual(32);
    expect(wp('option', 'get', 'fluxfiles_ttl', `--url=localhost:8890/${slug}`)).toBe('3600');

    const url = `--url=localhost:8890/${slug}`;
    if (!/\d/.test(wp('post', 'list', '--post_type=page', '--name=files', '--field=ID', '--format=ids', url))) {
      wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Files', '--post_name=files',
        '--post_content=[fluxfiles disk="local" mode="browser" height="400px"]', url);
    }
    await loginAdmin(page, `/${slug}`);
    await page.goto(`${BASE}/${slug}/files/`);
    await expect(page.locator('.fluxfiles-error')).toHaveCount(0);
    await expect(page.frameLocator('iframe').locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  });
}

// ---- Per-site isolation (settings, secrets, storage) on the same network ----
import { cardByName, uploadFile, pngFile } from '../e2e/helpers';

test('multisite: every site has its own JWT secret', async () => {
  test.setTimeout(120_000);
  const s = ['', '/before', '/after'].map(p => wp('option', 'get', 'fluxfiles_secret', `--url=localhost:8890${p}`));
  expect(new Set(s).size).toBe(3);
  for (const v of s) expect(v.length).toBeGreaterThanOrEqual(32);
});

test('multisite: the redesigned settings page works on a sub-site and saves only that site', async ({ page }) => {
  test.setTimeout(180_000);
  const before = wp('option', 'get', 'fluxfiles_max_upload', '--url=localhost:8890/after');
  await loginAdmin(page, '/before');
  await page.goto(`${BASE}/before/wp-admin/options-general.php?page=fluxfiles`);
  await expect(page.locator('.ff-tab')).toHaveCount(7);
  await expect(page.locator('.ff-status-item').first()).toContainText('JWT secret');
  await expect(page.locator('.ff-status-item').first()).toHaveAttribute('data-state', 'ok');
  await page.fill('#fluxfiles_max_upload', '7');
  await page.click('#submit');
  await expect(page.locator('.notice-success')).toContainText('Settings saved');
  try {
    expect(wp('option', 'get', 'fluxfiles_max_upload', '--url=localhost:8890/before')).toBe('7');
    expect(wp('option', 'get', 'fluxfiles_max_upload', '--url=localhost:8890/after')).toBe(before);
    expect(wp('option', 'get', 'fluxfiles_max_upload', '--url=localhost:8890')).not.toBe('7');
  } finally {
    wp('option', 'update', 'fluxfiles_max_upload', '10', '--url=localhost:8890/before');
  }
});

test('multisite: a file uploaded on one sub-site is not visible on another', async ({ page }) => {
  test.setTimeout(180_000);
  await loginAdmin(page, '/before');
  await page.goto(`${BASE}/before/files/`);
  const fm = page.frameLocator('iframe');
  await expect(fm.locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  const name = `ms-iso-${Date.now()}.png`;
  await uploadFile(fm, pngFile(name));
  await expect(cardByName(fm, name)).toBeVisible({ timeout: 15_000 });

  await page.goto(`${BASE}/after/files/`);
  const fm3 = page.frameLocator('iframe');
  await expect(fm3.locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  await page.waitForTimeout(1500);
  await expect(cardByName(fm3, name)).toHaveCount(0);

  // On disk it lives under this site's own folder.
  const where = execFileSync('npx', ['--yes', '@wordpress/env', 'run', 'cli', 'sh', '-c',
    `find /var/www/html/wp-content/uploads -name '${name}' -not -path '*/_variants/*'`], { cwd: CWD }).toString();
  expect(where).toMatch(/sites\/2\//);
  expect(where).not.toMatch(/sites\/3\//);
});
