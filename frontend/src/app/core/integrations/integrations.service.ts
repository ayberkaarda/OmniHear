import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { environment } from '../../../environments/environment';
import { MAX_PER_PAGE, Paginated } from '../api/pagination';
import { Integration, IntegrationResponse, IntegrationWriteBody } from './integration.models';

/**
 * The six integration endpoints of `docs/contracts/wave2-seams.md` section 3.
 *
 * `credentials` travels only outwards, inside `IntegrationWriteBody`. Nothing
 * here reads it back, because nothing can: the API does not serialize it
 * (invariant I5).
 */
@Injectable({ providedIn: 'root' })
export class IntegrationsService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${environment.apiBaseUrl}/v1/integrations`;

  /**
   * A tenant has a handful of integrations, not pages of them, so the list is
   * fetched at the contract's maximum page size and rendered whole. The
   * envelope is still paginated and `meta` is still read, so a tenant that
   * outgrows one page is visible rather than silently truncated.
   */
  list(): Observable<Paginated<Integration>> {
    return this.http.get<Paginated<Integration>>(this.baseUrl, {
      params: new HttpParams().set('per_page', MAX_PER_PAGE)
    });
  }

  create(body: IntegrationWriteBody): Observable<Integration> {
    return this.http.post<IntegrationResponse>(this.baseUrl, body).pipe(map((response) => response.integration));
  }

  update(id: number, body: IntegrationWriteBody): Observable<Integration> {
    return this.http
      .patch<IntegrationResponse>(`${this.baseUrl}/${id}`, body)
      .pipe(map((response) => response.integration));
  }

  /** `204` — no body. */
  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }

  /** `202` when the run is queued; `409 SYNC_IN_PROGRESS` when one is already running. */
  sync(id: number): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.baseUrl}/${id}/sync`, {});
  }
}
