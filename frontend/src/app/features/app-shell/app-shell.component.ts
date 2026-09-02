import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { AuthService } from '../../core/auth/auth.service';
import { AuthStore } from '../../core/auth/auth.store';
import { PaywallModalComponent } from '../../core/paywall/paywall-modal.component';
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
export class AppShellComponent {
  private readonly authService = inject(AuthService);
  private readonly authStore = inject(AuthStore);
  private readonly router = inject(Router);

  protected readonly user = this.authStore.user;
  protected readonly company = this.authStore.company;
  protected readonly emailVerified = this.authStore.isEmailVerified;
  protected readonly signingOut = signal(false);

  protected readonly userInitial = computed(() => this.user()?.name.trim().charAt(0).toUpperCase() ?? '?');

  protected readonly primaryNavLabel = $localize`:Primary navigation landmark label@@shell.nav.primary:Primary`;
  protected readonly signOutLabel = $localize`:Sign out button label@@shell.signOut:Sign out`;

  protected onSignOut(): void {
    if (this.signingOut()) {
      return;
    }
    this.signingOut.set(true);
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
