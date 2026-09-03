import { computed, inject, Injectable, signal } from '@angular/core';

import { RequestState } from '../api/request-state';
import { AuthStore } from '../auth/auth.store';
import { FieldErrors } from '../errors/api-error';
import { errorCodeOf, fieldErrorsOf } from '../errors/error-code';
import { ApiKey, DeviceSession } from './settings.models';
import { SettingsService } from './settings.service';

/**
 * `/app/settings/api-keys` — the two token lists, kept apart.
 *
 * An API key and a device session are both Sanctum tokens; they differ only by
 * ability, and they are listed by two different endpoints
 * (`GET /settings/api-keys` versus `GET /auth/tokens`). One store holds both
 * because one screen shows both, but nothing crosses between them: the arrays
 * are separate, the pending flags are separate, and `revokeKey`/`revokeSession`
 * call different endpoints. That separation is the whole point of contract
 * section 3 — the failure it prevents is one list offering to revoke the
 * other's rows.
 *
 * **`plainTextToken` is held in memory and only until the dialog closes.** The
 * server returns it exactly once; it is never written to storage, never logged,
 * and `clearPlainTextToken()` drops it. Nothing re-reads it, because nothing
 * can.
 */
@Injectable({ providedIn: 'root' })
export class ApiKeysStore {
  private readonly service = inject(SettingsService);
  private readonly auth = inject(AuthStore);

  private readonly keysSignal = signal<readonly ApiKey[]>([]);
  private readonly sessionsSignal = signal<readonly DeviceSession[]>([]);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly sessionsStateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private readonly creatingSignal = signal(false);
  private readonly createErrorsSignal = signal<FieldErrors | null>(null);
  private readonly plainTextTokenSignal = signal<string | null>(null);
  private readonly createdKeySignal = signal<ApiKey | null>(null);
  private readonly revokingKeyIdSignal = signal<number | null>(null);
  private readonly revokingSessionIdSignal = signal<number | null>(null);

  private requestToken = 0;

  readonly keys = this.keysSignal.asReadonly();
  readonly sessions = this.sessionsSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly sessionsState = this.sessionsStateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();
  readonly creating = this.creatingSignal.asReadonly();
  readonly createErrors = this.createErrorsSignal.asReadonly();
  readonly plainTextToken = this.plainTextTokenSignal.asReadonly();
  readonly createdKey = this.createdKeySignal.asReadonly();
  readonly revokingKeyId = this.revokingKeyIdSignal.asReadonly();
  readonly revokingSessionId = this.revokingSessionIdSignal.asReadonly();

  readonly loading = computed(() => this.stateSignal() === 'idle' || this.stateSignal() === 'loading');
  readonly sessionsLoading = computed(
    () => this.sessionsStateSignal() === 'idle' || this.sessionsStateSignal() === 'loading'
  );
  readonly isEmpty = computed(() => this.stateSignal() === 'ready' && this.keysSignal().length === 0);

  /** Creating and revoking keys is `owner` or `admin` (contract section 3). */
  readonly canManageKeys = computed(() => {
    const role = this.auth.role();
    return role === 'owner' || role === 'admin';
  });

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.sessionsStateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.apiKeys().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.keysSignal.set(response.data);
        this.stateSignal.set('ready');
      },
      error: (error: unknown) => {
        if (token !== this.requestToken) {
          return;
        }
        this.errorCodeSignal.set(errorCodeOf(error));
        this.stateSignal.set('error');
      }
    });

    // A separate read with a separate state: the device-session list must still
    // render when the key list fails, because signing a stolen device out is
    // the one action on this screen that cannot be allowed to depend on
    // anything else working.
    this.service.deviceSessions().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.sessionsSignal.set(response.data);
        this.sessionsStateSignal.set('ready');
      },
      error: () => {
        if (token !== this.requestToken) {
          return;
        }
        this.sessionsStateSignal.set('error');
      }
    });
  }

  loadIfNeeded(): void {
    if (this.stateSignal() === 'idle') {
      this.load();
    }
  }

  clearCreateErrors(): void {
    this.createErrorsSignal.set(null);
  }

  /** Drops the plaintext value. Called when the reveal dialog closes. */
  clearPlainTextToken(): void {
    this.plainTextTokenSignal.set(null);
    this.createdKeySignal.set(null);
  }

  create(name: string, onSuccess?: () => void): void {
    if (this.creatingSignal()) {
      return;
    }
    this.creatingSignal.set(true);
    this.createErrorsSignal.set(null);

    this.service.createApiKey({ name }).subscribe({
      next: (response) => {
        this.creatingSignal.set(false);
        this.plainTextTokenSignal.set(response.plain_text_token);
        this.createdKeySignal.set(response.api_key);
        this.keysSignal.update((keys) => [response.api_key, ...keys]);
        onSuccess?.();
      },
      error: (error: unknown) => {
        this.creatingSignal.set(false);
        this.createErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  revokeKey(id: number, onSuccess?: () => void): void {
    if (this.revokingKeyIdSignal() !== null) {
      return;
    }
    this.revokingKeyIdSignal.set(id);

    this.service.revokeApiKey(id).subscribe({
      next: () => {
        this.revokingKeyIdSignal.set(null);
        this.keysSignal.update((keys) => keys.filter((key) => key.id !== id));
        onSuccess?.();
      },
      error: () => {
        this.revokingKeyIdSignal.set(null);
      }
    });
  }

  /**
   * Revokes a *device session*, not a key. Revoking the current session is
   * allowed and ends it — `errorInterceptor` then sees `401 UNAUTHENTICATED` on
   * the next request and takes the user to the login screen, which is the
   * correct outcome and needs no special case here.
   */
  revokeSession(id: number, onSuccess?: () => void): void {
    if (this.revokingSessionIdSignal() !== null) {
      return;
    }
    this.revokingSessionIdSignal.set(id);

    this.service.revokeDeviceSession(id).subscribe({
      next: () => {
        this.revokingSessionIdSignal.set(null);
        this.sessionsSignal.update((sessions) => sessions.filter((session) => session.id !== id));
        onSuccess?.();
      },
      error: () => {
        this.revokingSessionIdSignal.set(null);
      }
    });
  }

  reset(): void {
    this.requestToken++;
    this.keysSignal.set([]);
    this.sessionsSignal.set([]);
    this.stateSignal.set('idle');
    this.sessionsStateSignal.set('idle');
    this.errorCodeSignal.set(null);
    this.creatingSignal.set(false);
    this.createErrorsSignal.set(null);
    this.plainTextTokenSignal.set(null);
    this.createdKeySignal.set(null);
    this.revokingKeyIdSignal.set(null);
    this.revokingSessionIdSignal.set(null);
  }
}
