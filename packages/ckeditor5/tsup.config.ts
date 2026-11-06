import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/index.ts'],
  // ESM only: the ckeditor5 package itself ships no CommonJS build.
  format: ['esm'],
  dts: true,
  clean: true,
  // ckeditor5 is a peer dependency and `fluxfiles` (the SDK) a regular one:
  // neither is bundled, so the host app keeps a single copy of each.
  external: ['ckeditor5', 'fluxfiles', /^@ckeditor\//],
});
