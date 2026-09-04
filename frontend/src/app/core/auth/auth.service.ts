import { HttpClient, HttpHeaders } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import {
  AuthSessionResponse,
  ForgotPasswordRequest,
  isTwoFactorChallenge,
  LoginRequest,
  LoginResponse,
  MessageResponse,
  RecoveryCodesResponse,
  RegisterRequest,
  ResetPasswordRequest,
  SessionResponse,
  TwoFactorChallengeRequest,
  TwoFactorCodeRequest,
  TwoFactorDisableRequest,
  TwoFactorEnrolmentResponse,
  VerifyEmailRequest,
  VerifyEmailResponse
} from './auth.models';
import { AuthStore } from './auth.store';

/** Names the Sanctum token so sessions stay revocable per device (contract 5). */
const DEFAULT_DEVICE_NAME = 'web';

/**
 * Every endpoint of `docs/contracts/http-api-v1.md` section 5 and of
 * `docs/contracts/w10-two-factor.md`, and nothing else. The service owns the
 * network call; `AuthStore` owns the state it produces.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly store = inject(AuthStore);

  private readonly baseUrl = `${environment.apiBaseUrl}/v1/auth`;

  register(payload: RegisterRequest): Observable<AuthSessionResponse> {
    return this.http
      .post<AuthSessionResponse>(`${this.baseUrl}/register`, payload)
      .pipe(tap((response) => this.store.setSession(response.token, response.user, response.company)));
  }

  /**
   * Two success shapes, one call. A `200 {two_factor_required:true}` is a
   * *completed first factor*, so the store must not be written: there is no
   * session yet, and putting the challenge token in `AuthStore` would hand a
   * five-minute, single-purpose credential to every interceptor and guard as
   * though it were one.
   */
  login(payload: LoginRequest): Observable<LoginResponse> {
    const body: LoginRequest = { ...payload, device_name: payload.device_name ?? DEFAULT_DEVICE_NAME };
    return this.http.post<LoginResponse>(`${this.baseUrl}/login`, body).pipe(
      tap((response) => {
        if (!isTwoFactorChallenge(response)) {
          this.store.setSession(response.token, response.user, response.company);
        }
      })
    );
  }

  /* ------------------------------------------------- two-factor: signing in */

  /**
   * Second factor. The challenge token travels as this request's own
   * `Authorization` header rather than through `AuthStore`, because it is not a
   * session: `authInterceptor` leaves a header that is already set alone.
   *
   * Success returns the same envelope a plain login returns, so the caller has
   * exactly one success path to write.
   */
  twoFactorChallenge(challengeToken: string, payload: TwoFactorChallengeRequest): Observable<AuthSessionResponse> {
    return this.http
      .post<AuthSessionResponse>(`${this.baseUrl}/two-factor/challenge`, payload, {
        headers: new HttpHeaders({ Authorization: `Bearer ${challengeToken}` })
      })
      .pipe(tap((response) => this.store.setSession(response.token, response.user, response.company)));
  }

  /* ------------------------------------------------ two-factor: enrolment */

  /** `201`. The secret is served once, here; nothing stores it client-side. */
  startTwoFactorEnrolment(): Observable<TwoFactorEnrolmentResponse> {
    return this.http.post<TwoFactorEnrolmentResponse>(`${this.baseUrl}/two-factor`, {});
  }

  /**
   * Confirms enrolment and returns the recovery codes once.
   *
   * `two_factor_enabled` means *confirmed* (contract, schema note), so this is
   * the only call that may flip it — enrolment alone must not, or a user who
   * closed the tab mid-setup would be locked out by a factor they never armed.
   */
  confirmTwoFactor(payload: TwoFactorCodeRequest): Observable<RecoveryCodesResponse> {
    return this.http
      .post<RecoveryCodesResponse>(`${this.baseUrl}/two-factor/confirm`, payload)
      .pipe(tap(() => this.setTwoFactorEnabled(true)));
  }

  /** Both factors re-proved: disabling one is exactly when a stolen session acts. */
  disableTwoFactor(payload: TwoFactorDisableRequest): Observable<void> {
    return this.http
      .delete<void>(`${this.baseUrl}/two-factor`, { body: payload })
      .pipe(tap(() => this.setTwoFactorEnabled(false)));
  }

  /** Invalidates the old set and returns a fresh one. Requires a current code. */
  regenerateRecoveryCodes(payload: TwoFactorCodeRequest): Observable<RecoveryCodesResponse> {
    return this.http.post<RecoveryCodesResponse>(`${this.baseUrl}/two-factor/recovery-codes`, payload);
  }

  /**
   * Keeps the cached user in step with a flag the server just changed.
   * `GET /auth/me` would say the same thing one round trip later; the screen
   * that just enabled 2FA has to be right immediately.
   */
  private setTwoFactorEnabled(enabled: boolean): void {
    const user = this.store.user();
    if (user !== null) {
      this.store.setUser({ ...user, two_factor_enabled: enabled });
    }
  }

  /** `204`; the store is cleared regardless of what the server answers. */
  logout(): Observable<void> {
    return this.http.post<void>(`${this.baseUrl}/logout`, {}).pipe(tap(() => this.store.clear()));
  }

  me(): Observable<SessionResponse> {
    return this.http
      .get<SessionResponse>(`${this.baseUrl}/me`)
      .pipe(tap((response) => this.store.setIdentity(response.user, response.company)));
  }

  forgotPassword(payload: ForgotPasswordRequest): Observable<MessageResponse> {
    return this.http.post<MessageResponse>(`${this.baseUrl}/forgot-password`, payload);
  }

  resetPassword(payload: ResetPasswordRequest): Observable<MessageResponse> {
    return this.http.post<MessageResponse>(`${this.baseUrl}/reset-password`, payload);
  }

  /** The four values are forwarded verbatim from the emailed link's query string. */
  verifyEmail(payload: VerifyEmailRequest): Observable<VerifyEmailResponse> {
    return this.http
      .post<VerifyEmailResponse>(`${this.baseUrl}/email/verify`, payload)
      .pipe(tap((response) => this.store.setUser(response.user)));
  }

  resendVerificationEmail(): Observable<MessageResponse> {
    return this.http.post<MessageResponse>(`${this.baseUrl}/email/resend`, {});
  }
}
