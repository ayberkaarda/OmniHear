import { inject } from '@angular/core';
import { CanActivateFn, Router, UrlTree } from '@angular/router';
import { catchError, map, Observable, of } from 'rxjs';

import { AuthService } from './auth.service';
import { AuthStore } from './auth.store';

/**
 * Protects `/app/**`.
 *
 * A token restored from localStorage is *not* proof of a session — it may have
 * been revoked on another device. When the store is still `unknown` the guard
 * resolves it with `GET /auth/me` before deciding, so a valid returning user is
 * never bounced to the login screen on a hard refresh.
 */
export const authGuard: CanActivateFn = (_route, state): boolean | UrlTree | Observable<boolean | UrlTree> => {
  const store = inject(AuthStore);
  const router = inject(Router);

  const loginUrl = (): UrlTree => router.createUrlTree(['/auth/login'], { queryParams: { redirect: state.url } });

  if (store.isAuthenticated()) {
    return true;
  }

  if (store.token() === null) {
    return loginUrl();
  }

  return inject(AuthService)
    .me()
    .pipe(
      map(() => true as const),
      catchError(() => {
        // errorInterceptor already cleared the store for UNAUTHENTICATED; for any
        // other failure we still must not let an unproven session through.
        store.clear();
        return of(loginUrl());
      })
    );
};

/**
 * Keeps a signed-in user off the login/register screens. Deliberately does not
 * call `/auth/me`: a stale token must not delay the public auth pages, and the
 * worst case is that the user sees a login form they do not need.
 */
export const guestGuard: CanActivateFn = (): boolean | UrlTree => {
  const store = inject(AuthStore);
  const router = inject(Router);

  return store.isAuthenticated() ? router.createUrlTree(['/app/overview']) : true;
};
