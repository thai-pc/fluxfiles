import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig({
  plugins: [vue()],
  root: path.resolve('vue-host'),
  resolve: {
    alias: {
      '@fluxfiles/vue': path.resolve('../../../vue/src/index.ts'),
    },
  },
  server: { host: '127.0.0.1', strictPort: true },
});
