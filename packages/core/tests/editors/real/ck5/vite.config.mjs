import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const editors = path.resolve(here, '../..');
const packages = path.resolve(editors, '../../..');

// Bundles the CKEditor 5 adapter SOURCE (packages/ckeditor5/src) with the real
// ckeditor5 package and the npm-style `fluxfiles` SDK import, the way a consumer would.
export default defineConfig({
  root: here,
  resolve: {
    alias: [
      { find: '@fluxfiles/ckeditor5', replacement: path.join(packages, 'ckeditor5/src/index.ts') },
      // The adapter source lives outside this dir: point its bare imports at our node_modules.
      { find: /^ckeditor5$/, replacement: path.join(editors, 'node_modules/ckeditor5/dist/ckeditor5.js') },
      { find: /^fluxfiles$/, replacement: path.join(editors, 'node_modules/fluxfiles/fluxfiles.js') },
    ],
  },
  optimizeDeps: { include: ['@fluxfiles/ckeditor5'] },
  server: { host: '127.0.0.1', strictPort: true, fs: { allow: [path.resolve(packages, '..')] } },
});
