import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, openManager } from './helpers';

// Retention panel (legal-hold gate). The run endpoint is mocked (the module is
// absent here); the engine is covered by tests/unit/test-retention.php.
const CLAIMS = {
  perms: ['read', 'write', 'delete', 'audit'], allow_legal_hold: true,
  retention_rules: [{ path: 'invoices', days: 365, action: 'trash' }, { path: 'tmp', days: 30, action: 'delete' }],
};

test('preview lists what would happen; apply asks first, then reports', async ({ page }) => {
  await openManager(page, mintTokenWithClaims(CLAIMS));
  const posted: Array<Record<string, unknown>> = [];
  await page.route('**/api/fm/retention/run', async (route) => {
    const body = route.request().postDataJSON();
    posted.push(body);
    const dry = body.dry_run !== false;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      dry_run: dry, rules: 2, matched: 2, acted: dry ? 0 : 1, held: 1, errors: 0, truncated: false,
      items: [
        { path: 'invoices/2019.pdf', action: 'trash', age_days: 400, result: dry ? 'would_trash' : 'trashed' },
        { path: 'invoices/held.pdf', action: 'trash', age_days: 900, result: 'held' },
      ] }, error: null }) });
  });

  await page.getByRole('button', { name: 'Activity' }).first().click();
  await page.getByRole('button', { name: 'Retention' }).click();
  const dialog = page.getByRole('dialog', { name: 'Retention' });
  await expect(dialog).toContainText('/invoices: move to trash after 365 days unchanged');
  await expect(dialog).toContainText('/tmp: delete permanently after 30 days unchanged');
  await expect(dialog.getByRole('button', { name: 'Apply now' })).toBeDisabled(); // preview first

  await dialog.getByRole('button', { name: 'Preview' }).click();
  await expect(dialog).toContainText('2 file(s) would be affected (1 on legal hold, skipped).');
  await expect(dialog).toContainText('/invoices/held.pdf — skipped — legal hold');

  page.once('dialog', (d) => d.accept());
  await dialog.getByRole('button', { name: 'Apply now' }).click();
  await expect(dialog).toContainText('Done: 1 file(s) processed');
  expect(posted.map((p) => p.dry_run)).toEqual([true, false]);
});

test('no rules or no delete perm → no Retention button', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ ...CLAIMS, retention_rules: [] }));
  await page.getByRole('button', { name: 'Activity' }).first().click();
  await expect(page.getByRole('button', { name: 'Retention' })).toBeHidden();
});
