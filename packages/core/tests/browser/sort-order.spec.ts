import { test, expect } from '@playwright/test';

// The sort/filter getters (filteredFiles / filteredFolders) were rewritten to use one
// cached Intl.Collator + precomputed keys + a memo. This pins their ordering to the
// ORIGINAL per-pair comparator (`localeCompare(b, undefined, {numeric:true})`), over a
// mixed set: numbers, accents, case, ties, equal timestamps and sizes.

test('sorted lists match the reference comparator for every sortBy/sortDir', async ({ page }) => {
  await page.goto('/public/index.html');
  const result = await page.evaluate(() => {
    const names = [
      'file10.txt', 'file2.txt', 'File1.txt', 'file1.txt', 'Zebra.png', 'apple.png', 'Äpfel.png',
      'ärger.mp4', 'éclair.mp3', 'eclair.mp3', 'Éclair2.pdf', '10.doc', '9.doc', '100.doc',
      'a b.txt', 'a-b.txt', 'a_b.txt', 'README', 'readme.md', 'ñandú.jpg', 'nandu.jpg', 'ZZ.txt',
    ];
    const files = names.map((name, i) => ({
      name, key: 'd/' + name, type: 'file',
      size: [0, 5, 5, 100, 100, 7][i % 6], modified: [0, 1000, 1000, 2000][i % 4],
    }));
    const folders = ['b10', 'b2', 'B1', 'Éa', 'ea', 'zeta', 'Alpha', 'alpha'].map((name, i) => ({
      name, key: name, type: 'dir', modified: [5, 5, 9, 1][i % 4],
    }));

    const lc = (a: string, b: string) => a.localeCompare(b, undefined, { numeric: true });
    const icon = (app: any, f: any) => app.fileIcon(f) || '';
    const refFiles = (app: any, by: string, dir: number) => {
      const lower = (f: any) => String(f.name || '').toLowerCase();
      return files.slice().sort((a, b) => {
        let av: any, bv: any;
        if (by === 'date') { av = Number(a.modified || 0); bv = Number(b.modified || 0); }
        else if (by === 'size') { av = Number(a.size || 0); bv = Number(b.size || 0); }
        else if (by === 'type') { av = icon(app, a); bv = icon(app, b); }
        else return lc(lower(a), lower(b)) * dir;
        if (av < bv) return -1 * dir;
        if (av > bv) return 1 * dir;
        return lc(lower(a), lower(b));
      }).map((f) => f.key);
    };
    const refFolders = (by: string, dir: number) => {
      const eff = by === 'size' || by === 'type' ? 'name' : by;
      return folders.slice().sort((a, b) => {
        if (eff === 'date') {
          const av = Number(a.modified || 0), bv = Number(b.modified || 0);
          if (av !== bv) return (av < bv ? -1 : 1) * dir;
        }
        return lc(a.name.toLowerCase(), b.name.toLowerCase()) * dir;
      }).map((f) => f.key);
    };

    const out: Record<string, unknown>[] = [];
    const app: any = (window as any).fluxFilesApp();
    app.files = files; app.folders = folders;
    for (const by of ['name', 'date', 'size', 'type']) {
      for (const sd of ['asc', 'desc']) {
        const dir = sd === 'desc' ? -1 : 1;
        app.sortBy = by; app.sortDir = sd; app._listOrderLocal = true;
        const gotF = app.filteredFiles.map((f: any) => f.key);
        const gotD = app.filteredFolders.map((f: any) => f.key);
        // Second read must hit the memo and return the identical order.
        const again = app.filteredFiles.map((f: any) => f.key);
        out.push({
          by, sd,
          filesOk: JSON.stringify(gotF) === JSON.stringify(refFiles(app, by, dir)),
          foldersOk: JSON.stringify(gotD) === JSON.stringify(refFolders(by, dir)),
          memoOk: JSON.stringify(again) === JSON.stringify(gotF),
        });
      }
    }
    // Server-ordered pages (_listOrderLocal=false) must come back untouched.
    app._listOrderLocal = false; app.sortBy = 'name'; app.sortDir = 'desc';
    const serverOrder = app.filteredFiles.map((f: any) => f.key);
    const serverOk = JSON.stringify(serverOrder) === JSON.stringify(files.map((f) => f.key));
    // Search narrows the list; a changed query must not serve a stale memo.
    app._listOrderLocal = true; app.sortBy = 'name'; app.sortDir = 'asc';
    app.searchQuery = 'eclair';
    const searched = app.filteredFiles.map((f: any) => f.name);
    app.searchQuery = '';
    const full = app.filteredFiles.length;
    return { out, serverOk, searched, full, total: files.length };
  });
  for (const r of result.out as any[]) {
    expect(r, `${r.by}/${r.sd}`).toMatchObject({ filesOk: true, foldersOk: true, memoOk: true });
  }
  expect(result.serverOk).toBe(true);
  expect(result.searched.every((n: string) => n.toLowerCase().includes('eclair'))).toBe(true);
  expect(result.searched).toEqual(["eclair.mp3"]);
  expect(result.full).toBe(result.total);
});

test('isSelected tracks push / splice / replacement of `selected`', async ({ page }) => {
  await page.goto('/public/index.html');
  const r = await page.evaluate(() => {
    const app: any = (window as any).fluxFilesApp();
    const a = { key: 'a' }, b = { key: 'b' }, c = { key: 'c' };
    const seen: boolean[] = [];
    seen.push(app.isSelected(a));
    app.selected.push(a); seen.push(app.isSelected(a), app.isSelected(b));
    app.selected.push(b); seen.push(app.isSelected(b));
    app.selected.splice(0, 1); seen.push(app.isSelected(a), app.isSelected(b));
    app.selected = [c]; seen.push(app.isSelected(b), app.isSelected(c));
    app.selected = []; seen.push(app.isSelected(c));
    return seen;
  });
  expect(r).toEqual([false, true, false, true, false, true, false, true, false]);
});

test('an in-place size change (editor save) re-sorts a size-sorted list', async ({ page }) => {
  await page.goto('/public/index.html');
  const r = await page.evaluate(async () => {
    const app: any = (window as any).fluxFilesApp();
    app.files = [
      { name: 'a.txt', key: 'a.txt', type: 'file', size: 10, modified: 1 },
      { name: 'b.txt', key: 'b.txt', type: 'file', size: 20, modified: 1 },
      { name: 'c.txt', key: 'c.txt', type: 'file', size: 30, modified: 1 },
    ];
    app._listOrderLocal = true; app.sortBy = 'size'; app.sortDir = 'asc';
    const before = app.filteredFiles.map((f: any) => f.key);
    // The editor shares the row object with `files`; a save only changes .size on it.
    app.detailFile = app.files[2];
    app.editorFile = { key: 'c.txt' }; app.editorContent = 'x'; app.editorOriginal = '';
    app.api = async () => ({ size: 5 });
    await app.saveEditor();
    return { before, after: app.filteredFiles.map((f: any) => f.key), size: app.files[2].size };
  });
  expect(r.before).toEqual(['a.txt', 'b.txt', 'c.txt']);
  expect(r.size).toBe(5);
  expect(r.after).toEqual(['c.txt', 'a.txt', 'b.txt']);
});
