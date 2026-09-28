import { defineConfig, devices } from '@playwright/test'

const port = 4173
const ci = Boolean(process.env.CI)

// End-to-end runs against the production build (npm run build) served like nginx serves it,
// with Laravel on the same origin. AI is always faked in e2e.
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
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
      command: 'php artisan serve --host=127.0.0.1 --port=8000',
      cwd: '..',
      url: 'http://127.0.0.1:8000/up',
      reuseExistingServer: !ci,
      timeout: 60_000,
    },
    {
      command: `node e2e/serve.mjs --port=${port}`,
      url: `http://127.0.0.1:${port}/`,
      reuseExistingServer: !ci,
    },
  ],
})
