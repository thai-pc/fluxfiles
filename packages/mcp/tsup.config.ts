import { defineConfig } from 'tsup';

// src/cli.ts starts with a `#!/usr/bin/env node` hashbang, which esbuild keeps,
// so dist/cli.js is directly executable as the package `bin`.
export default defineConfig({
  entry: ['src/index.ts', 'src/cli.ts'],
  format: ['esm'],
  target: 'node18',
  dts: { entry: 'src/index.ts' },
  clean: true,
});
