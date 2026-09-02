import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import {
  AuthSessionResponse,
  ForgotPasswordRequest,
  LoginRequest,
  MessageResponse,
  RegisterRequest,
  ResetPasswordRequest,
  SessionResponse,
  VerifyEmailRequest,
  VerifyEmailResponse
} from './auth.models';
import { AuthStore } from './auth.store';

/** Names the Sanctum token so sessions stay revocable per device (contract 5). */
const DEFAULT_DEVICE_NAME = 'web';

/**
 * Every endpoint of `docs/contracts/http-api-v1.md` section 5, and nothing else.
 * The service owns the network call; `AuthStore` owns the state it produces.
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

  login(payload: LoginRequest): Observable<AuthSessionResponse> {
    const body: LoginRequest = { ...payload, device_name: payload.device_name ?? DEFAULT_DEVICE_NAME };
    return this.http
      .post<AuthSessionResponse>(`${this.baseUrl}/login`, body)
      .pipe(tap((response) => this.store.setSession(response.token, response.user, response.company)));
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
