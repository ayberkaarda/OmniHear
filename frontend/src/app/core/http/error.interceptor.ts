import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { AuthStore } from '../auth/auth.store';
import { ApiError, toApiError } from '../errors/api-error';
import { errorMessageForCode } from '../errors/error-messages';
import { PaywallService } from '../paywall/paywall.service';
import { ToastService } from '../toast/toast.service';

/**
 * The single place where an API failure becomes user-visible behaviour.
 * Branching is on `code`, never on the HTTP status: `401 INVALID_CREDENTIALS`
 * (a wrong password on the login form) must not sign the user out, while
 * `401 UNAUTHENTICATED` (a dead token) must. Contract section 6.
 */
export const errorInterceptor: HttpInterceptorFn = (request, next) => {
  const router = inject(Router);
  const authStore = inject(AuthStore);
  const toasts = inject(ToastService);
  const paywall = inject(PaywallService);

  return next(request).pipe(
    catchError((error: unknown) => {
      if (!(error instanceof HttpErrorResponse)) {
        return throwError(() => error);
      }

      const apiError: ApiError = toApiError(error);

      switch (apiError.code) {
        case 'UNAUTHENTICATED':
          authStore.clear();
          void router.navigate(['/auth/login']);
          break;
        case 'QUOTA_EXCEEDED':
          // Blocking modal, never a toast: the quota wall is the only screen
          // from which the user can move forward (spec 7.5).
          paywall.open();
          break;
        case 'EMAIL_NOT_VERIFIED':
          void router.navigate(['/auth/verify-email']);
          break;
        default:
          toasts.error(errorMessageForCode(apiError.code));
          break;
      }

      return throwError(() => apiError);
    })
  );
};
