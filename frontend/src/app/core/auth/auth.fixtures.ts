import {
  Company,
  RecoveryCodesResponse,
  TwoFactorChallengeResponse,
  TwoFactorEnrolmentResponse,
  User
} from './auth.models';

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

/**
 * The three two-factor response bodies of `docs/contracts/w10-two-factor.md`.
 *
 * Track A's endpoints do not exist while the frontend is built, so these are
 * the only description of the wire the specs are allowed to assert against —
 * an inline object in a spec would be a second, drifting copy of the contract.
 */
export function makeTwoFactorChallenge(
  overrides: Partial<TwoFactorChallengeResponse> = {}
): TwoFactorChallengeResponse {
  return {
    two_factor_required: true,
    challenge_token: '9|challenge-token',
    ...overrides
  };
}

export function makeTwoFactorEnrolment(
  overrides: Partial<TwoFactorEnrolmentResponse> = {}
): TwoFactorEnrolmentResponse {
  return {
    secret: 'JBSWY3DPEHPK3PXP',
    otpauth_url: 'otpauth://totp/OmniHear:ada@acme.com?secret=JBSWY3DPEHPK3PXP&issuer=OmniHear',
    // A real (tiny) base64 SVG, so a spec can assert the prefix an <img src>
    // needs rather than a placeholder string that would never render.
    qr_svg_data_uri:
      'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4=',
    ...overrides
  };
}

/** The eight one-time fallbacks, returned at confirmation and at regeneration. */
export function makeRecoveryCodes(overrides: Partial<RecoveryCodesResponse> = {}): RecoveryCodesResponse {
  return {
    recovery_codes: [
      'a1b2-c3d4',
      'e5f6-g7h8',
      'i9j0-k1l2',
      'm3n4-o5p6',
      'q7r8-s9t0',
      'u1v2-w3x4',
      'y5z6-a7b8',
      'c9d0-e1f2'
    ],
    ...overrides
  };
}
