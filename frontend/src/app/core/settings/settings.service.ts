import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../../environments/environment';
import { MAX_PER_PAGE, Paginated } from '../api/pagination';
import { SessionResponse, User } from '../auth/auth.models';
import {
  ApiKeyCreateBody,
  ApiKeyCreatedResponse,
  ApiKeyListResponse,
  AppNotification,
  DeviceSessionListResponse,
  InvitationBody,
  NotificationPreferences,
  NotificationPreferencesResponse,
  PasswordUpdateBody,
  PlatformListResponse,
  ProfileResponse,
  ProfileUpdateBody,
  RoleUpdateBody
} from './settings.models';

/**
 * Every endpoint of `docs/contracts/settings-api.md`, and nothing else.
 *
 * Two boundaries are load-bearing here:
 *
 * - **Device sessions live under `/auth/tokens`, API keys under
 *   `/settings/api-keys`.** They are different lists of the same kind of
 *   record, separated server-side by ability. Giving them separate methods on
 *   separate base URLs is what stops one screen from revoking the other's rows.
 * - **`/auth/tokens` and `/account` sit outside the `verified` middleware**
 *   (HTTP contract 5a) — revoking a stolen device token must not require a
 *   mailbox the user may have lost. Nothing here may route a session revoke
 *   through the verified surface.
 */
@Injectable({ providedIn: 'root' })
export class SettingsService {
  private readonly http = inject(HttpClient);

  private readonly settingsUrl = `${environment.apiBaseUrl}/v1/settings`;
  private readonly authUrl = `${environment.apiBaseUrl}/v1/auth`;
  private readonly notificationsUrl = `${environment.apiBaseUrl}/v1/notifications`;
  private readonly platformsUrl = `${environment.apiBaseUrl}/v1/integrations/platforms`;

  /* ---------------------------------------------------------------- profile */

  profile(): Observable<ProfileResponse> {
    return this.http.get<ProfileResponse>(`${this.settingsUrl}/profile`);
  }

  updateProfile(body: ProfileUpdateBody): Observable<ProfileResponse> {
    return this.http.patch<ProfileResponse>(`${this.settingsUrl}/profile`, body);
  }

  /** `204` — no body. Revokes every other token and keeps the caller's own. */
  updatePassword(body: PasswordUpdateBody): Observable<void> {
    return this.http.patch<void>(`${this.settingsUrl}/password`, body);
  }

  /* ------------------------------------------------------------------- team */

  team(): Observable<Paginated<User>> {
    return this.http.get<Paginated<User>>(`${this.settingsUrl}/team`, {
      params: new HttpParams().set('per_page', MAX_PER_PAGE)
    });
  }

  /**
   * `201`. The contract does not state a response body, so none is typed: the
   * store refreshes the list rather than reading a shape the server may not
   * send. An invitation is a row, not a user — the team list will not grow
   * until the invitee accepts.
   */
  invite(body: InvitationBody): Observable<unknown> {
    return this.http.post<unknown>(`${this.settingsUrl}/team/invitations`, body);
  }

  /** `owner` only. */
  updateRole(userId: number, body: RoleUpdateBody): Observable<{ user: User }> {
    return this.http.patch<{ user: User }>(`${this.settingsUrl}/team/${userId}`, body);
  }

  /** `204`. `owner` or `admin`, never yourself, never the last owner. */
  removeMember(userId: number): Observable<void> {
    return this.http.delete<void>(`${this.settingsUrl}/team/${userId}`);
  }

  /* --------------------------------------------------------------- api keys */

  apiKeys(): Observable<ApiKeyListResponse> {
    return this.http.get<ApiKeyListResponse>(`${this.settingsUrl}/api-keys`);
  }

  createApiKey(body: ApiKeyCreateBody): Observable<ApiKeyCreatedResponse> {
    return this.http.post<ApiKeyCreatedResponse>(`${this.settingsUrl}/api-keys`, body);
  }

  revokeApiKey(id: number): Observable<void> {
    return this.http.delete<void>(`${this.settingsUrl}/api-keys/${id}`);
  }

  /* -------------------------------------------------------- device sessions */

  deviceSessions(): Observable<DeviceSessionListResponse> {
    return this.http.get<DeviceSessionListResponse>(`${this.authUrl}/tokens`);
  }

  /** Revoking the current token is allowed and ends the session. */
  revokeDeviceSession(id: number): Observable<void> {
    return this.http.delete<void>(`${this.authUrl}/tokens/${id}`);
  }

  /* ---------------------------------------------------------- notifications */

  notificationPreferences(): Observable<NotificationPreferencesResponse> {
    return this.http.get<NotificationPreferencesResponse>(`${this.settingsUrl}/notifications`);
  }

  updateNotificationPreferences(preferences: NotificationPreferences): Observable<NotificationPreferencesResponse> {
    return this.http.patch<NotificationPreferencesResponse>(`${this.settingsUrl}/notifications`, { preferences });
  }

  /** In-app notifications, newest first, scoped to the authenticated user. */
  notifications(): Observable<Paginated<AppNotification>> {
    return this.http.get<Paginated<AppNotification>>(this.notificationsUrl);
  }

  /** `204`. Another user's notification answers `404`. */
  markNotificationRead(id: string): Observable<void> {
    return this.http.post<void>(`${this.notificationsUrl}/${id}/read`, {});
  }

  /* -------------------------------------------------------------- platforms */

  /**
   * Replaces the hand-copied `CONNECTABLE_PLATFORMS` constant. The registry is
   * the server's, so the integration form cannot drift from it — which it
   * already did once, when Zendesk was added on the backend mid-wave.
   */
  platforms(): Observable<PlatformListResponse> {
    return this.http.get<PlatformListResponse>(this.platformsUrl);
  }

  /** Re-reads the session after a change that alters `user` or `company`. */
  session(): Observable<SessionResponse> {
    return this.http.get<SessionResponse>(`${this.authUrl}/me`);
  }
}
