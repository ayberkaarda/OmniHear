import { Paginated } from '../api/pagination';
import { BillingSummary, Subscription } from '../billing/billing.models';
import { User } from '../auth/auth.models';
import { makeUser } from '../auth/auth.fixtures';
import {
  ApiKey,
  ApiKeyCreatedResponse,
  AppNotification,
  DeviceSession,
  NotificationPreferencesResponse,
  PlatformDescriptor,
  PlatformListResponse
} from './settings.models';

/**
 * Test-only builders for the settings, billing and platform surfaces.
 *
 * They exist for the same reason `auth.fixtures.ts` does: every spec asserts
 * against the exact shape of `docs/contracts/settings-api.md` and
 * `wave2-seams.md` section 3 rather than an ad-hoc object that drifts from the
 * wire format. Not referenced by application code.
 *
 * Note what `makeApiKey` cannot build: there is no plaintext field on it. The
 * value exists only on the create response (`makeApiKeyCreated`), which is the
 * one place the API ever sends it.
 */

export function makeTeamPage(data: readonly User[] = [makeUser()]): Paginated<User> {
  return {
    data,
    meta: { current_page: 1, per_page: 100, total: data.length, last_page: 1 }
  };
}

export function makeApiKey(overrides: Partial<ApiKey> = {}): ApiKey {
  return {
    id: 1,
    name: 'billing export',
    last_used_at: null,
    created_at: '2026-09-02T10:00:00+00:00',
    ...overrides
  };
}

export function makeApiKeyCreated(overrides: Partial<ApiKeyCreatedResponse> = {}): ApiKeyCreatedResponse {
  return {
    api_key: makeApiKey({ id: 7, name: 'staging' }),
    plain_text_token: '7|abcdefghijklmnopqrstuvwxyz',
    ...overrides
  };
}

export function makeDeviceSession(overrides: Partial<DeviceSession> = {}): DeviceSession {
  return {
    id: 11,
    name: 'web',
    last_used_at: '2026-09-02T11:04:03+00:00',
    created_at: '2026-09-01T08:00:00+00:00',
    ...overrides
  };
}

export function makeNotificationPreferences(): NotificationPreferencesResponse {
  return { preferences: { quota_warning: { mail: true, database: true } } };
}

export function makeNotification(overrides: Partial<AppNotification> = {}): AppNotification {
  return {
    id: '9d3f2b1a-0000-4000-8000-000000000001',
    type: 'App\\Notifications\\QuotaWarningNotification',
    data: { used: 160, limit: 200 },
    read_at: null,
    created_at: '2026-09-02T11:04:03+00:00',
    ...overrides
  };
}

export function makeNotificationPage(
  data: readonly AppNotification[] = [makeNotification()]
): Paginated<AppNotification> {
  return {
    data,
    meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1 }
  };
}

/**
 * Mirrors what `config/connectors.php` held when the constant this replaces was
 * last hand-copied: App Store needs two settings and no credentials, Zendesk
 * needs one setting and two credentials.
 */
export function makePlatformList(data?: readonly PlatformDescriptor[]): PlatformListResponse {
  return {
    data: data ?? [
      {
        platform: 'appstore',
        requires_credentials: false,
        settings: [
          { key: 'app_id', required: true, format: 'digits' },
          // `format: null` rather than an absent key, because that is what
          // `PlatformController` sends for a setting with no extra shape rule.
          { key: 'country', required: true, format: null }
        ],
        credentials: []
      },
      {
        platform: 'zendesk',
        requires_credentials: true,
        settings: [{ key: 'subdomain', required: true, format: null }],
        credentials: [
          { key: 'email', required: true },
          { key: 'api_token', required: true }
        ]
      }
    ]
  };
}

export function makeSubscription(overrides: Partial<Subscription> = {}): Subscription {
  return {
    id: 1,
    provider: 'stripe',
    plan: 'pro',
    status: 'active',
    current_period_start: '2026-09-01T00:00:00+00:00',
    current_period_end: '2026-10-01T00:00:00+00:00',
    canceled_at: null,
    created_at: '2026-09-01T00:00:00+00:00',
    ...overrides
  };
}

export function makeBillingSummary(overrides: Partial<BillingSummary> = {}): BillingSummary {
  return {
    subscription: null,
    plan: 'free',
    quota: { limit: 200, used: 12, remaining: 188 },
    ...overrides
  };
}
