import { Paginated } from '../api/pagination';
import { Integration } from './integration.models';

/**
 * Test-only builder for the F4 resource.
 *
 * Note what it cannot build: there is no `credentials` key, because the API
 * never sends one (invariant I5). A fixture that carried one would let a spec
 * pass while the application rendered a secret.
 */
export function makeIntegration(overrides: Partial<Integration> = {}): Integration {
  return {
    id: 1,
    platform: 'appstore',
    status: 'active',
    settings: { app_id: '284882215', country: 'tr' },
    last_synced_at: '2026-09-02T11:04:03+00:00',
    sync_error: null,
    feedback_count: 42,
    created_at: '2026-09-02T10:00:00+00:00',
    ...overrides
  };
}

export function makeIntegrationPage(data: readonly Integration[] = [makeIntegration()]): Paginated<Integration> {
  return {
    data,
    meta: { current_page: 1, per_page: 100, total: data.length, last_page: 1 }
  };
}
