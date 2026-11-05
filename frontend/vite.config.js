import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    strictPort: true,
    // The browser only talks to the dev server, which forwards /api to Symfony: one origin, no CORS.
    proxy: { '/api': process.env.API_PROXY_TARGET ?? 'http://localhost:8471' },
    // File events from a Windows bind mount do not reach the container, so Compose turns polling on.
    watch: { usePolling: process.env.WATCH_POLLING === 'true' },
  },
  // Vitest: components and the API client in a simulated browser (src/**/*.test.jsx).
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test-setup.js'],
  },
});
