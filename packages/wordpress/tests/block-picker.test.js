// Behaviour test: the Gutenberg block picker must work on every open and route the pick
// to the block that opened it (the SDK closes itself after a pick, so a one-shot "opened"
// flag left the 2nd open with an empty modal and the 1st block's callback).
// Run: node packages/wordpress/tests/block-picker.test.js
const fs = require('fs'), vm = require('vm'), path = require('path'), assert = require('assert');

const read = f => fs.readFileSync(path.join(__dirname, '../assets', f), 'utf8');
assert(!/\bopened\b/.test(read('block.js')), 'stale single-open flag in block.js');
assert(!/\bopened\b/.test(read('media-button.js')), 'stale single-open flag in media-button.js');
assert(!/pickerOpened/.test(read('media-integration.js')), 'stale single-open flag in media-integration.js');

// FluxFiles SDK stub: one live "iframe" at a time, closed after a pick, like the real one.
const sdk = {
    live: null, opens: 0,
    open(o) { sdk.close(); sdk.live = o; sdk.opens++; },
    close() { sdk.live = null; },
    pick(f) {
        const o = sdk.live;
        assert(o, 'picker shown empty (no live iframe)');
        o.onSelect(f);
        sdk.close();
    }
};

const els = {};
const fakeEl = () => ({ style: {}, querySelector: () => ({ addEventListener() {} }), addEventListener() {} });
const buttons = [];
const ctx = vm.createContext({
    console, Promise, setTimeout, URL,
    document: {
        getElementById: id => els[id] || null,
        createElement: () => fakeEl(),
        body: { appendChild: e => { els[e.id] = e; } }
    },
    fetch: (...a) => ctx.__fetch(...a)
});
ctx.__fetch = () => Promise.resolve({ ok: true, json: () => ({ data: { url: 'http://x/u', id: 1 } }) });
ctx.window = ctx;
ctx.alerts = [];
ctx.alert = m => ctx.alerts.push(m);
vm.runInContext(read('picker-url.js'), ctx);
ctx.FluxFiles = sdk;
ctx.FluxFilesBlockCfg = { token: 't', endpoint: 'e', attachUrl: '/a' };
let edit;
ctx.wp = {
    blocks: { registerBlockType: (n, d) => { edit = d.edit; } },
    element: { createElement: (t, p) => { if (p && p.variant === 'primary') buttons.push(p.onClick); return {}; } },
    blockEditor: {}, components: {}, i18n: { __: s => s, sprintf: (s, ...a) => a.reduce((t, v) => t.replace('%s', v), s) }
};
vm.runInContext(read('block.js'), ctx);

const sets = [[], []];
[0, 1].forEach(i => edit({ attributes: { url: '' }, setAttributes: a => sets[i].push(a) }));
assert.strictEqual(buttons.length, 2);

const tick = () => new Promise(r => setTimeout(r, 10));
(async () => {
    buttons[0](); sdk.pick({ url: 'http://x/one', name: 'one' }); await tick();
    buttons[1](); sdk.pick({ url: 'http://x/two', name: 'two' }); await tick();
    assert.strictEqual(sdk.opens, 2, 'each open re-initialises the SDK');
    assert.strictEqual(sets[0].length, 1, 'block 1 got exactly one pick');
    assert.strictEqual(sets[1].length, 1, 'block 2 got its own pick');

    // Private local storage: the attach bridge refuses (422) and the only URL is an
    // expiring /stream?token= link. It must never be saved into the post.
    const private422 = () => Promise.resolve({ ok: false, json: () => ({ data: null }) });
    ctx.__fetch = private422;
    buttons[0](); sdk.pick({ url: 'http://x/api/fm/stream?disk=local&path=a.png&token=T', permanent_url: null, name: '<b>a.png' }); await tick();
    assert.strictEqual(sets[0].length, 1, 'a token-only pick is NOT saved into the block');
    assert.strictEqual(ctx.alerts.length, 1, 'and the editor is told (plain-text alert)');
    assert.ok(/private storage/.test(ctx.alerts[0]) && ctx.alerts[0].includes('<b>a.png'), 'names the file, plain text: ' + ctx.alerts[0]);
    // The bridge failing for another reason still falls back to the permanent URL.
    buttons[0](); sdk.pick({ url: 'http://x/api/fm/stream?token=T', permanent_url: 'http://x/storage/uploads/a.png', name: 'a.png' }); await tick();
    assert.strictEqual(sets[0].length, 2);
    assert.strictEqual(sets[0][1].url, 'http://x/storage/uploads/a.png', 'permanent_url used, never the token link');
    // javascript: and data: URLs never reach saved content.
    buttons[0](); sdk.pick({ url: 'javascript:alert(1)', name: 'x.png' }); await tick();
    assert.strictEqual(sets[0].length, 2, 'non-http(s) refused');
    ctx.__fetch = () => Promise.resolve({ ok: true, json: () => ({ data: { url: 'http://x/u', id: 1 } }) });

    // media-button.js: same contract through the classic-editor modal.
    const mel = { style: {}, querySelector: () => ({ addEventListener() {} }) };
    let click;
    const mctx = vm.createContext({
        console, Promise, setTimeout, URL,
        document: {
            getElementById: id => id === 'fluxfiles-media-btn' ? { addEventListener: (e, f) => { click = f; } } : mel,
            addEventListener() {}
        },
        fetch: (...a) => mctx.__fetch(...a)
    });
    mctx.__fetch = () => Promise.resolve({ ok: true, json: () => ({ data: { id: 1, url: 'http://x/att.png' } }) });
    mctx.window = mctx;
    mctx.alerts = [];
    mctx.alert = m => mctx.alerts.push(m);
    vm.runInContext(read('picker-url.js'), mctx);
    mctx.FluxFiles = sdk;
    mctx.fluxfilesMediaButton = { config: { disk: 'd' }, tokenUrl: '/t', nonce: 'n', attachUrl: '/a' };
    vm.runInContext(read('media-button.js'), mctx);
    sdk.opens = 0;
    const picks = [];
    mctx.send_to_editor = h => picks.push(h);
    click({ preventDefault() {} }); sdk.pick({ url: 'http://x/a.png', name: 'a.png' }); await tick();
    click({ preventDefault() {} }); sdk.pick({ url: 'http://x/b.png', name: 'b.png' }); await tick();
    assert.strictEqual(sdk.opens, 2, 'media button re-initialises the SDK on each open');
    assert.strictEqual(picks.length, 2, 'media button inserted both picks');
    // Private local storage in the classic editor: nothing inserted, one visible notice.
    mctx.__fetch = () => Promise.resolve({ ok: false, json: () => ({}) });
    click({ preventDefault() {} });
    sdk.pick([{ url: 'http://x/api/fm/stream?token=T', permanent_url: null, name: 'p1.png' }, { url: 'http://x/api/fm/img?a=1&token=T', name: 'p2.png' }]); await tick();
    assert.strictEqual(picks.length, 2, 'no token link was inserted');
    assert.strictEqual(mctx.alerts.length, 1, 'one combined notice for the whole selection');
    assert.ok(mctx.alerts[0].includes('p1.png') && mctx.alerts[0].includes('p2.png'));
    // A public disk without the bridge still inserts its plain URL.
    click({ preventDefault() {} }); sdk.pick({ url: 'http://x/storage/uploads/c.png', permanent_url: 'http://x/storage/uploads/c.png', name: 'c.png' }); await tick();
    assert.strictEqual(picks.length, 3);
    assert.ok(picks[2].includes('src="http://x/storage/uploads/c.png"'), picks[2]);
    console.log('block-picker OK');
})().catch(e => { console.error(e); process.exit(1); });
