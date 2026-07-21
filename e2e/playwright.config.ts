import { defineConfig, devices } from '@playwright/test'

/**
 * E2E suite for the Distributor Management System.
 * Assumes the frontend (Vite :5173) and backend (Laravel :8000, pointed at the
 * mms_auto_test database) are already running. Run with `npm test`.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1, // serial: specs mutate shared DB state, keep it deterministic
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }], ['json', { outputFile: 'test-results/results.json' }]],
  use: {
    // Override with E2E_BASE_URL to point at an isolated dev server
    // (see vite.e2e.config.ts) when port 8000/5173 are contended.
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:5173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 10_000,
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: '.auth/user.json' },
      dependencies: ['setup'],
    },
  ],
})
