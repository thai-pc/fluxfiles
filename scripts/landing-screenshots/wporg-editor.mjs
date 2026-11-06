#!/usr/bin/env node
/**
 * WordPress.org screenshot of the block-editor integration: the REAL free
 * plugin (scripts/build-wordpress.sh artifact) in @wordpress/env, a post being
 * written with a "FluxFiles Image" block already filled from FluxFiles, and the
 * FluxFiles picker open for a second one.
 *
 * Needs the wp-env site the WordPress e2e uses, already up:
 *   bash packages/core/tests/apps/wordpress-e2e/setup.sh
 * then:
 *   node scripts/landing-screenshots/wporg-editor.mjs [--out=FILE]
 * Tear the site down afterwards: (cd packages/core/tests/apps/wordpress-e2e && npx @wordpress/env stop)
 *
 * Demo photos are generated with ImageMagick and uploaded through the plugin's
 * own [fluxfiles] page; nothing is faked in the editor.
 */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..', '..');
const wpEnvDir = join(repoRoot, 'packages', 'core', 'tests', 'apps', 'wordpress-e2e');
const args = Object.fromEntries(process.argv.slice(2).map((a) => {
  const m = a.match(/^--([a-z0-9-]+)(?:=(.*))?$/);
  return m ? [m[1], m[2] ?? true] : [a, true];
}));
const OUT = typeof args.out === 'string' ? resolve(args.out) : join(repoRoot, '.wordpress-org', 'screenshot-4.png');
const BASE = 'http://localhost:8888';
const FOLDER = 'Spring walks';

const { chromium } = createRequire(join(repoRoot, 'packages', 'core', 'tests', 'browser', 'package.json'))('@playwright/test');
const wp = (...a) => execFileSync('npx', ['--yes', '@wordpress/env', 'run', 'cli', 'wp', ...a], { cwd: wpEnvDir, stdio: 'ignore' });

// ── demo photos ──
const work = mkdtempSync(join(tmpdir(), 'ff-wporg-'));
const FONT = ['/System/Library/Fonts/Supplemental/Arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'].find(existsSync);
function photo(name, [s1, s2, g1, g2], sun) {
  const out = join(work, name);
  execFileSync('magick', ['-size', '1200x470', `gradient:${s1}-${s2}`, '-size', '1200x330', `gradient:${g1}-${g2}`, '-append',
    ...(sun ? ['-fill', sun, '-draw', 'circle 820,330 820,262'] : []), '-blur', '0x7', '-quality', '86', out]);
  return { name, mimeType: 'image/jpeg', buffer: readFileSync(out) };
}
if (!FONT) console.warn('no TTF font found (only needed by capture.mjs)');
const PHOTOS = [
  photo('coastal-sunrise.jpg', ['#fef3c7', '#fb923c', '#0e7490', '#164e63'], '#fffbeb'),
  photo('alpine-lake.jpg', ['#bfdbfe', '#60a5fa', '#1e3a8a', '#0f172a']),
  photo('lavender-field.jpg', ['#fae8ff', '#e879f9', '#6b21a8', '#3b0764'], '#fdf4ff'),
  photo('autumn-forest.jpg', ['#fed7aa', '#ea580c', '#7c2d12', '#431407']),
  photo('northern-lights.jpg', ['#a7f3d0', '#059669', '#1e293b', '#020617']),
  photo('harbor-at-dusk.jpg', ['#fbcfe8', '#c084fc', '#1e3a8a', '#0f172a'], '#fdf4ff'),
  photo('spring-meadow.jpg', ['#e0f2fe', '#7dd3fc', '#65a30d', '#365314'], '#fefce8'),
  photo('desert-dunes.jpg', ['#fde68a', '#f59e0b', '#b45309', '#78350f'], '#fffbeb'),
];

async function login(page) {
  for (let i = 0; i < 3; i++) {
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('#wpadminbar').count()) return;
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
    await page.goto(`${BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    if (await page.locator('#wpadminbar').count()) return;
  }
  throw new Error('WordPress login failed');
}

// The block inserts a public URL, which /attach refuses on a gated disk; the
// e2e setup gates it, so open it up for this run (restored at the end).
wp('option', 'update', 'fluxfiles_local_private', '0');
const browser = await chromium.launch();
try {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1, locale: 'en-US', colorScheme: 'light' });
  const page = await ctx.newPage();
  await login(page);

  // 1. Upload the photos through the plugin's own [fluxfiles] page into a
  //    "Spring walks" folder (the disk root also lists the plugin's own
  //    .htaccess/index.php guards, which would clutter the shot).
  await page.goto(`${BASE}/files/`);
  const fm = page.frameLocator('iframe');
  await fm.locator('.ff-app').waitFor({ timeout: 25000 });
  await page.waitForLoadState('networkidle');
  const fmFrame = page.frames().find((f) => f.url().includes('/public/'));
  await fmFrame.evaluate(async (dir) => {
    const app = window.Alpine.$data(document.querySelector('.ff-app'));
    await fetch(app.joinUrl('/api/fm/mkdir'), { method: 'POST',
      headers: { Authorization: 'Bearer ' + app.token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ disk: app.currentDisk, path: dir }) }); // 409 on a re-run is fine
  }, FOLDER).catch(() => {});
  const enterFolder = async () => {
    await page.reload();
    await fm.locator('.ff-app').waitFor({ timeout: 25000 });
    const folderCard = fm.locator(`.file-card:has(.fname:text-is("${FOLDER}"))`);
    await folderCard.waitFor({ timeout: 15000 });
    await folderCard.dblclick();
    await fm.locator('.ff-breadcrumb, .breadcrumb').getByText(FOLDER).first().waitFor({ timeout: 10000 });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
  };
  await enterFolder();
  // Re-run hygiene: drop "name (1).jpg" copies an interrupted earlier run left.
  const removed = await page.frames().find((f) => f.url().includes('/public/')).evaluate(async (dir) => {
    const app = window.Alpine.$data(document.querySelector('.ff-app'));
    const dupes = (app.files || []).map((x) => x.name || x.basename || '').filter((n) => / \(\d+\)\.[a-z]+$/.test(n));
    for (const n of dupes) {
      await fetch(app.joinUrl('/api/fm/delete'), { method: 'DELETE',
        headers: { Authorization: 'Bearer ' + app.token, 'Content-Type': 'application/json' },
        body: JSON.stringify({ disk: app.currentDisk, path: dir + '/' + n }) });
    }
    return dupes.length;
  }, FOLDER);
  if (removed) await enterFolder();
  const have = new Set(await fm.locator('.file-card .fname').allInnerTexts());
  const todo = PHOTOS.filter((p) => !have.has(p.name));
  if (todo.length) {
    await fm.locator('.ff-dropzone input[type=file]').setInputFiles(todo);
    for (const p of todo) await fm.locator(`.file-card:has(.fname:text-is("${p.name}"))`).waitFor({ timeout: 30000 });
  }
  wp('option', 'update', 'blogname', 'Northwind Travel');

  // 2. A new post: title, a paragraph, and an empty FluxFiles Image block.
  await page.goto(`${BASE}/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.wp?.data?.select('core/block-editor') && !!window.wp?.blocks?.getBlockType('fluxfiles/image')
    && !!window.wp.data.select('core/editor').getCurrentPostType() && !!window.wp.data.select('core/editor').getCurrentPostId(), null, { timeout: 30000 });
  await page.waitForTimeout(1000);
  await page.evaluate(() => {
    const { dispatch } = window.wp.data;
    try { dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch { /* older WP */ }
    try { dispatch('core/preferences').set('core', 'fullscreenMode', false); } catch { /* */ }
    dispatch('core/editor').editPost({ title: 'Five coastal walks for the spring' });
    const { createBlock } = window.wp.blocks;
    dispatch('core/block-editor').resetBlocks([
      createBlock('core/paragraph', { content: 'Long days, soft light and quiet beaches: spring is the best time to walk the coast. Here are five routes we keep coming back to, with photos from this year’s trips.' }),
      createBlock('fluxfiles/image'),
    ]);
  });
  // The editor can still be initialising and drop that reset; wait, verify, retry.
  for (let i = 0; i < 10; i++) {
    await page.waitForTimeout(800);
    const ok = await page.evaluate(() => window.wp.data.select('core/block-editor').getBlocks()
      .some((b) => b.name === 'fluxfiles/image'));
    if (ok) break;
    await page.evaluate(() => {
      const { createBlock } = window.wp.blocks;
      window.wp.data.dispatch('core/block-editor').resetBlocks([
        createBlock('core/paragraph', { content: 'Long days, soft light and quiet beaches: spring is the best time to walk the coast. Here are five routes we keep coming back to, with photos from this year\u2019s trips.' }),
        createBlock('fluxfiles/image'),
      ]);
    });
  }
  // The editor canvas may be an iframe (WP ≥ 6.3 with v3 blocks) or not.
  const canvas = (await page.locator('iframe[name="editor-canvas"]').count())
    ? page.frameLocator('iframe[name="editor-canvas"]') : page;
  const pickBtn = () => canvas.getByRole('button', { name: 'Choose from FluxFiles' }).last();

  // 3. Fill the block for real: picker → double-click a photo → attachment bridge → <img>.
  await pickBtn().click({ timeout: 15000 })
    .catch(async (e) => { await page.screenshot({ path: OUT.replace(/\.png$/, '') + '-FAILED.png' }); throw e; });
  const picker = page.frameLocator('#fluxfiles-block-body iframe');
  await picker.locator('.ff-app').waitFor({ timeout: 25000 });
  await picker.locator(`.file-card:has(.fname:text-is("${FOLDER}"))`).dblclick();
  await picker.locator('.file-card:has(.fname:text-is("coastal-sunrise.jpg"))').dblclick();
  await canvas.locator('img[src*="coastal-sunrise"]').first().waitFor({ timeout: 30000 });
  await page.locator('#fluxfiles-block-modal').waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {});

  // 4. Save the draft and reload the editor: block.js keeps one picker per page
  // and binds it to the first block's callback, so a second pick in the same
  // page session opens an empty modal (plugin bug, reported separately).
  await page.evaluate(() => window.wp.data.dispatch('core/editor').savePost());
  await page.waitForFunction(() => { const s = window.wp.data.select('core/editor');
    return !s.isSavingPost() && !!s.getCurrentPostId() && !s.isEditedPostDirty(); }, null, { timeout: 30000 });
  const postId = await page.evaluate(() => window.wp.data.select('core/editor').getCurrentPostId());
  await page.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.wp?.data?.select('core/block-editor')?.getBlocks().length, null, { timeout: 30000 });
  await canvas.locator('img[src*="coastal-sunrise"]').first().waitFor({ timeout: 30000 });

  // 5. A second, empty block → open the picker over the editor; that's the shot.
  await page.evaluate(() => {
    const { dispatch } = window.wp.data;
    const { createBlock } = window.wp.blocks;
    dispatch('core/block-editor').insertBlocks([
      createBlock('core/heading', { level: 2, content: '1. The cliff path at sunrise' }),
      createBlock('fluxfiles/image'),
    ]);
  });
  await page.waitForTimeout(600);
  await pickBtn().click();
  await page.locator('#fluxfiles-block-modal').waitFor({ state: 'visible', timeout: 10000 });
  await picker.locator(`.file-card:has(.fname:text-is("${FOLDER}"))`).dblclick();
  await picker.locator('.file-card:has(.fname:text-is("lavender-field.jpg"))').waitFor({ timeout: 15000 });
  await picker.locator('.file-card img').first().waitFor({ timeout: 20000 })
    .catch(async (e) => { await page.screenshot({ path: OUT.replace(/\.png$/, '') + '-FAILED.png' }); throw e; });
  const pickerFrame = page.frames().find((f) => f.url().includes('/public/') && f.parentFrame() === page.mainFrame());
  if (pickerFrame) {
    await pickerFrame.waitForFunction(() => [...document.querySelectorAll('.file-card img')].every((i) => i.complete && i.naturalWidth > 0), null, { timeout: 20000 });
  }
  await page.mouse.move(640, 108); // modal header: no card hover in the shot
  await page.waitForTimeout(800);
  await page.screenshot({ path: OUT });
  console.log(`✔ ${OUT}`);
} finally {
  await browser.close();
  try { wp('option', 'update', 'fluxfiles_local_private', '1'); } catch { /* site gone */ }
  rmSync(work, { recursive: true, force: true });
}
