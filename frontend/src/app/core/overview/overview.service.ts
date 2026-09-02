import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../../environments/environment';
import { OverviewKpis } from './overview.models';

/** `GET /api/v1/overview/kpis` — the whole dashboard in one aggregate call. */
@Injectable({ providedIn: 'root' })
export class OverviewService {
  private readonly http = inject(HttpClient);

  kpis(): Observable<OverviewKpis> {
    return this.http.get<OverviewKpis>(`${environment.apiBaseUrl}/v1/overview/kpis`);
  }
}
