import { defineConfig, devices } from '@playwright/test'
import { e2eEnvironment } from './e2e/environment'

const port = 4173
const ci = Boolean(process.env.CI)
// Laravel's port: 8000 by default (CI); another one locally when 8000 is taken (E2E_API_PORT=8010).
const apiPort = Number(process.env.E2E_API_PORT ?? 8000)

// End-to-end runs against the production build (npm run build) served like nginx serves it, with
// Laravel on the same origin and its own freshly migrated database. AI is always faked in e2e.
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  fullyParallel: true,
  // The local PHP server is single-threaded on Windows.
  workers: ci ? 2 : 1,
  forbidOnly: ci,
  retries: ci ? 1 : 0,
  reporter: ci ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      command: `php artisan serve --host=127.0.0.1 --port=${apiPort}`,
      cwd: '..',
      url: `http://127.0.0.1:${apiPort}/up`,
      env: e2eEnvironment,
      // Always our own server: a leftover one could point at the development database.
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: `node e2e/serve.mjs --port=${port}`,
      url: `http://127.0.0.1:${port}/`,
      env: { FEEDGENIX_BACKEND_URL: `http://127.0.0.1:${apiPort}` },
      reuseExistingServer: !ci,
    },
  ],
})
