import { defineConfig, devices } from '@playwright/test';

import { BASE_URL } from './e2e/support/env';

/**
 * End-to-end suite — spec section 9: "Playwright (E2E: register -> integration
 * -> paywall)".
 *
 * This is the only suite that runs the product rather than a piece of it: a real
 * browser against the Angular app, against Laravel, against the queue worker,
 * against the FastAPI analyzer and against a real mailbox. Jest covers the
 * components; Pest and pytest cover the services. Nothing but this covers the
 * seams between them.
 *
 * # Determinism
 *
 * - `workers: 1`, `fullyParallel: false`. The journey registers a tenant, drops
 *   its quota and syncs it; two of those at once share a rate limiter and a
 *   queue, and the second one's failure would be about the first one.
 *
 * - `retries: 0`, in both environments, and this is a deliberate departure from
 *   the usual CI default. `POST /auth/register` is rate limited to five per hour
 *   per IP (`auth-register` in AppServiceProvider). A retry does not re-run a
 *   flake here; it spends a quarter of the hour's registration budget and can
 *   turn one real failure into a `429` on the next honest run. A test that needs
 *   a retry to pass is a test to fix.
 *
 * - No `waitForTimeout` anywhere in the suite. Analysis happens on the queue, so
 *   the journey waits for the state it expects (`expect.poll` around a reload),
 *   never for a duration.
 *
 * # Where the app comes from
 *
 * `webServer` starts `ng serve` on the host and reuses an already-running one
 * locally. The compose file's `frontend` service is deliberately not used: it
 * mounts the host `frontend/` into a Linux image, so on a Windows host it would
 * be running the app against Windows-built native binaries in `node_modules`.
 */
export default defineConfig({
  testDir: './e2e',
  // Not `*.spec.ts`: jest.config.js sweeps the whole project for that pattern
  // and would try to run these in jsdom. Two runners, two patterns, no overlap.
  testMatch: '**/*.e2e.ts',

  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env['CI'],

  // One journey covers register -> verify -> sign in -> integrate -> sync ->
  // inbox -> paywall, and the analyzer loads its ONNX weights on the first
  // request of the stack's life. The budget is for that first run.
  timeout: 180_000,
  expect: { timeout: 15_000 },

  reporter: process.env['CI'] ? [['list'], ['html', { open: 'never' }]] : [['list']],

  globalSetup: './e2e/support/global-setup.ts',

  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 15_000,
    navigationTimeout: 30_000
  },

  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

  webServer: {
    command: 'npm start -- --port 4200',
    url: BASE_URL,
    reuseExistingServer: !process.env['CI'],
    timeout: 300_000,
    stdout: 'ignore',
    stderr: 'pipe'
  }
});
