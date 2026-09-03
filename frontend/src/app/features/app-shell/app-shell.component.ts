import { ChangeDetectionStrategy, Component, computed, effect, inject, OnDestroy, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { AuthService } from '../../core/auth/auth.service';
import { AuthStore } from '../../core/auth/auth.store';
import { PaywallModalComponent } from '../../core/paywall/paywall-modal.component';
import { RealtimeBridge } from '../../core/realtime/realtime.bridge';
import { ButtonComponent } from '../../shared/ui/button/button.component';
import { IconComponent } from '../../shared/ui/icon/icon.component';
import { QuotaMeterComponent } from './quota-meter.component';
import { ThemeToggleComponent } from './theme-toggle.component';

/**
 * Chrome for every `/app/**` screen: skip link, primary navigation landmark,
 * identity/quota rail and the single `<main>` the child routes render into.
 */
@Component({
    selector: 'app-app-shell',
    imports: [
        RouterOutlet,
        RouterLink,
        RouterLinkActive,
        IconComponent,
        ButtonComponent,
        QuotaMeterComponent,
        ThemeToggleComponent,
        PaywallModalComponent
    ],
    templateUrl: './app-shell.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class AppShellComponent implements OnDestroy {
  private readonly authService = inject(AuthService);
  private readonly authStore = inject(AuthStore);
  private readonly realtime = inject(RealtimeBridge);
  private readonly router = inject(Router);

  protected readonly user = this.authStore.user;
  protected readonly company = this.authStore.company;
  protected readonly emailVerified = this.authStore.isEmailVerified;
  protected readonly signingOut = signal(false);

  protected readonly userInitial = computed(() => this.user()?.name.trim().charAt(0).toUpperCase() ?? '?');

  protected readonly primaryNavLabel = $localize`:Primary navigation landmark label@@shell.nav.primary:Primary`;
  protected readonly signOutLabel = $localize`:Sign out button label@@shell.signOut:Sign out`;

  /**
   * The only place realtime is started, and the reason it is here rather than
   * in `app.config.ts`: this component lives in a lazy chunk behind
   * `authGuard`, so pusher-js can never reach the initial bundle or a page a
   * signed-out visitor can open (`docs/contracts/realtime.md` section 3).
   *
   * An `effect` rather than a constructor call because a hard refresh mounts
   * the shell while `authGuard` is still resolving `GET /auth/me`: the company
   * id and the token both arrive a tick later, and `connect()` is idempotent.
   */
  constructor() {
    effect(() => {
      if (this.authStore.isAuthenticated()) {
        this.realtime.start();
      }
    });
  }

  /** Leaving `/app/**` — sign-out, a dead token, or a plain navigation — closes the socket. */
  ngOnDestroy(): void {
    this.realtime.stop();
  }

  protected onSignOut(): void {
    if (this.signingOut()) {
      return;
    }
    this.signingOut.set(true);
    // Before the request, not after: the token that authorized the channel is
    // about to be revoked, and a socket still holding it would keep receiving
    // this tenant's events until the server noticed.
    this.realtime.stop();
    this.authService.logout().subscribe({
      next: () => {
        this.signingOut.set(false);
        void this.router.navigate(['/']);
      },
      error: () => {
        // The token is unusable either way; drop it locally and leave.
        this.signingOut.set(false);
        this.authStore.clear();
        void this.router.navigate(['/']);
      }
    });
  }
}
