import { execFileSync } from 'node:child_process';

import { expect, Page, test } from '@playwright/test';

import { API_URL, COMPOSE_FILE, FIXTURE_COMMENTS, PASSWORD, QUOTA_LIMIT, uniqueEmail } from './support/env';
import { waitForVerificationLink } from './support/mailpit';
import { setQuotaLimit } from './support/stack';

/**
 * Spec section 9's end-to-end journey: register -> integration -> paywall.
 *
 * One test, not seven, and that is the point. Each step consumes the state the
 * previous one produced — the tenant, the confirmed mailbox, the session, the
 * connection, the analysed comments — so splitting it into separate `test()`s
 * would either re-do the whole thing five times (and blow the five-per-hour
 * registration budget in one run) or make each test depend on the leftovers of
 * the last, which is the failure mode this suite exists to avoid. `test.step`
 * gives the same readable breakdown in the report without the shared-state
 * problem.
 *
 * What this covers that no other suite can:
 *  - the verification mail is really sent, really addressed, and its link really
 *    verifies (Jest mocks the endpoint; Pest mocks the mailer);
 *  - the queue actually carries FetchFeedbackJob and AnalyzeFeedbackJob to a
 *    worker in another container, and the analyzer really answers;
 *  - `X-Quota-Remaining` really travels from the middleware to the interceptor
 *    to the guard that raises the wall.
 */

/** The inbox table's column order (`inbox.component.ts` `columns`). */
const STATUS_COLUMN = 7;

const ANALYSED = 'Analysed';
const WAITING = 'Waiting for analysis';

const tableRows = (page: Page) => page.locator('[data-testid="data-table-ready"] tbody tr');

const statusCells = (page: Page) =>
  page.locator(`[data-testid="data-table-ready"] tbody tr td:nth-child(${STATUS_COLUMN})`);

/**
 * Reloads until `read` settles on the expected value.
 *
 * Analysis runs on the queue, so the answer to "is it done yet" always comes
 * from asking again. This stays reload-based even now that the dev stack has a
 * real `REVERB_APP_KEY` (`infra/docker-compose.dev.yml`) and realtime is live:
 * `RealtimeBridge.applyAnalysis` only ever updates a row already in the store
 * (`docs/contracts/realtime.md` section 4 — no re-fetch on broadcast), so a
 * *new* row (this function's other caller, waiting for all five to have
 * arrived) can never appear this way, and this function has to keep working in
 * any environment where realtime is disabled or the socket dropped. The step
 * below this one, not this function, is what actually proves a live update.
 * This waits on a condition, never on a duration — a `waitForTimeout` here would
 * be a guess that passes on a fast machine and fails on a loaded one.
 */
async function reloadUntil(page: Page, read: () => Promise<number>, expected: number, message: string): Promise<void> {
  await expect
    .poll(
      async () => {
        await page.reload();
        await page
          .locator('[data-testid="data-table-ready"], [data-testid="data-table-empty"]')
          .first()
          .waitFor({ state: 'visible' });
        return read();
      },
      { message, timeout: 90_000, intervals: [1_000, 1_000, 2_000, 2_000, 3_000] }
    )
    .toBe(expected);
}

async function countStatus(page: Page, label: string): Promise<number> {
  const cells = await statusCells(page).allTextContents();
  return cells.filter((text) => text.trim() === label).length;
}

/**
 * Reads `path` through the page's own bearer-authed `fetch`, the same way the
 * `X-Quota-Remaining` step below does. Deliberately not `request.get()`:
 * Playwright's `request` fixture has no access to the token the app put in
 * `localStorage`. This never navigates and never touches the DOM — it is how
 * the realtime step below confirms ingestion finished without the one
 * mechanism (`page.reload()`) it exists to prove is unnecessary.
 */
async function fetchAuthed(page: Page, path: string): Promise<{ status: number; body: unknown }> {
  return page.evaluate(
    async ({ apiUrl, requestPath }) => {
      const token = localStorage.getItem('omnihear.token');
      const response = await fetch(`${apiUrl}${requestPath}`, {
        headers: { Authorization: `Bearer ${token ?? ''}`, Accept: 'application/json' }
      });
      return { status: response.status, body: await response.json() };
    },
    { apiUrl: API_URL, requestPath: path }
  );
}

/**
 * Dispatches the real `FeedbackAnalyzed` event — the same class, through the
 * same queue and the same Reverb connection a genuine `AnalyzeFeedbackJob`
 * completion uses — for one feedback row. Same shape of privileged, no-API
 * operation as `setQuotaLimit` in `support/stack.ts`: there is no endpoint for
 * this and there should not be one, so the suite reaches into the backend
 * container the same way, via `docker compose exec`.
 *
 * This is not a fake broadcast. It is the identical `event()` call
 * `AnalyzeFeedbackJob` makes on success (`backend/app/Events/FeedbackAnalyzed.php`),
 * fired for a row this run's own company owns. What it does not do is touch
 * that row's actual `analysis_status` in the database — see the call site for
 * why that is exactly the point.
 */
function dispatchFeedbackAnalyzed(companyId: number, feedbackId: number): void {
  const php =
    `event(new App\\Events\\FeedbackAnalyzed(${companyId}, ${feedbackId}, ` +
    `'negative', -0.5, 'bug', 'e2e-realtime-check'));`;
  execFileSync('docker', ['compose', '-f', COMPOSE_FILE, 'exec', '-T', 'backend', 'php', 'artisan', 'tinker', '--execute', php], {
    cwd: process.cwd(),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  });
}

test('a new company registers, confirms its mailbox, connects a channel and hits the paywall', async ({
  page,
  request
}) => {
  const email = uniqueEmail();
  const companyName = `E2E ${Date.now()}`;
  let companyId = 0;

  // KVKK: nothing this application serves may reach out to a host we do not
  // run. index.html used to pull IBM Plex from fonts.googleapis.com, so every
  // visitor's IP address was handed to a third party before they had consented
  // to anything. The faces are self-hosted now, and this listener is what keeps
  // the next `<link>` from quietly putting one back - it watches the whole
  // journey, not one page.
  const offOrigin: string[] = [];
  page.on('request', (request) => {
    const host = new URL(request.url()).hostname;
    if (host !== 'localhost' && host !== '127.0.0.1' && host !== '::1') {
      offOrigin.push(request.url());
    }
  });

  await test.step('register a company through the sign-up form', async () => {
    await page.goto('/auth/register');
    await expect(page.getByRole('heading', { name: 'Create your OmniHear account' })).toBeVisible();

    await page.getByLabel('Your name').fill('E2E Owner');
    await page.getByLabel('Company name').fill(companyName);
    await page.getByLabel('Email address').fill(email);
    // Two password inputs, and their labels share a word; position is
    // unambiguous where a substring match is not.
    await page.locator('input[type="password"]').first().fill(PASSWORD);
    await page.getByLabel('Repeat password').fill(PASSWORD);

    const registered = page.waitForResponse(
      (response) => response.url().includes('/api/v1/auth/register') && response.request().method() === 'POST'
    );
    await page.getByRole('button', { name: 'Create account' }).click();
    const response = await registered;

    // A 429 here is the `auth-register` limiter (five per hour per IP), not a
    // product failure — saying so beats an assertion about a form field.
    expect(
      response.status(),
      `POST /auth/register answered ${response.status()}. 429 means the five-per-hour registration limiter is spent.`
    ).toBe(201);

    const body = (await response.json()) as { company: { id: number; quota_limit: number } };
    companyId = body.company.id;
    expect(companyId).toBeGreaterThan(0);
    // The free plan starts at config/quota.php's 200 — this is the number the
    // paywall step has to get around.
    expect(body.company.quota_limit).toBe(200);

    await expect(page).toHaveURL(/\/auth\/verify-email$/);
    await expect(page.getByTestId('verify-awaiting')).toBeVisible();
  });

  await test.step('the tenant surface is closed until the mailbox is confirmed', async () => {
    // Spec 7.1 is enforced, not advisory: /app/inbox is behind `verified`, so an
    // unverified session is bounced back to the confirmation screen by the
    // interceptor that handles 403 EMAIL_NOT_VERIFIED. If this ever stops being
    // true, every step below would still pass and the suite would be proving
    // nothing about verification.
    await page.goto('/app/inbox');
    await expect(page).toHaveURL(/\/auth\/verify-email$/);
  });

  await test.step('open the link from the verification mail', async () => {
    const link = await waitForVerificationLink(request, email);
    expect(link).toContain('/auth/verify-email?');
    expect(link).toMatch(/[?&]signature=/);

    await page.goto(link);
    await expect(page.getByTestId('verify-success')).toBeVisible();
  });

  await test.step('sign out, then sign back in with the registered credentials', async () => {
    await page.getByRole('link', { name: 'Go to your dashboard' }).click();
    await expect(page).toHaveURL(/\/app\/overview$/);
    // The banner is the shell's rendering of an unconfirmed mailbox. Its absence
    // is the UI half of the verification having taken.
    await expect(page.getByTestId('verify-banner')).toHaveCount(0);

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/$/);

    await page.goto('/auth/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/\/app\/overview$/);
    await expect(page.getByText(companyName)).toBeVisible();
  });

  await test.step('the browser is allowed to read X-Quota-Remaining across the origin boundary', async () => {
    // The SPA is served from :4200 and the API answers on :8000, so every call
    // the app makes is cross-origin. A browser hands JavaScript only the
    // response headers named in `Access-Control-Expose-Headers`, and the
    // framework default for that list is empty - so `X-Quota-Remaining` was set
    // by the middleware, arrived on the wire, and was invisible to
    // `quotaInterceptor`, which read null on every response. The meter appeared
    // to work only because `/auth/me` repeats the number in its body.
    //
    // Nothing else in the suite can catch that. Jest's HttpTestingController
    // has no CORS at all, and `page.waitForResponse` reads the wire rather than
    // what the page is permitted to see. Only an in-page `fetch` is subject to
    // the rule being tested.
    const seen = await page.evaluate(async (apiUrl) => {
      const token = localStorage.getItem('omnihear.token');
      const response = await fetch(`${apiUrl}/api/v1/auth/me`, {
        headers: { Authorization: `Bearer ${token ?? ''}`, Accept: 'application/json' }
      });

      return {
        status: response.status,
        quota: response.headers.get('X-Quota-Remaining'),
        correlationId: response.headers.get('X-Correlation-Id')
      };
    }, API_URL);

    expect(seen.status).toBe(200);
    expect(seen.quota, 'X-Quota-Remaining is not in Access-Control-Expose-Headers').not.toBeNull();
    expect(Number.parseInt(seen.quota ?? '', 10)).toBeGreaterThan(0);
    expect(seen.correlationId, 'X-Correlation-Id is not in Access-Control-Expose-Headers').not.toBeNull();
  });

  await test.step('connect a fixture channel', async () => {
    await page.goto('/app/integrations');
    await expect(page.getByText('No channel is connected yet')).toBeVisible();

    await page.getByRole('button', { name: 'Connect a channel' }).click();
    // The platform list is served by GET /integrations/platforms, so this select
    // is populated from config/connectors.php rather than a copy of it.
    await page.getByLabel('Platform').selectOption('fixture');
    await page.getByLabel('fixture_set').fill('default');

    const created = page.waitForResponse(
      (response) =>
        /\/api\/v1\/integrations$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST'
    );
    await page.getByRole('button', { name: 'Save' }).click();
    expect((await created).status()).toBe(201);

    await expect(page.getByRole('heading', { name: 'Fixture' })).toBeVisible();
  });

  await test.step('drop the plan limit below what the channel is about to deliver', async () => {
    // The lever DemoCompanySeeder pulls, applied to this run's own company: the
    // fixture connector serves five comments, so a limit of three exhausts the
    // quota part-way through the batch. There is no API for this and there
    // should not be one — a tenant cannot set its own quota.
    setQuotaLimit(companyId, QUOTA_LIMIT);
  });

  await test.step('sync the channel', async () => {
    const synced = page.waitForResponse(
      (response) => /\/integrations\/\d+\/sync$/.test(new URL(response.url()).pathname)
    );
    await page.getByRole('button', { name: 'Sync now' }).click();
    // 202: the request only queues FetchFeedbackJob. Everything after this
    // happens in the horizon container.
    expect((await synced).status()).toBe(202);
  });

  await test.step('the comments arrive in the inbox and three of them are analysed', async () => {
    await page.goto('/app/inbox');

    await reloadUntil(
      page,
      () => tableRows(page).count(),
      FIXTURE_COMMENTS,
      'the five fixture comments never appeared in the inbox — is the horizon container running?'
    );

    // Invariant I4, both halves at once: the counter stopped at the limit, and
    // the two comments it could not pay for are still here, waiting rather than
    // deleted. A run that dropped them would show three rows, not five.
    await reloadUntil(
      page,
      () => countStatus(page, ANALYSED),
      QUOTA_LIMIT,
      'exactly three comments should have been analysed before the quota ran out'
    );
    expect(await countStatus(page, WAITING)).toBe(FIXTURE_COMMENTS - QUOTA_LIMIT);
  });

  await test.step(
    'a feedback.analyzed broadcast updates an inbox row live, with no page.reload()',
    async () => {
      // This step makes no navigation of its own: the page above is already
      // sitting on /app/inbox, freshly reloaded, showing three analysed rows
      // and two stuck in `pending_analysis` forever (I4 — quota exhausted,
      // nothing deleted). Nothing from here on calls page.reload() or
      // page.goto(); whatever changes on screen has to arrive over the socket
      // RealtimeBridge opened when this page last loaded.
      //
      // What this deterministically dispatches, and why: the natural race
      // between "ingestion is done" and "quota's three analyses are done" is
      // not winnable from here to demonstrate a *live* transition — measured
      // twice against a freshly recreated ai-service container, all three
      // quota-permitted analyses had already landed before any poll from this
      // file could catch one mid-flight, because this fixture set is small and
      // the queue is fast once the model is warm. The suite's own reload-based
      // waits just above are what prove *analysis* is real. What is left to
      // prove is *delivery*: that the exact `FeedbackAnalyzed` event
      // (`backend/app/Events/FeedbackAnalyzed.php`) — same class, same queue,
      // same Reverb connection a genuine completion uses — reaches this
      // browser and RealtimeBridge applies it to a row already on screen. So
      // this step dispatches that real event for one of the two rows quota
      // guarantees will never be analysed for real, from a channel this run's
      // company genuinely owns. It does not touch the row's actual database
      // state, and the SPA forgets the client-only update the moment it is
      // next reloaded — as the next step's fresh /app/overview navigation
      // immediately does.
      const baseline = await countStatus(page, ANALYSED);
      expect(baseline, 'expected exactly the quota limit analysed before this step').toBe(QUOTA_LIMIT);

      const { status, body } = await fetchAuthed(page, `/api/v1/feedbacks?per_page=${FIXTURE_COMMENTS}`);
      expect(status, 'could not read the inbox back through the API').toBe(200);
      const pending = (body as { data: { id: number; analysis_status: string }[] }).data.find(
        (row) => row.analysis_status === 'pending_analysis'
      );
      expect(pending, 'no pending_analysis row left to demonstrate a live update on').toBeTruthy();

      dispatchFeedbackAnalyzed(companyId, (pending as { id: number }).id);

      await expect
        .poll(() => countStatus(page, ANALYSED), {
          message: 'no feedback.analyzed broadcast updated a row without a reload — realtime never delivered one',
          timeout: 30_000,
          intervals: [500, 500, 1_000, 1_000, 2_000]
        })
        .toBeGreaterThan(baseline);
    }
  );

  await test.step('the paywall is raised and the quota meter agrees with it', async () => {
    await page.goto('/app/overview');

    const paywall = page.getByRole('alertdialog');
    await expect(paywall).toBeVisible();
    await expect(paywall).toContainText('Plan limit reached:');
    await expect(paywall).toContainText(`${QUOTA_LIMIT} / ${QUOTA_LIMIT}`);
    // The owner is the role that can act on it (spec 7.5).
    await expect(paywall.getByText('Upgrade plan')).toBeVisible();
    await expect(page.getByTestId('paywall-owner-only')).toHaveCount(0);

    // Not a toast and not dismissible: the wall is the only screen the user can
    // move forward from.
    await page.keyboard.press('Escape');
    await expect(paywall).toBeVisible();

    const meter = page.getByTestId('quota-meter');
    await expect(meter).toContainText(`${QUOTA_LIMIT} / ${QUOTA_LIMIT}`);
    await expect(meter).toContainText('Remaining:');
    await expect(meter).toContainText('0');
  });

  await test.step('the whole journey talked to nobody but us', async () => {
    expect(offOrigin, `the browser contacted third-party hosts: ${offOrigin.join(', ')}`).toEqual([]);
  });
});
