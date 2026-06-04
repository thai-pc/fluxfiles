import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/index.ts'],
  format: ['cjs', 'esm'],
  dts: true,
  clean: true,
  // Ship the React Server Components boundary so the package can be imported
  // directly into a Next.js App Router (Server Component) without the consumer
  // adding their own "use client" wrapper.
  banner: { js: '"use client";' },
});
