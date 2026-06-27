import { test, expect } from '@playwright/test';
import { mintToken, mintTokenWithClaims, openManager } from './helpers';

// File-permissions (chmod) dialog (M3). The chmod *logic* over a real SFTP server
// is covered by the HTTP e2e; here we verify the dialog UI: it shows only on an
// SFTP disk, reads the mode, the checkbox↔octal binding is correct, and Apply
// POSTs the new mode. The endpoint is mocked, so no SFTP server is needed.

async function mockChmod(page: import('@playwright/test').Page, startMode = '644') {
  let posted: Record<string, unknown> | null = null;
  await page.route('**/api/fm/chmod*', async (route) => {
    if (route.request().method() === 'POST') {
      posted = route.request().postDataJSON();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { path: 'x', mode: posted.mode } }) });
    } else {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { path: 'x', mode: startMode } }) });
    }
  });
  return () => posted;
}

test('canChmod: only true on an SFTP disk with the claim', async ({ page }) => {
  await openManager(page, mintToken());
  const r = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    c.diskDriver = 'local';
    const onLocal = c.canChmod;
    c.diskDriver = 'sftp';
    const onSftp = c.canChmod;
    return { onLocal, onSftp };
  });
  expect(r.onLocal).toBe(false);
  expect(r.onSftp).toBe(true);
});

test('canChmod is false when the token sets allow_chmod=false (even on SFTP)', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ allow_chmod: false }));
  const allowed = await page.evaluate(() => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    c.diskDriver = 'sftp';
    return c.canChmod;
  });
  expect(allowed).toBe(false);
});

test('chmod dialog: reads the mode, checkbox↔octal binding, Apply POSTs', async ({ page }) => {
  await openManager(page, mintToken());
  const getPosted = await mockChmod(page, '644');

  // Drive the dialog open for a fake file (SFTP disk).
  await page.evaluate(async () => {
    const c = (window as any).Alpine.$data(document.querySelector('.ff-app'));
    c.diskDriver = 'sftp';
    await c.openChmod({ name: 'deploy.sh', key: 'bin/deploy.sh', type: 'file' });
  });

  const modal = page.locator('.ff-chmod-modal');
  await expect(modal).toBeVisible();
  await expect(modal).toContainText('deploy.sh');
  // 644 → owner rw, group r, world r. The editable octal field shows 644,
  // and the symbolic string mirrors it.
  await expect(modal.locator('.ff-chmod-octal')).toHaveValue('644');
  await expect(modal.locator('.ff-chmod-symbolic')).toHaveText('rw-r--r--');

  // The grid has 9 checkboxes (3 who × 3 perms).
  await expect(modal.locator('.ff-chmod-grid input[type=checkbox]')).toHaveCount(9);

  // Toggle owner-execute (row 0, last checkbox) → 644 becomes 744.
  await modal.locator('tbody tr').nth(0).locator('input[type=checkbox]').nth(2).check();
  await expect(modal.locator('.ff-chmod-octal')).toHaveValue('744');
  await expect(modal.locator('.ff-chmod-symbolic')).toHaveText('rwxr--r--');

  // A preset chip applies a whole mode in one click (700 → rwx------).
  await modal.locator('.ff-chmod-preset', { hasText: '700' }).click();
  await expect(modal.locator('.ff-chmod-octal')).toHaveValue('700');
  await expect(modal.locator('tbody tr').nth(0).locator('input[type=checkbox]').nth(0)).toBeChecked();
  await expect(modal.locator('tbody tr').nth(2).locator('input[type=checkbox]').nth(0)).not.toBeChecked();

  // Typing into the octal field drives the checkboxes too (755).
  await modal.locator('.ff-chmod-octal').fill('755');
  await expect(modal.locator('.ff-chmod-symbolic')).toHaveText('rwxr-xr-x');

  // Apply → POST carries the final mode (755) and closes the dialog.
  await modal.getByRole('button', { name: 'Apply' }).click();
  await expect(modal).toBeHidden();
  expect(getPosted()).toMatchObject({ disk: 'local', path: 'bin/deploy.sh', mode: '755' });
});
