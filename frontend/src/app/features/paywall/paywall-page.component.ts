import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AuthStore } from '../../core/auth/auth.store';
import { QuotaStore } from '../../core/quota/quota.store';
import { ButtonStyleDirective } from '../../shared/ui/button/button-style.directive';
import { IconComponent } from '../../shared/ui/icon/icon.component';

/**
 * Full-page counterpart of the paywall modal, at `/402`.
 *
 * The modal covers the case where a request fails mid-session; this page covers
 * a direct link, a bookmark or a reload — the state has to survive both.
 * Deliberately guard-free so it also renders for a signed-out visitor.
 */
@Component({
    selector: 'app-paywall-page',
    imports: [RouterLink, ButtonStyleDirective, IconComponent],
    templateUrl: './paywall-page.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class PaywallPageComponent {
  private readonly quota = inject(QuotaStore);
  private readonly authStore = inject(AuthStore);

  protected readonly limit = this.quota.limit;
  protected readonly isAuthenticated = this.authStore.isAuthenticated;
}
