import { Company, User } from './auth.models';

/**
 * Test-only builders for the two session resources.
 *
 * They exist so every spec asserts against the exact shape of
 * `docs/contracts/http-api-v1.md` section 4 instead of an ad-hoc object that
 * quietly drifts from the wire format. Not referenced by application code.
 */
export function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    company_id: 1,
    name: 'Ada Lovelace',
    email: 'ada@acme.com',
    role: 'owner',
    email_verified_at: '2026-09-02T11:04:03+00:00',
    two_factor_enabled: false,
    created_at: '2026-09-02T11:04:03+00:00',
    ...overrides
  };
}

export function makeCompany(overrides: Partial<Company> = {}): Company {
  return {
    id: 1,
    name: 'Acme Inc.',
    plan: 'free',
    analyzed_feedback_count: 12,
    quota_limit: 200,
    quota_remaining: 188,
    created_at: '2026-09-02T11:04:03+00:00',
    ...overrides
  };
}
