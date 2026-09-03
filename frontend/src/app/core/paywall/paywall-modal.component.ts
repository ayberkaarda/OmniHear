import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Router } from '@angular/router';

import { ButtonComponent } from '../../shared/ui/button/button.component';
import { ModalComponent } from '../../shared/ui/modal/modal.component';
import { AuthStore } from '../auth/auth.store';
import { QuotaStore } from '../quota/quota.store';
import { PaywallService } from './paywall.service';

/**
 * The `402 QUOTA_EXCEEDED` wall (spec 7.4-7.5).
 *
 * Mounted once, globally, and driven by `PaywallService` — a quota failure can
 * come from any request on any screen, so the modal cannot belong to a feature.
 * `dismissible` is false: Esc and a backdrop click do not close a wall that is
 * the only route forward. The header's explicit close button remains, because a
 * dialog with no keyboard-reachable exit fails WCAG 2.1.
 */
@Component({
    selector: 'app-paywall-modal',
    imports: [ModalComponent, ButtonComponent],
    templateUrl: './paywall-modal.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class PaywallModalComponent {
  private readonly paywall = inject(PaywallService);
  private readonly router = inject(Router);
  private readonly quota = inject(QuotaStore);
  private readonly auth = inject(AuthStore);

  protected readonly isOpen = this.paywall.isOpen;
  protected readonly quotaLimit = computed(() => this.quota.limit());

  /** Only an `owner` may start a checkout, so only an owner is offered one. */
  protected readonly canUpgrade = computed(() => this.auth.role() === 'owner');

  protected readonly title = $localize`:Paywall modal title@@paywall.title:Your analysis quota is full`;
  protected readonly ownerOnly = $localize`:Shown in the paywall to a non-owner@@paywall.ownerOnly:Only the company owner can change the plan. Ask an owner to upgrade — the waiting comments are analysed as soon as they do.`;

  protected onClose(): void {
    this.paywall.close();
  }

  /**
   * Closes spec 7.5's loop. `/app/settings/billing` is where the provider is
   * chosen and `POST /billing/checkout` is called; sending the user there is
   * the shortest honest path, because the modal cannot pick a payment provider
   * on their behalf.
   */
  protected onUpgrade(): void {
    this.paywall.close();
    void this.router.navigate(['/app/settings/billing']);
  }
}
