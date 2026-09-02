import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideExperimentalZonelessChangeDetection } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';

import { routes } from './app.routes';
import { authInterceptor } from './core/http/auth.interceptor';
import { correlationIdInterceptor } from './core/http/correlation-id.interceptor';
import { errorInterceptor } from './core/http/error.interceptor';
import { quotaInterceptor } from './core/http/quota.interceptor';

/**
 * Interceptor order is load-bearing.
 *
 * `withInterceptors([A, B, C])` builds A(B(C(backend))): the request travels
 * down the list and the response travels back up it. `quotaInterceptor` is last
 * — i.e. closest to the backend — because `errorInterceptor` replaces the
 * `HttpErrorResponse` with a plain `ApiError`, and the `402` that exhausts the
 * quota carries the final `X-Quota-Remaining: 0` in the headers of exactly that
 * discarded response.
 */
export const appConfig: ApplicationConfig = {
  providers: [
    provideExperimentalZonelessChangeDetection(),
    provideRouter(
      routes,
      withComponentInputBinding()
    ),
    provideHttpClient(
      withInterceptors([correlationIdInterceptor, authInterceptor, errorInterceptor, quotaInterceptor])
    )
  ]
};
