import { Routes } from '@angular/router';

/**
 * `/app/**`. The parent `app` route already carries `authGuard`
 * (`app.routes.ts`), so nothing here is reachable without a proven session.
 *
 * Spec section 4 also lists a `SubscriptionGuard` on this subtree. It is not
 * added yet: subscriptions land in the payments phase, and a guard with nothing
 * to check would either be a no-op or a guess.
 */
export const appAreaRoutes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'overview' },
  {
    path: '',
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
