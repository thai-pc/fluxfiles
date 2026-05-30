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
