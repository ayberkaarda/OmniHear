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

/*
 * There is deliberately no `CONNECTABLE_PLATFORMS` constant here any more.
 *
 * It used to mirror `config/connectors.php` by hand, and it drifted the first
 * time the backend changed: Zendesk was added mid-phase and the mismatch was
 * caught by a person rather than by the build. `GET /api/v1/integrations/platforms`
 * now publishes what the connector registry actually holds, and `PlatformsStore`
 * reads it — the same reason `REQUIRED_SETTINGS` and `REQUIRED_CREDENTIALS` are
 * gone from this file too.
 */

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
