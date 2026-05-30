import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [react(), vue()],
  test: {
    environment: 'jsdom',
    include: ['__tests__/**/*.test.{js,mjs,ts,tsx}'],
    globals: true,
  },
});
