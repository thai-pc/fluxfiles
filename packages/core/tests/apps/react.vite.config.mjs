import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  plugins: [react()],
  root: path.resolve('react-host'),
  resolve: {
    alias: {
      '@fluxfiles/react': path.resolve('../../../react/src/index.ts'),
    },
  },
  server: { host: '127.0.0.1', strictPort: true },
});
