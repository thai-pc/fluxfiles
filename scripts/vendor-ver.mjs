// Stamp the content hashes of the lazily loaded vendor trees into fm.js's
// FM_VENDOR_VER (the ?v= of CodeMirror files). Run by scripts/build-ui.sh.
// Hash = first 10 hex of md5 over, per file sorted by relative path:
//   <relpath> NUL <bytes> NUL
// tests/unit/test-vendor-ver.php recomputes the same value and fails when stale.
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const assets = join(dirname(fileURLToPath(import.meta.url)), '..', 'packages', 'core', 'assets');

function files(dir, rel = '') {
    let out = [];
    for (const name of readdirSync(join(dir, rel))) {
        const r = rel ? rel + '/' + name : name;
        if (statSync(join(dir, r)).isDirectory()) out = out.concat(files(dir, r));
        else out.push(r);
    }
    return out;
}

function treeHash(name) {
    const dir = join(assets, 'vendor', name);
    const h = createHash('md5');
    for (const r of files(dir).sort()) {
        h.update(r + '\0').update(readFileSync(join(dir, r))).update('\0');
    }
    return h.digest('hex').slice(0, 10);
}

const p = join(assets, 'fm.js');
const src = readFileSync(p, 'utf8');
const line = `const FM_VENDOR_VER = { codemirror: '${treeHash('codemirror')}' }; // vendor-ver`;
const out = src.replace(/^const FM_VENDOR_VER = .*\/\/ vendor-ver$/m, line);
if (out === src && !src.includes(line)) {
    console.error('FM_VENDOR_VER marker not found in fm.js');
    process.exit(1);
}
if (out !== src) writeFileSync(p, out);
console.log('==> ' + line);
