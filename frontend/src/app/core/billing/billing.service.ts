import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../../environments/environment';
import { BillingSummary, CheckoutBody, CheckoutSession } from './billing.models';

/** The two tenant-facing billing endpoints of `docs/contracts/wave2-seams.md` section 3. */
@Injectable({ providedIn: 'root' })
export class BillingService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${environment.apiBaseUrl}/v1/billing`;

  subscription(): Observable<BillingSummary> {
    return this.http.get<BillingSummary>(`${this.baseUrl}/subscription`);
  }

  /** `owner` only; anyone else is refused with `403 FORBIDDEN` by the policy. */
  checkout(body: CheckoutBody): Observable<CheckoutSession> {
    return this.http.post<CheckoutSession>(`${this.baseUrl}/checkout`, body);
  }
}
