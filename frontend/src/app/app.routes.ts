import { inject } from '@angular/core';
import { Router, Routes, UrlTree } from '@angular/router';

import { authGuard } from './core/auth/auth.guard';

/**
 * Where a payment provider sends the browser back to.
 *
 * `config/stripe.php` defaults `success_url` / `cancel_url` to
 * `FRONTEND_URL/billing/{success,cancel}`, and iyzico's `callbackUrl` is
 * configured the same way. Without these two entries the return leg of the
 * checkout journey landed on the `**` not-found screen — the user paid and was
 * shown a 404.
 *
 * They redirect into the billing screen rather than becoming pages of their
 * own: `success` only means the browser came back, never that the plan is
 * upgraded (the webhook decides that), so the honest thing to render is the
 * billing screen re-reading the subscription. `redirectTo` runs in an injection
 * context, which is what lets a query parameter survive a redirect.
 */
function backFromCheckout(outcome: 'success' | 'cancel'): () => UrlTree {
  return () =>
    inject(Router).createUrlTree(['/app/settings/billing'], { queryParams: { checkout: outcome } });
}

/**
 * Page tree of spec section 4. Every route is `loadComponent`/`loadChildren`
 * lazy: the initial bundle carries the router configuration and the core
 * services, never a screen.
 */
export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () => import('./features/landing/landing.component').then((m) => m.LandingComponent)
  },
  {
    path: 'auth',
    loadChildren: () => import('./features/auth/auth.routes').then((m) => m.authRoutes)
  },
  {
    path: 'app',
    canActivate: [authGuard],
    loadChildren: () => import('./features/app/app-area.routes').then((m) => m.appAreaRoutes)
  },
  {
    path: 'billing/success',
    redirectTo: backFromCheckout('success')
  },
  {
    path: 'billing/cancel',
    redirectTo: backFromCheckout('cancel')
  },
  {
    path: '402',
    loadComponent: () => import('./features/paywall/paywall-page.component').then((m) => m.PaywallPageComponent)
  },
  {
    // Kept from the F1 skeleton: the container health probe screen.
    path: 'health',
    loadComponent: () => import('./features/health/health.component').then((m) => m.HealthComponent)
  },
  {
    path: '**',
    loadComponent: () => import('./features/not-found/not-found.component').then((m) => m.NotFoundComponent)
  }
];
