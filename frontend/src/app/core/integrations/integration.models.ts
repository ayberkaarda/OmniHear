/**
 * Integration shapes — `docs/contracts/wave2-seams.md` section 3 (F4).
 *
 * There is no `credentials` field on `Integration`, and that absence is the
 * point: invariant I5 says connector secrets are never serialized, in any
 * shape, not even back to the owner who just wrote them. The only place
 * credentials appear in this codebase is `IntegrationWriteBody`, which travels
 * one way — browser to server.
 */

/** `Integration::PLATFORMS` in the backend model. */
export const PLATFORMS = ['appstore', 'googleplay', 'zendesk', 'trustpilot', 'email', 'social', 'fixture'] as const;
export type Platform = (typeof PLATFORMS)[number];

/** `Integration::STATUSES`. `error` is a system verdict and cannot be set by hand. */
export const INTEGRATION_STATUSES = ['active', 'error', 'paused'] as const;
export type IntegrationStatus = (typeof INTEGRATION_STATUSES)[number];

/**
 * Platforms the API will actually accept on create.
 *
 * `config/connectors.php` gates `POST /integrations` on having a connector
 * class, and the schema deliberately allows more platform values than the
 * factory does. No endpoint exposes that list, so it is mirrored here; a
 * platform without a connector would be refused with `422 VALIDATION_ERROR`,
 * which is a confusing way to learn it is not available yet.
 */
export const CONNECTABLE_PLATFORMS: readonly Platform[] = ['appstore', 'zendesk'];

/** Non-secret connector configuration: app id, country, marketplace, ... */
export type IntegrationSettings = Readonly<Record<string, string>>;

export interface Integration {
  readonly id: number;
  readonly platform: Platform;
  readonly status: IntegrationStatus;
  readonly settings: IntegrationSettings;
  readonly last_synced_at: string | null;
  readonly sync_error: string | null;
  readonly feedback_count: number;
  readonly created_at: string | null;
}

/** `{ "integration": {...} }` — contract section 1: no `data` wrapper on a single resource. */
export interface IntegrationResponse {
  readonly integration: Integration;
}

/** Request body for POST and PATCH. Write-only; nothing here is ever read back. */
export interface IntegrationWriteBody {
  readonly platform?: Platform;
  readonly settings?: Record<string, string>;
  readonly credentials?: Record<string, string>;
  readonly status?: Exclude<IntegrationStatus, 'error'>;
}

/**
 * The settings each platform's connector requires, mirroring
 * `config/connectors.php` `required_settings`. Used to build the create form;
 * the server validates them again and remains the authority.
 */
export const REQUIRED_SETTINGS: Readonly<Record<string, readonly string[]>> = {
  appstore: ['app_id', 'country'],
  zendesk: ['subdomain'],
  googleplay: [],
  trustpilot: [],
  email: [],
  social: [],
  fixture: []
};

/**
 * The secrets each connector needs, mirroring `required_credentials` in the
 * same config. They are sent on create and on a deliberate rotation, and are
 * never read back — `Integration` has no field for them because the API has no
 * key for them (invariant I5).
 */
export const REQUIRED_CREDENTIALS: Readonly<Record<string, readonly string[]>> = {
  appstore: [],
  zendesk: ['email', 'api_token'],
  googleplay: [],
  trustpilot: [],
  email: [],
  social: [],
  fixture: []
};
