import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';

import { environment } from '../../../environments/environment';
import { Paginated } from '../api/pagination';
import { Feedback, FeedbackFilters, FeedbackResponse } from './feedback.models';

/**
 * `GET /api/v1/feedbacks` and `GET /api/v1/feedbacks/{id}`.
 *
 * The service owns the wire; the stores own the state it produces — the same
 * split `AuthService`/`AuthStore` established, and the reason neither of them
 * has to be mocked to test the other.
 */
@Injectable({ providedIn: 'root' })
export class FeedbackService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${environment.apiBaseUrl}/v1/feedbacks`;

  list(filters: FeedbackFilters, page: number, perPage: number): Observable<Paginated<Feedback>> {
    return this.http.get<Paginated<Feedback>>(this.baseUrl, {
      params: toHttpParams(filters, page, perPage)
    });
  }

  get(id: number): Observable<Feedback> {
    return this.http.get<FeedbackResponse>(`${this.baseUrl}/${id}`).pipe(map((response) => response.feedback));
  }
}

/**
 * Only set filters reach the query string.
 *
 * Every filter is validated `sometimes` on the server, so an empty string is
 * not "no filter" — it is a value that fails `Rule::in` and turns the whole
 * request into a 422.
 */
export function toHttpParams(filters: FeedbackFilters, page: number, perPage: number): HttpParams {
  let params = new HttpParams().set('page', page).set('per_page', perPage);

  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === '') {
      continue;
    }
    params = params.set(key, String(value));
  }

  return params;
}
