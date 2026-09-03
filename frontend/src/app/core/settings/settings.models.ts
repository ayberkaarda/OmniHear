/**
 * Wire shapes for `/api/v1/settings/**` — `docs/contracts/settings-api.md`.
 *
 * Field names are the API's, unchanged, so a response can be assigned without
 * a translation layer. Nothing here is invented: where the contract does not
 * state a response body (the invitation endpoint), this file declares no type
 * for it and the store treats the response as opaque rather than guessing at a
 * shape the server may not send.
 */
import { User, UserRole } from '../auth/auth.models';
import { Platform } from '../integrations/integration.models';

/* -------------------------------------------------------------------------- */
/* Profile — contract section 1                                               */
/* -------------------------------------------------------------------------- */

export interface ProfileResponse {
  readonly user: User;
  /**
   * `true` when the submitted change moved the account to a new address, which
   * un-verifies it. The SPA reacts to this rather than discovering it on the
   * next `403 EMAIL_NOT_VERIFIED`.
   */
  readonly email_verification_required?: boolean;
}

export interface ProfileUpdateBody {
  readonly name?: string;
  readonly email?: string;
}

export interface PasswordUpdateBody {
  readonly current_password: string;
  readonly password: string;
  readonly password_confirmation: string;
}

/* -------------------------------------------------------------------------- */
/* Team — contract section 2                                                  */
/* -------------------------------------------------------------------------- */

export interface InvitationBody {
  readonly email: string;
  readonly role: UserRole;
}

export interface RoleUpdateBody {
  readonly role: UserRole;
}

/**
 * Spec section 8's role ladder, most privileged first. Used for the two rules
 * the UI enforces before the server has to: nobody invites above their own
 * role, and only an `owner` may grant `owner`.
 */
export const ROLE_RANK: Readonly<Record<UserRole, number>> = {
  owner: 3,
  admin: 2,
  member: 1
};

export function rolesAssignableBy(actor: UserRole | null): readonly UserRole[] {
  if (actor === null) {
    return [];
  }
  return (['owner', 'admin', 'member'] as const).filter((role) => ROLE_RANK[role] <= ROLE_RANK[actor]);
}

/* -------------------------------------------------------------------------- */
/* API keys and device sessions — contract section 3                          */
/* -------------------------------------------------------------------------- */

/**
 * An API key and a device session are both Sanctum tokens, and telling them
 * apart is the whole point of this section of the contract: they are separated
 * by ability (`['api']` versus `['session']`) and listed by two different
 * endpoints. Two types rather than one alias, so no screen can pass a row of
 * one kind to the other's revoke call.
 */
export interface ApiKey {
  readonly id: number;
  readonly name: string;
  readonly last_used_at: string | null;
  readonly created_at: string | null;
}

export interface DeviceSession {
  readonly id: number;
  readonly name: string;
  readonly last_used_at: string | null;
  readonly created_at: string | null;
}

export interface ApiKeyListResponse {
  readonly data: readonly ApiKey[];
}

export interface DeviceSessionListResponse {
  readonly data: readonly DeviceSession[];
}

export interface ApiKeyCreateBody {
  readonly name: string;
}

/** The one and only time the plaintext value exists outside the server. */
export interface ApiKeyCreatedResponse {
  readonly api_key: ApiKey;
  readonly plain_text_token: string;
}

/* -------------------------------------------------------------------------- */
/* Notifications — contract section 4                                         */
/* -------------------------------------------------------------------------- */

/** Spec 7.3 requires the quota warning by email *and* in-app. */
export interface NotificationChannels {
  readonly mail: boolean;
  readonly database: boolean;
}

/**
 * Keyed by notification type. The contract names `quota_warning` and leaves the
 * map open, so the screen renders whatever keys the server sends instead of a
 * hard-coded list that would hide a newly added one.
 */
export type NotificationPreferences = Readonly<Record<string, NotificationChannels>>;

export interface NotificationPreferencesResponse {
  readonly preferences: NotificationPreferences;
}

/**
 * A row of Laravel's `notifications` table. `id` is a string: that table's key
 * is a uuid, unlike every other id in this API.
 */
export interface AppNotification {
  readonly id: string;
  readonly type: string;
  readonly data: Readonly<Record<string, unknown>>;
  readonly read_at: string | null;
  readonly created_at: string | null;
}

/* -------------------------------------------------------------------------- */
/* Platforms — contract section 5                                             */
/* -------------------------------------------------------------------------- */

export interface PlatformField {
  readonly key: string;
  readonly required: boolean;
  /**
   * The name of the extra shape rule the server enforces (`digits`, ...), or
   * `null` when the setting is validated as a plain string. `PlatformController`
   * sends the key with a `null` value rather than omitting it, so the type says
   * `null` as well as optional — a declaration of `string | undefined` would be
   * a lie about what arrives.
   *
   * Advisory only: the server validates again and stays the authority.
   */
  readonly format?: string | null;
}

/**
 * What the connector registry actually holds, published so the integration form
 * stops being a hand-copy of `config/connectors.php`. `credentials` lists key
 * names and whether they are required — never a value (invariant I5).
 */
export interface PlatformDescriptor {
  readonly platform: Platform;
  readonly requires_credentials: boolean;
  readonly settings: readonly PlatformField[];
  readonly credentials: readonly PlatformField[];
}

export interface PlatformListResponse {
  readonly data: readonly PlatformDescriptor[];
}
