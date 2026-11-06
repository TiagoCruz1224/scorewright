import { defineConfig, devices } from '@playwright/test';

// One browser, one test at a time: the scenarios share a stack started by ./e2e.sh.
export default defineConfig({
  testDir: './tests',
  // The stack is the development one (Symfony in debug mode; on Windows, code on a bind mount): about 1–2 s a page.
  timeout: 360_000,
  expect: { timeout: 20_000 },
  workers: 1,
  forbidOnly: !!process.env.CI,
  reporter: [['list']],
  outputDir: 'test-results',
  use: {
    // The browser runs in the frontend container's network: the Vite dev server, which forwards /api to Symfony.
    baseURL: process.env.BASE_URL ?? 'http://localhost:5173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } } }],
});
