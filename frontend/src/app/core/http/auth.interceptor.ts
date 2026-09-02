import { HttpInterceptorFn } from '@angular/common/http';
import { inject, LOCALE_ID } from '@angular/core';

import { AuthStore } from '../auth/auth.store';
import { isApiRequest } from './api-url';

/**
 * Attaches the Sanctum bearer token, and the `Accept: application/json` header
 * the contract requires (without it Laravel may answer with a redirect instead
 * of JSON — contract section 1).
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

  return next(
    request.clone({
      headers: token === null ? headers : headers.set('Authorization', `Bearer ${token}`)
    })
  );
};
