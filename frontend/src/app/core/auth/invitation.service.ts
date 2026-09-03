import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import { AcceptInvitationRequest, AuthSessionResponse, InvitationResponse } from './auth.models';
import { AuthStore } from './auth.store';

/**
 * The two public invitation endpoints (`docs/contracts/settings-api.md` 3a).
 *
 * Separate from `AuthService`, which states in its own docblock that it holds
 * every endpoint of the HTTP contract section 5 *and nothing else* — these two
 * live under `/v1/invitations`, not `/v1/auth`, and folding them in would make
 * that sentence untrue.
 *
 * Both calls are unauthenticated: the recipient has no account yet. The token
 * in the path is the only credential, which is why it is never put in a query
 * string from here — it arrives in one from the emailed link and goes straight
 * into the path segment.
 */
@Injectable({ providedIn: 'root' })
export class InvitationService {
  private readonly http = inject(HttpClient);
  private readonly store = inject(AuthStore);

  private readonly baseUrl = `${environment.apiBaseUrl}/v1/invitations`;

  /** `404` for an expired, an already-accepted and an unknown token alike. */
  show(token: string): Observable<InvitationResponse> {
    return this.http.get<InvitationResponse>(`${this.baseUrl}/${encodeURIComponent(token)}`);
  }

  /**
   * `201` with the same `{token, user, company}` envelope `POST /auth/register`
   * returns, so the SPA lands in the same authenticated state from either door
   * — which is why this sets the session exactly as `register()` does.
   */
  accept(token: string, payload: AcceptInvitationRequest): Observable<AuthSessionResponse> {
    return this.http
      .post<AuthSessionResponse>(`${this.baseUrl}/${encodeURIComponent(token)}/accept`, payload)
      .pipe(tap((response) => this.store.setSession(response.token, response.user, response.company)));
  }
}
