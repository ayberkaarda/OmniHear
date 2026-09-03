/**
 * Every address and knob the E2E suite needs, in one place.
 *
 * Defaults describe the stack `infra/docker-compose.dev.yml` brings up on a
 * developer machine. CI overrides nothing today, which is deliberate: a CI job
 * that has to redefine the ports is a CI job that can pass against a different
 * stack than the one a developer runs.
 */

function fromEnv(name: string, fallback: string): string {
  const value = process.env[name];
  return value === undefined || value.trim() === '' ? fallback : value.trim().replace(/\/+$/, '');
}

/** Where the SPA is served. `playwright.config.ts` also starts it here. */
export const BASE_URL = fromEnv('E2E_BASE_URL', 'http://localhost:4200');

/** The Laravel API. Must match `src/environments/environment.ts` — the browser calls it directly. */
export const API_URL = fromEnv('E2E_API_URL', 'http://localhost:8000');

/** The FastAPI analyzer. Only probed in global setup; the suite never calls it. */
export const AI_URL = fromEnv('E2E_AI_URL', 'http://localhost:8001');

/** Mailpit's HTTP API. This is how the suite reads the verification link. */
export const MAILPIT_URL = fromEnv('E2E_MAILPIT_URL', 'http://localhost:8025');

/** Compose file used for the two container commands the suite runs (psql, horizon check). */
export const COMPOSE_FILE = fromEnv('E2E_COMPOSE_FILE', '../infra/docker-compose.dev.yml');

/**
 * Mail domain for the throwaway account each run registers.
 *
 * `.test` is reserved (RFC 6761) and appears on neither of the two registration
 * blocklists — `config/registration.php` refuses throwaway mailbox providers and
 * the big free consumer providers, so `gmail.com` here would fail with a 422
 * that has nothing to do with what the test is checking.
 */
export const EMAIL_DOMAIN = fromEnv('E2E_EMAIL_DOMAIN', 'omnihear-e2e.test');

/** Meets `Password::min(12)`; `uncompromised()` is production-only, so no network call. */
export const PASSWORD = 'omnihear-e2e-2026';

/**
 * The quota the registered tenant is dropped to before its first sync.
 *
 * The fixture connector serves five comments (two pages, three plus two), so a
 * limit of three means three analyses land and two accumulate in
 * `pending_analysis` — spec 7.4's "nothing is deleted" — and the paywall is
 * reachable without ingesting the two hundred a fresh free plan allows.
 */
export const QUOTA_LIMIT = 3;

/** Comments the fixture connector serves in one run: `tests/Fixtures/platforms/fixture/default`. */
export const FIXTURE_COMMENTS = 5;

/**
 * A mailbox nothing else in the stack will ever write to.
 *
 * Unique per run so a re-run cannot collide with the previous one's account
 * (`users.email` is unique) or read the previous one's verification mail.
 */
export function uniqueEmail(): string {
  const stamp = Date.now().toString(36);
  const noise = Math.random().toString(36).slice(2, 8);
  return `e2e-${stamp}-${noise}@${EMAIL_DOMAIN}`;
}
