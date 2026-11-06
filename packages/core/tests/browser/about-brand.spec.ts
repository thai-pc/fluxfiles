import { test, expect } from '@playwright/test';
import { mintTokenWithClaims, mintToken, openManager } from './helpers';

// About dialog + light white-label (claims: about, brand_name, brand_logo_url,
// accent_color). Real backend, real UI.

test('default: About opens, shows MIT + links, no version, Esc closes, focus returns', async ({ page }) => {
  await openManager(page, mintToken());
  await expect(page).toHaveTitle('FluxFiles');
  const link = page.locator('.ff-about-link');
  await expect(link).toBeVisible();
  await link.click();

  const dlg = page.getByRole('dialog', { name: 'FluxFiles' });
  await expect(dlg).toBeVisible();
  await expect(dlg).toContainText('MIT');
  await expect(dlg.getByRole('link', { name: 'Third-party notices' })).toHaveAttribute('href', /THIRD-PARTY-NOTICES/);
  await expect(dlg.getByRole('link', { name: 'Documentation' })).toHaveAttribute('href', /^https:\/\//);
  // never reveal a version number to end users
  expect(await dlg.innerText()).not.toMatch(/\d+\.\d+\.\d+/);
  // no "built on" line unless a brand name is set
  await expect(dlg.locator('.ff-about-muted')).toBeHidden();

  // focus starts in the dialog and Tab cycles within it
  await expect(dlg.getByRole('button', { name: 'Close' })).toBeFocused();
  for (let i = 0; i < 4; i++) {
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => !!document.activeElement?.closest('.ff-about-modal'))).toBe(true);
  }

  await page.keyboard.press('Escape');
  await expect(dlg).toBeHidden();
  await expect(link).toBeFocused();
});

test('about:false hides the entry entirely', async ({ page }) => {
  await openManager(page, mintTokenWithClaims({ about: false }));
  await expect(page.locator('.ff-about-link')).toBeHidden();
});

test('brand_name / accent_color / brand_logo_url apply; HTML in the name stays text', async ({ page }) => {
  const token = mintTokenWithClaims({
    brand_name: 'Acme <b>Files</b>',
    accent_color: '#0a7f5a',
    brand_logo_url: 'https://example.invalid/logo.png',
  });
  await openManager(page, token);
  await expect(page).toHaveTitle('Acme Files');
  await expect(page.locator('.ff-logo-text')).toHaveText('Acme Files');
  expect(await page.locator('.ff-logo-text b').count()).toBe(0);
  await expect(page.locator('img.ff-brand-logo')).toHaveAttribute('src', 'https://example.invalid/logo.png');
  expect(
    await page.evaluate(() => document.documentElement.style.getPropertyValue('--ff-primary'))
  ).toBe('#0a7f5a');

  await page.locator('.ff-about-link').click();
  const dlg = page.getByRole('dialog', { name: 'Acme Files' });
  await expect(dlg).toBeVisible();
  await expect(dlg.locator('.ff-about-muted')).toContainText('FluxFiles');
});

test('invalid brand claims are ignored (default look)', async ({ page }) => {
  await openManager(
    page,
    mintTokenWithClaims({
      accent_color: 'red;}body{display:none',
      brand_logo_url: 'javascript:alert(1)',
    })
  );
  await expect(page).toHaveTitle('FluxFiles');
  await expect(page.locator('img.ff-brand-logo')).toBeHidden();
  expect(
    await page.evaluate(() => document.documentElement.style.getPropertyValue('--ff-primary'))
  ).toBe('');
});
