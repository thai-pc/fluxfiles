import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHmac } from 'node:crypto';
import { TEST_SECRET } from './secret';
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

// Node's createToken (JSON.stringify) and jose/Go emit RAW UTF-8 in the payload,
// unlike PHP/PyJWT which escape it to \uXXXX. A bare atob() would render mojibake.
function mintRawUtf8Token(extra: Record<string, unknown>): string {
  const b64u = (b: Buffer) => b.toString('base64url');
  const head = b64u(Buffer.from(JSON.stringify({ alg: 'HS256', typ: 'JWT' })));
  const now = Math.floor(Date.now() / 1000);
  const body = b64u(
    Buffer.from(
      JSON.stringify({ sub: 'e2e-user', perms: ['read', 'write', 'delete'], disks: ['local'], prefix: '', max_upload: 50, iat: now, exp: now + 86400, ...extra }),
      'utf8'
    )
  );
  return `${head}.${body}.${b64u(createHmac('sha256', TEST_SECRET).update(`${head}.${body}`).digest())}`;
}

test('raw UTF-8 brand_name in the JWT payload renders exactly', async ({ page }) => {
  const token = mintRawUtf8Token({ brand_name: 'Tệp Acme' });
  expect(Buffer.from(token.split('.')[1], 'base64url').toString('utf8')).toContain('Tệp Acme');
  await openManager(page, token);
  await expect(page).toHaveTitle('Tệp Acme');
  await expect(page.locator('.ff-logo-text')).toHaveText('Tệp Acme');
});

// Shared with tests/unit/test-claims.php: the PHP and JS sanitizers must agree.
const here = dirname(fileURLToPath(import.meta.url));
const vectors = JSON.parse(readFileSync(join(here, '../fixtures/brand-vectors.json'), 'utf8'));

test('brand sanitizer vectors match the PHP rules (fm.js getters)', async ({ page }) => {
  await openManager(page, mintRawUtf8Token({}));
  const results = await page.evaluate(
    ([names, logos]) => {
      const data = (window as any).Alpine.$data(document.querySelector('[x-data]') as any);
      const [h, , sig] = data.token.split('.');
      const enc = (o: object) => {
        let bin = '';
        new TextEncoder().encode(JSON.stringify(o)).forEach((b) => (bin += String.fromCharCode(b)));
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      };
      const out: { names: string[]; logos: string[] } = { names: [], logos: [] };
      for (const [i] of names) {
        data.token = `${h}.${enc({ brand_name: i })}.${sig}`;
        out.names.push(data.brandName);
      }
      for (const [i] of logos) {
        data.token = `${h}.${enc({ brand_logo_url: i })}.${sig}`;
        out.logos.push(data.brandLogoUrl);
      }
      return out;
    },
    [vectors.brand_name, vectors.brand_logo_url] as [string[][], string[][]]
  );
  expect(results.names).toEqual(vectors.brand_name.map((v: string[]) => v[1]));
  expect(results.logos).toEqual(vectors.brand_logo_url.map((v: string[]) => v[1]));
});
