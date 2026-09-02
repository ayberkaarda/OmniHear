import { Routes } from '@angular/router';

import { authGuard } from './core/auth/auth.guard';

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
