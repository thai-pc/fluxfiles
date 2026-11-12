import { test, expect, type Page } from '@playwright/test';
import { mintTokenWithClaims, openManager, uploadFile, pngFile, cardByName, createFolder } from './helpers';

/**
 * E-mail notices + Intake document requests: the operator UI (create dialogs and
 * the links panel). The harness has no licensed modules, so every module response
 * is mocked; what is asserted is the REQUEST BODY the UI builds and what it renders.
 */

const LINKS_BTN = 'button[aria-label="Links"]';
const A = 'owner@example.com';
const B = 'ops@example.com';
const future = () => Math.floor(Date.now() / 1000) + 3600;

const ok = (data: unknown) => ({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, error: null }) });

/** Mock POST /api/fm/share (or /intake) and capture the JSON body. */
async function captureCreate(page: Page, route: 'share' | 'intake', extra: Record<string, unknown> = {}) {
  const bodies: any[] = [];
  await page.route(`**/api/fm/${route}`, async (r) => {
    if (r.request().method() !== 'POST') return r.fallback();
    bodies.push(r.request().postDataJSON());
    await r.fulfill(ok({ jti: 'j1', expires: future(), url: 'https://files.example/x?token=T', has_password: false, ...extra }));
  });
  return bodies;
}

async function openShareModal(page: Page, claims: Record<string, unknown>, name: string) {
  await openManager(page, mintTokenWithClaims({ allow_share: true, ...claims }));
  await uploadFile(page, pngFile(name));
  await expect(cardByName(page, name)).toBeVisible({ timeout: 15_000 });
  await cardByName(page, name).click();
  await page.getByRole('button', { name: 'Share link', exact: true }).click();
}

async function openIntakeModal(page: Page, claims: Record<string, unknown>, folder: string) {
  await openManager(page, mintTokenWithClaims({ allow_intake: true, ...claims }));
  await createFolder(page, page, folder);
  await cardByName(page, folder).click();
  await page.getByRole('button', { name: 'Upload portal', exact: true }).click();
}

test('no notify_to / domains: the E-mail me box is absent and no notify block is sent', async ({ page }) => {
  const bodies = await captureCreate(page, 'share');
  await openShareModal(page, {}, 'nn-none.png');
  await expect(page.getByTestId('notify-box')).toHaveCount(0);
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();
  expect(bodies[0]).not.toHaveProperty('notify');
});

test('default (all recipients checked) sends no notify block', async ({ page }) => {
  const bodies = await captureCreate(page, 'share');
  await openShareModal(page, { notify_to: [A, B] }, 'nn-default.png');
  await expect(page.getByTestId('notify-on')).toBeChecked();
  await expect(page.getByTestId('notify-rcpt')).toHaveCount(2);
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();
  expect(bodies[0]).not.toHaveProperty('notify');
});

test('E-mail me off sends notify {enabled:false}', async ({ page }) => {
  const bodies = await captureCreate(page, 'share');
  await openShareModal(page, { notify_to: [A, B] }, 'nn-off.png');
  await page.getByTestId('notify-on').uncheck();
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();
  expect(bodies[0].notify).toEqual({ enabled: false });
});

test('a recipient subset is sent as notify.to; none selected is refused in the form', async ({ page }) => {
  const bodies = await captureCreate(page, 'share');
  await openShareModal(page, { notify_to: [A, B] }, 'nn-subset.png');
  const boxes = page.getByTestId('notify-rcpt');
  await boxes.nth(1).uncheck();
  await boxes.nth(0).uncheck();
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('.ff-links-form-error')).toContainText(/at least one recipient/i);
  expect(bodies).toHaveLength(0);

  await boxes.nth(0).check();
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();
  expect(bodies[0].notify).toEqual({ to: [A] });
});

test('notify_recipient_domains: an extra address is sent, an invalid one is refused', async ({ page }) => {
  const bodies = await captureCreate(page, 'share');
  await openShareModal(page, { notify_to: [A], notify_recipient_domains: ['example.com'] }, 'nn-extra.png');
  await page.getByTestId('notify-extra').fill('not-an-address');
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('.ff-links-form-error')).toContainText(/valid e-mail/i);
  await page.getByTestId('notify-extra').fill('Boss@Example.com');
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();
  expect(bodies[0].notify).toEqual({ to: [A, 'boss@example.com'] });
});

test('intake create with a document request sends the right body and shows "Request sent"', async ({ page }) => {
  const bodies = await captureCreate(page, 'intake', { request: { sent: true } });
  await openIntakeModal(page, { notify_to: [A], intake_request_any_recipient: true }, 'nn-req-folder');

  await page.getByTestId('req-toggle').click();
  await page.getByTestId('req-label').first().fill('Passport');
  await page.getByTestId('req-add').click();
  await page.getByTestId('req-label').nth(1).fill('Proof of address');
  await page.getByRole('checkbox', { name: 'Required' }).nth(1).uncheck();
  const d3 = new Date(Date.now() + 3 * 86400_000);
  const dueStr = `${d3.getFullYear()}-${String(d3.getMonth() + 1).padStart(2, '0')}-${String(d3.getDate()).padStart(2, '0')}`;
  await page.getByTestId('req-due').fill(dueStr);
  await page.getByTestId('req-email').fill('Anna@Client.test');
  await expect(page.getByTestId('req-send-now')).toBeChecked();
  await page.getByTestId('req-message').fill('Hi Anna, please upload these.');
  await page.getByRole('button', { name: 'Create upload portal' }).click();

  await expect(page.getByTestId('req-sent')).toHaveText('Request sent to a***@client.test');
  const req = bodies[0].request;
  expect(req.items).toEqual([{ label: 'Passport', required: true }, { label: 'Proof of address', required: false }]);
  expect(req.requestee).toBe('anna@client.test');
  expect(req.send_now).toBe(true);
  expect(req.message).toBe('Hi Anna, please upload these.');
  expect(req.due_at).toBe(Math.floor(new Date(d3.getFullYear(), d3.getMonth(), d3.getDate(), 23, 59, 59).getTime() / 1000));
  expect(bodies[0]).not.toHaveProperty('notify');
});

test('request form validation: duplicate names and a past due date never reach the server', async ({ page }) => {
  const bodies = await captureCreate(page, 'intake');
  await openIntakeModal(page, {}, 'nn-val-folder');
  await page.getByTestId('req-toggle').click();
  await page.getByTestId('req-label').first().fill('ID');
  await page.getByTestId('req-add').click();
  await page.getByTestId('req-label').nth(1).fill('id');
  await page.getByRole('button', { name: 'Create upload portal' }).click();
  await expect(page.locator('.ff-links-form-error')).toContainText(/different/i);

  await page.getByTestId('req-label').nth(1).fill('Other');
  await page.getByTestId('req-due').fill('2001-01-01');
  await page.getByRole('button', { name: 'Create upload portal' }).click();
  await expect(page.locator('.ff-links-form-error')).toContainText(/due date/i);
  expect(bodies).toHaveLength(0);
});

test('a closed "Request documents" disclosure sends no request block', async ({ page }) => {
  const bodies = await captureCreate(page, 'intake');
  await openIntakeModal(page, {}, 'nn-plain-folder');
  await page.getByRole('button', { name: 'Create upload portal' }).click();
  await expect(page.locator('[x-ref="linkUrl"]')).toBeVisible();
  expect(bodies[0]).not.toHaveProperty('request');
});

test('server 403 notify_recipient_denied keeps the form open with the message', async ({ page }) => {
  await page.route('**/api/fm/share', (r) =>
    r.request().method() !== 'POST' ? r.fallback() : r.fulfill({
      status: 403, contentType: 'application/json',
      body: JSON.stringify({ data: null, error: 'denied', error_code: 'notify_recipient_denied' }),
    }));
  await openShareModal(page, { notify_to: [A] }, 'nn-denied.png');
  await page.getByRole('button', { name: 'Create share link' }).click();
  await expect(page.locator('.ff-links-form-error')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create share link' })).toBeVisible();
});

test('links panel: bell toggles mute through /notify/mute and flips state', async ({ page }) => {
  const calls: any[] = [];
  await page.route('**/api/fm/share/list*', (r) => r.fulfill(ok([
    { jti: 'bell-1', disk: 'local', path: 'a.pdf', label: 'With bell', created: 1, expires: future(), views: 0, downloads: 0, max_downloads: 0, has_password: false, has_notify: true, notify_recipients: 2, notify_muted: false },
    { jti: 'nobell', disk: 'local', path: 'b.pdf', label: 'No bell', created: 1, expires: future(), views: 0, downloads: 0, max_downloads: 0, has_password: false, has_notify: false, notify_recipients: 0, notify_muted: false },
  ])));
  await page.route('**/api/fm/notify/mute', async (r) => {
    const b = r.request().postDataJSON();
    calls.push(b);
    await r.fulfill(ok({ muted: b.muted }));
  });
  await openManager(page, mintTokenWithClaims({ allow_share: true }));
  await page.locator(LINKS_BTN).click();
  await expect(page.locator('.ff-activity-table tbody tr')).toHaveCount(2);
  await expect(page.getByTestId('notify-bell')).toHaveCount(2);
  await expect(page.getByTestId('notify-bell').first()).toBeVisible();
  await expect(page.getByTestId('notify-bell').nth(1)).not.toBeVisible();   // has_notify:false

  const bell = page.getByTestId('notify-bell').first();
  await expect(bell).toHaveAttribute('aria-pressed', 'false');
  await bell.click();
  await expect(bell).toHaveAttribute('aria-pressed', 'true');
  await expect(bell).toHaveClass(/is-muted/);
  await bell.click();
  await expect(bell).toHaveAttribute('aria-pressed', 'false');
  expect(calls).toEqual([
    { module: 'share', disk: 'local', jti: 'bell-1', muted: true },
    { module: 'share', disk: 'local', jti: 'bell-1', muted: false },
  ]);
});

test('links panel: "Send test e-mail" posts {} and toasts the outcome; hidden without notify_to', async ({ page }) => {
  const bodies: any[] = [];
  let sent = true;
  await page.route('**/api/fm/share/list*', (r) => r.fulfill(ok([])));
  await page.route('**/api/fm/notify/test', async (r) => {
    bodies.push(r.request().postDataJSON());
    await r.fulfill(ok(sent ? { sent: true, transport: 'resend', error: null } : { sent: false, transport: 'resend', error: 'bad key' }));
  });
  await openManager(page, mintTokenWithClaims({ allow_share: true, notify_to: [A] }));
  await page.locator(LINKS_BTN).click();
  await page.getByTestId('notify-test').click();
  await expect(page.locator('.ff-toast, [role="status"]').filter({ hasText: 'Test e-mail sent (resend)' }).first()).toBeVisible();
  sent = false;
  await page.getByTestId('notify-test').click();
  await expect(page.locator('body')).toContainText('Test e-mail failed (resend): bad key');
  expect(bodies).toEqual([{}, {}]);
});

test('links panel: no notify_to → no test button', async ({ page }) => {
  await page.route('**/api/fm/share/list*', (r) => r.fulfill(ok([])));
  await openManager(page, mintTokenWithClaims({ allow_share: true }));
  await page.locator(LINKS_BTN).click();
  await expect(page.getByTestId('notify-test')).not.toBeVisible();
});

test('intake list: progress, due date, overdue and complete badges render', async ({ page }) => {
  const now = Math.floor(Date.now() / 1000);
  await page.route('**/api/fm/intake/list*', (r) => r.fulfill(ok([
    { jti: 'p1', disk: 'local', path: 'in/a', label: 'Onboarding', created: 1, expires: now + 86400, received: 2, max_files: 0, allowed_ext: [], has_password: false, has_notify: true, notify_recipients: 1, notify_muted: false,
      request: { items_total: 4, items_required: 3, items_done_required: 2, due_at: now + 86400, complete_at: 0, overdue: false, has_requestee: true } },
    { jti: 'p2', disk: 'local', path: 'in/b', label: 'Late one', created: 1, expires: now + 86400, received: 0, max_files: 0, allowed_ext: [], has_password: false, has_notify: false, notify_recipients: 0, notify_muted: false,
      request: { items_total: 2, items_required: 2, items_done_required: 0, due_at: now - 7200, complete_at: 0, overdue: true, has_requestee: false } },
    { jti: 'p3', disk: 'local', path: 'in/c', label: 'Done one', created: 1, expires: now + 86400, received: 3, max_files: 0, allowed_ext: [], has_password: false, has_notify: false, notify_recipients: 0, notify_muted: false,
      request: { items_total: 3, items_required: 3, items_done_required: 3, due_at: 0, complete_at: now - 60, overdue: false, has_requestee: false } },
    { jti: 'p4', disk: 'local', path: 'in/d', label: 'Plain', created: 1, expires: now + 86400, received: 1, max_files: 0, allowed_ext: [], has_password: false, has_notify: false, notify_recipients: 0, notify_muted: false, request: null },
  ])));
  await openManager(page, mintTokenWithClaims({ allow_intake: true }));
  await page.locator(LINKS_BTN).click();
  const rows = page.locator('.ff-activity-table tbody tr');
  await expect(rows).toHaveCount(4);
  const cell = (i: number) => rows.nth(i).getByTestId('req-cell');
  await expect(cell(0)).toContainText('2/3 documents');
  await expect(cell(0)).toContainText('Due');
  await expect(cell(0).locator('.ff-links-badge:visible')).toHaveCount(0);
  await expect(cell(1)).toContainText('0/2 documents');
  await expect(cell(1)).toContainText('Overdue');
  await expect(cell(2)).toContainText('3/3 documents');
  await expect(cell(2)).toContainText('Complete');
  await expect(cell(3)).toContainText('—');
});
