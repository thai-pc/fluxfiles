// Behaviour test: the Gutenberg block picker must work on every open and route the pick
// to the block that opened it (the SDK closes itself after a pick, so a one-shot "opened"
// flag left the 2nd open with an empty modal and the 1st block's callback).
// Run: node packages/wordpress/tests/block-picker.test.js
const fs = require('fs'), vm = require('vm'), path = require('path'), assert = require('assert');

const read = f => fs.readFileSync(path.join(__dirname, '../assets', f), 'utf8');
assert(!/\bopened\b/.test(read('block.js')), 'stale single-open flag in block.js');
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
    console, Promise, setTimeout,
    document: {
        getElementById: id => els[id] || null,
        createElement: () => fakeEl(),
        body: { appendChild: e => { els[e.id] = e; } }
    },
    fetch: () => Promise.resolve({ ok: true, json: () => ({ data: { url: 'u', id: 1 } }) })
});
ctx.window = ctx;
ctx.FluxFiles = sdk;
ctx.FluxFilesBlockCfg = { token: 't', endpoint: 'e', attachUrl: '/a' };
let edit;
ctx.wp = {
    blocks: { registerBlockType: (n, d) => { edit = d.edit; } },
    element: { createElement: (t, p) => { if (p && p.variant === 'primary') buttons.push(p.onClick); return {}; } },
    blockEditor: {}, components: {}, i18n: { __: s => s }
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
    console.log('block-picker OK');
})().catch(e => { console.error(e); process.exit(1); });
