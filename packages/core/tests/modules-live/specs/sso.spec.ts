import { test, expect } from '@playwright/test';
import { uploadAndSee, textFile } from './helpers';

// SSO bridge, real module + a real OIDC round trip: the standalone UI's
// "Sign in with SSO" → /api/fm/sso/login (PKCE, state, nonce) → the IdP's
// authorize page → callback (code exchange, RS256 id_token checks) → the UI
// boots from the #boot fragment and the access JWT works for real writes.

test('sso: sign in through the IdP and use the minted token', async ({ page }) => {
  await page.goto('/public/index.html');
  const signIn = page.getByRole('link', { name: 'Sign in with SSO' });
  await expect(signIn).toBeVisible({ timeout: 20_000 });
  await signIn.click();

  await expect(page).toHaveURL(/^http:\/\/ff-idp:8191\/authorize\?/);
  const authorize = new URL(page.url());
  expect(authorize.searchParams.get('code_challenge_method')).toBe('S256');
  expect(authorize.searchParams.get('code_challenge')).toBeTruthy();
  expect(authorize.searchParams.get('nonce')).toBeTruthy();
  await page.locator('input[name="sub"]').fill(`sso-user-${Date.now()}`);
  await page.getByRole('button', { name: 'Sign in' }).click();

  // Back on FluxFiles, signed in; the boot token never stays in the URL.
  await expect(page.locator('.ff-app')).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText('Authentication required to access the file manager.')).toBeHidden({ timeout: 20_000 });
  await expect.poll(() => page.url()).not.toContain('#boot=');
  await uploadAndSee(page, textFile(`sso-${Date.now()}.txt`, 'signed in via OIDC\n'));
});

test('sso: a forged callback (unknown state) is refused', async ({ page }) => {
  const res = await page.request.get('/api/fm/sso/callback?code=x&state=forged', { maxRedirects: 0 });
  expect(res.status()).toBeGreaterThanOrEqual(400);
});
