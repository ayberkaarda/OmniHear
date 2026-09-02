import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Router } from '@angular/router';

import { ButtonComponent } from '../../shared/ui/button/button.component';
import { ModalComponent } from '../../shared/ui/modal/modal.component';
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

  protected readonly isOpen = this.paywall.isOpen;
  protected readonly quotaLimit = computed(() => this.quota.limit());

  protected readonly title = $localize`:Paywall modal title@@paywall.title:Your analysis quota is full`;

  protected onClose(): void {
    this.paywall.close();
  }

  protected onUpgrade(): void {
    this.paywall.close();
    void this.router.navigate(['/app/settings/billing']);
  }
}
