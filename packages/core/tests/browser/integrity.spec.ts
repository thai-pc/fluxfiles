import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager } from './helpers';

/**
 * Activity panel integrity tools (audit-export gate): the fixity check and the
 * audit-chain verification. Responses are mocked (the module is absent here);
 * the server side is covered by test-fixity.php / test-audit-chain.php and the
 * gate by test-audit-export-http.php.
 */
const ADMIN = { perms: ['read', 'write', 'audit'], allow_audit_export: true };

test('integrity check lists unexplained changes; verify log reports intact', async ({ page }) => {
  await openManager(page, mintTokenWithClaims(ADMIN));
  await page.route('**/api/fm/fixity/check', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: { ok: false, checked: 12, complete: true, baselined: 0, unchanged: 10,
      changed_explained: [], changed_unexplained: ['contracts/nda.pdf'], missing_explained: [],
      missing_unexplained: ['invoices/2026-09.pdf'] }, error: null }),
  }));
  await page.route('**/api/fm/audit/verify*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: { ok: true, configured: true, entries: 40, chained: 40, unchained: 0,
      tampered: [], gaps: 0, forks: 0, head: 'x' }, error: null }),
  }));

  await page.getByRole('button', { name: 'Activity' }).first().click();
  await page.getByRole('button', { name: 'Integrity check' }).click();
  const box = page.locator('.ff-integrity');
  await expect(box).toContainText('2 file(s) changed or removed outside FluxFiles');
  await expect(box).toContainText('Changed: /contracts/nda.pdf');
  await expect(box).toContainText('Missing: /invoices/2026-09.pdf');

  await page.getByRole('button', { name: 'Verify log' }).click();
  await expect(box).toContainText('Log intact — 40 entries verified.');
});

test('a path-scoped token never gets the per-disk "Verify log" button', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ ...ADMIN, prefix: 'tenant-x' }));
  await page.getByRole('button', { name: 'Activity' }).first().click();
  await expect(page.getByRole('button', { name: 'Integrity check' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Verify log' })).toBeHidden();
});
