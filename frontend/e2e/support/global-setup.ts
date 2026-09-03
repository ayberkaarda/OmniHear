import { request } from '@playwright/test';

import { AI_URL, API_URL, MAILPIT_URL } from './env';
import { isServiceRunning } from './stack';

/**
 * Preflight.
 *
 * The journey test touches four services and a queue worker. When one of them
 * is down, the failure surfaces as a click that does nothing or a row that never
 * appears — a symptom several steps away from the cause. Everything checked here
 * is checked so that the run fails in one sentence instead.
 *
 * Nothing here creates data, and nothing here starts a container: a suite that
 * silently repairs its own environment hides the environment it needs.
 */

interface Probe {
  name: string;
  url: string;
  hint: string;
}

const PROBES: Probe[] = [
  {
    name: 'backend',
    url: `${API_URL}/api/health`,
    hint: 'docker compose -f infra/docker-compose.dev.yml up -d backend'
  },
  {
    name: 'ai-service',
    url: `${AI_URL}/health`,
    hint: 'docker compose -f infra/docker-compose.dev.yml up -d ai-service'
  },
  {
    name: 'mailpit',
    url: `${MAILPIT_URL}/api/v1/messages`,
    hint: 'docker compose -f infra/docker-compose.dev.yml up -d mailpit'
  }
];

export default async function globalSetup(): Promise<void> {
  const api = await request.newContext();
  const failures: string[] = [];

  for (const probe of PROBES) {
    try {
      const response = await api.get(probe.url, { timeout: 10_000 });
      if (!response.ok()) {
        failures.push(`${probe.name} answered ${response.status()} at ${probe.url} — start it with: ${probe.hint}`);
      }
    } catch (error) {
      const reason = error instanceof Error ? error.message.split('\n')[0] : String(error);
      failures.push(`${probe.name} is unreachable at ${probe.url} (${reason}) — start it with: ${probe.hint}`);
    }
  }

  await api.dispose();

  // Horizon has no HTTP surface of its own here, and its absence is the one
  // failure that still lets every early assertion pass: comments are ingested by
  // the sync request itself, but AnalyzeFeedbackJob only ever runs on the queue.
  if (!isServiceRunning('horizon')) {
    failures.push(
      'the horizon container is not running, so AnalyzeFeedbackJob will never execute and nothing will be analysed ' +
        '— start it with: docker compose -f infra/docker-compose.dev.yml up -d horizon'
    );
  }

  if (failures.length > 0) {
    throw new Error(
      ['The E2E stack is not ready:', ...failures.map((line) => `  - ${line}`)].join('\n')
    );
  }
}
