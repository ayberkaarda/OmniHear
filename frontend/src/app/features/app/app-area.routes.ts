import { Routes } from '@angular/router';

import { subscriptionGuard } from '../../core/billing/subscription.guard';

/**
 * `/app/**`. The parent `app` route already carries `authGuard`
 * (`app.routes.ts`), so nothing here is reachable without a proven session.
 *
 * Spec section 4's `SubscriptionGuard` is here rather than beside `authGuard`
 * in `app.routes.ts`, and that is a bundle decision. `canActivate` runs *after*
 * the lazy config is loaded, so guarding from inside this file protects exactly
 * the same URLs at exactly the same moment — but `BillingStore` and
 * `BillingService` then live in this chunk instead of the initial one. Measured:
 * declaring it in `app.routes.ts` cost 6.05 kB of initial raw against a 8.73 kB
 * headroom.
 *
 * What the guard does, and why it refuses no navigation, is in
 * `core/billing/subscription.guard.ts`.
 */
export const appAreaRoutes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'overview' },
  {
    path: '',
    canActivate: [subscriptionGuard],
    loadComponent: () => import('../app-shell/app-shell.component').then((m) => m.AppShellComponent),
    children: [
      {
        path: 'overview',
        loadComponent: () => import('./overview/overview.component').then((m) => m.OverviewComponent)
      },
      {
        path: 'inbox',
        loadComponent: () => import('./inbox/inbox.component').then((m) => m.InboxComponent)
      },
      {
        path: 'inbox/:id',
        loadComponent: () => import('./inbox-detail/inbox-detail.component').then((m) => m.InboxDetailComponent)
      },
      {
        path: 'integrations',
        loadComponent: () => import('./integrations/integrations.component').then((m) => m.IntegrationsComponent)
      },
      {
        path: 'settings',
        loadComponent: () => import('./settings/settings-layout.component').then((m) => m.SettingsLayoutComponent),
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'profile' },
          {
            path: 'profile',
            loadComponent: () => import('./settings/profile/profile.component').then((m) => m.ProfileComponent)
          },
          {
            path: 'team',
            loadComponent: () => import('./settings/team/team.component').then((m) => m.TeamComponent)
          },
          {
            path: 'billing',
            loadComponent: () => import('./settings/billing/billing.component').then((m) => m.BillingComponent)
          },
          {
            path: 'api-keys',
            loadComponent: () => import('./settings/api-keys/api-keys.component').then((m) => m.ApiKeysComponent)
          },
          {
            path: 'notifications',
            loadComponent: () =>
              import('./settings/notifications/notifications.component').then((m) => m.NotificationsComponent)
          }
        ]
      }
    ]
  }
];
