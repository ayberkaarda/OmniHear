import { Routes } from '@angular/router';

import { guestGuard } from '../../core/auth/auth.guard';

/**
 * `guestGuard` is applied per route rather than on the `auth` parent.
 * `/auth/verify-email` must stay reachable while signed in: `errorInterceptor`
 * routes a `403 EMAIL_NOT_VERIFIED` here, and a guest guard on the parent would
 * bounce that user to `/app` and straight back again.
 */
export const authRoutes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'login' },
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./login/login.component').then((m) => m.LoginComponent)
  },
  {
    path: 'register',
    canActivate: [guestGuard],
    loadComponent: () => import('./register/register.component').then((m) => m.RegisterComponent)
  },
  {
    path: 'forgot-password',
    canActivate: [guestGuard],
    loadComponent: () => import('./forgot-password/forgot-password.component').then((m) => m.ForgotPasswordComponent)
  },
  {
    path: 'reset-password',
    canActivate: [guestGuard],
    loadComponent: () => import('./reset-password/reset-password.component').then((m) => m.ResetPasswordComponent)
  },
  {
    // Target of the emailed invitation link. `guestGuard` for the same reason
    // reset-password carries it: accepting replaces whatever session the
    // browser holds, and doing that silently under a signed-in user is worse
    // than telling them to sign out first.
    path: 'accept-invitation',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./accept-invitation/accept-invitation.component').then((m) => m.AcceptInvitationComponent)
  },
  {
    path: 'verify-email',
    loadComponent: () => import('./verify-email/verify-email.component').then((m) => m.VerifyEmailComponent)
  }
];
