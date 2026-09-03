import { execFileSync } from 'node:child_process';

import { COMPOSE_FILE } from './env';

/**
 * The two things the suite has to ask the compose stack directly.
 *
 * Everything else in this suite goes through the browser or the public HTTP
 * API, which is the point of an end-to-end test. These two do not, and each has
 * a reason:
 *
 *  1. `isServiceRunning('horizon')` — the analysis pipeline is a queue worker,
 *     so a stack without Horizon produces a journey that ingests comments and
 *     never analyses them. That failure looks like a broken assertion thirty
 *     seconds later; global setup turns it into one sentence up front.
 *
 *  2. `setQuotaLimit()` — the paywall needs an exhausted quota, and a fresh free
 *     plan allows two hundred analyses (`config/quota.php`). Lowering the plan
 *     limit is exactly the lever `DemoCompanySeeder` pulls for the same reason
 *     ("Low on purpose... demonstrating the paywall at that number means
 *     ingesting two hundred analyses first"), and there is no API for it — a
 *     tenant cannot raise or lower its own quota, which is the correct design.
 *     So the suite writes the number the same way the seeder does: to the
 *     database, for the one company this run created, and never for any other.
 *     Nothing about the application is weakened; a row's data is changed, not
 *     the code that reads it.
 */

function compose(args: string[]): string {
  return execFileSync('docker', ['compose', '-f', COMPOSE_FILE, ...args], {
    cwd: process.cwd(),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  });
}

/** True when the named compose service has a running container. */
export function isServiceRunning(service: string): boolean {
  try {
    return compose(['ps', '--status', 'running', '--format', '{{.Service}}', service]).trim() !== '';
  } catch {
    return false;
  }
}

function psql(sql: string): string {
  return compose(['exec', '-T', 'postgres', 'psql', '-U', 'omnihear', '-d', 'omnihear', '-At', '-c', sql]);
}

/**
 * Sets `companies.quota_limit` for a single company id.
 *
 * Both values are checked to be integers before they are put in the statement:
 * they come from an API response and a constant rather than from user input, but
 * a helper that interpolates into SQL should not depend on its callers for that.
 * The `WHERE id =` is not optional — a statement that could touch a second row
 * has no business in a test.
 */
export function setQuotaLimit(companyId: number, limit: number): void {
  if (!Number.isInteger(companyId) || companyId <= 0) {
    throw new Error(`Refusing to update a company with a non-integer id: ${String(companyId)}`);
  }
  if (!Number.isInteger(limit) || limit < 0) {
    throw new Error(`Refusing to set a non-integer quota limit: ${String(limit)}`);
  }

  // -At gives unaligned, header-less output, but psql still writes the command
  // tag ("UPDATE 1") after the returned row, so only the first line is the id.
  const output = psql(`UPDATE companies SET quota_limit = ${limit} WHERE id = ${companyId} RETURNING id;`);
  const [returned] = output.trim().split(/\r?\n/);

  if (returned?.trim() !== String(companyId)) {
    throw new Error(`Expected to update company ${companyId}, psql returned "${output.trim()}"`);
  }
}
