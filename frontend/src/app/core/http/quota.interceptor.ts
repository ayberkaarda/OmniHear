import { HttpErrorResponse, HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, tap, throwError } from 'rxjs';

import { QuotaStore } from '../quota/quota.store';

export const QUOTA_REMAINING_HEADER = 'X-Quota-Remaining';

function readQuotaHeader(headers: { get(name: string): string | null } | undefined): number | null {
  const raw = headers?.get(QUOTA_REMAINING_HEADER) ?? null;
  if (raw === null) {
    return null;
  }
  const value = Number.parseInt(raw, 10);
  return Number.isFinite(value) ? value : null;
}

/**
 * Reads `X-Quota-Remaining` off every response — success *and* failure, since
 * the `402` that exhausts the quota is itself an error response and carries the
 * final `0`.
 *
 * Must sit closer to the backend than `errorInterceptor`, which replaces the
 * `HttpErrorResponse` (headers included) with a plain `ApiError`.
 */
export const quotaInterceptor: HttpInterceptorFn = (request, next) => {
  const quota = inject(QuotaStore);

  return next(request).pipe(
    tap((event) => {
      if (event instanceof HttpResponse) {
        const remaining = readQuotaHeader(event.headers);
        if (remaining !== null) {
          quota.setRemaining(remaining);
        }
      }
    }),
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse) {
        const remaining = readQuotaHeader(error.headers);
        if (remaining !== null) {
          quota.setRemaining(remaining);
        }
      }
      return throwError(() => error);
    })
  );
};
