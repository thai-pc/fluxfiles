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
  await page.goto(`${BASE}${path}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
}

for (const [blog, slug] of [[2, 'before'], [3, 'after']] as const) {
  test(`multisite: sub-site ${blog} (${slug} network activation) has a secret and mints a token`, async ({ page }) => {
    expect(wp('option', 'get', 'fluxfiles_secret', `--url=localhost:8890/${slug}`).length).toBeGreaterThanOrEqual(32);
    expect(wp('option', 'get', 'fluxfiles_ttl', `--url=localhost:8890/${slug}`)).toBe('3600');

    wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Files', '--post_name=files',
      '--post_content=[fluxfiles disk="local" mode="browser" height="400px"]', `--url=localhost:8890/${slug}`);
    await loginAdmin(page, `/${slug}`);
    await page.goto(`${BASE}/${slug}/files/`);
    await expect(page.locator('.fluxfiles-error')).toHaveCount(0);
    await expect(page.frameLocator('iframe').locator('.ff-app')).toBeVisible({ timeout: 25_000 });
  });
}
