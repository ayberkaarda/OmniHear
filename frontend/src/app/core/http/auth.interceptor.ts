import { HttpInterceptorFn } from '@angular/common/http';
import { inject, LOCALE_ID } from '@angular/core';

import { AuthStore } from '../auth/auth.store';
import { isApiRequest } from './api-url';

/**
 * Attaches the Sanctum bearer token, and the `Accept: application/json` header
 * the contract requires (without it Laravel may answer with a redirect instead
 * of JSON — contract section 1).
 *
 * A request that already carries its own `Authorization` keeps it. The
 * two-factor challenge (`docs/contracts/w10-two-factor.md`) is authenticated by
 * a short-lived challenge token that is deliberately never put in `AuthStore`:
 * it is not a session, and it must not become one. Overwriting the caller's
 * header with a stale stored token would send the wrong credential to the one
 * endpoint that will not accept it.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  if (!isApiRequest(request.url)) {
    return next(request);
  }

  const token = inject(AuthStore).token();
  // The API localises its own validation messages from lang/{tr,en}; telling it
  // which build we are means the field errors we surface arrive translated.
  const locale = inject(LOCALE_ID);
  const headers = request.headers.set('Accept', 'application/json').set('Accept-Language', locale);
  const carriesOwnCredential = request.headers.has('Authorization');

  return next(
    request.clone({
      headers: token === null || carriesOwnCredential ? headers : headers.set('Authorization', `Bearer ${token}`)
    })
  );
};
