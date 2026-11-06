import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    strictPort: true,
    // The browser only talks to the dev server, which forwards /api to Symfony: one origin, no CORS.
    // - changeOrigin false: Symfony sees the Host the client sent, so its same-origin check (CSRF) compares the Origin
    //   with that host for any client, not only for browsers that send Sec-Fetch-Site (SWQ1-06).
    // - xfwd: X-Forwarded-For with the client's address, for the limits by IP (SYMFONY_TRUSTED_PROXIES in compose.yaml).
    proxy: { '/api': { target: process.env.API_PROXY_TARGET ?? 'http://localhost:8471', changeOrigin: false, xfwd: true } },
    // File events from a Windows bind mount do not reach the container, so Compose turns polling on.
    watch: { usePolling: process.env.WATCH_POLLING === 'true' },
  },
  // Vitest: components and the API client in a simulated browser (src/**/*.test.jsx).
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test-setup.js'],
  },
});
