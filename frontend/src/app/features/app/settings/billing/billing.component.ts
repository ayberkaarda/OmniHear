import { ChangeDetectionStrategy, Component, computed, effect, inject, input, OnInit } from '@angular/core';

import { isCheckoutOutcome, PaymentProvider, Subscription } from '../../../../core/billing/billing.models';
import { BillingStore } from '../../../../core/billing/billing.store';
import { errorMessageForCode } from '../../../../core/errors/error-messages';
import { QuotaStore } from '../../../../core/quota/quota.store';
import { ButtonComponent } from '../../../../shared/ui/button/button.component';
import { formatCount, formatDate, formatPercent } from '../../../../shared/format/format';

/**
 * `/app/settings/billing` — the plan, the quota, and the way to upgrade.
 *
 * This screen is the far end of spec 7.5's loop. The `402` modal used to lead
 * to a placeholder; it now leads here, and here leads to a provider's checkout
 * page.
 *
 * **`?checkout=success` does not mean the plan is upgraded.** The provider
 * returns the browser here, but it is the *webhook* that activates the
 * subscription (spec 7.5, 7.6) and it may not have landed yet. So the banner
 * says the payment was submitted, the store re-reads
 * `GET /billing/subscription`, and what the screen reports is whatever the
 * server says the plan is — never what the query string implies.
 *
 * `checkout` is bound from the query parameter by `withComponentInputBinding()`,
 * already configured in `app.config.ts`.
 */
@Component({
  selector: 'app-billing',
  standalone: true,
  imports: [ButtonComponent],
  templateUrl: './billing.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class BillingComponent implements OnInit {
  private readonly store = inject(BillingStore);
  private readonly quota = inject(QuotaStore);

  /** `?checkout=success|cancel`, written by the `/billing/*` return routes. */
  readonly checkout = input<string | undefined>(undefined);

  protected readonly loading = this.store.loading;
  protected readonly starting = this.store.starting;
  protected readonly subscription = this.store.subscription;
  protected readonly plan = this.store.plan;
  protected readonly isPaid = this.store.isPaid;
  protected readonly canCheckout = this.store.canCheckout;
  protected readonly outcome = this.store.outcome;

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  protected readonly quotaSummary = computed(() => {
    const quota = this.store.quota();
    if (quota === null) {
      return null;
    }
    const ratio = quota.limit > 0 ? Math.min(1, quota.used / quota.limit) : 0;
    return {
      used: formatCount(quota.used),
      limit: formatCount(quota.limit),
      remaining: formatCount(quota.remaining),
      percent: formatPercent(ratio),
      width: ratio * 100
    };
  });

  protected readonly quotaLevel = this.quota.level;

  protected readonly planLabel = computed(() =>
    this.isPaid()
      ? $localize`:Plan name@@billing.plan.pro:Pro`
      : $localize`:Plan name@@billing.plan.free:Free`
  );

  protected readonly renewsOn = computed(() => formatDate(this.subscription()?.current_period_end));
  protected readonly startedOn = computed(() => formatDate(this.subscription()?.current_period_start));

  protected readonly upgradeStripeLabel = $localize`:Start checkout with Stripe@@billing.checkout.stripe:Pay with card (Stripe)`;
  protected readonly upgradeIyzicoLabel = $localize`:Start checkout with iyzico@@billing.checkout.iyzico:Pay with iyzico`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly ownerOnlyLabel = $localize`:Why the upgrade buttons are absent@@billing.ownerOnly:Only the company owner can change the plan. Ask an owner to upgrade.`;

  constructor() {
    // The route is entered both directly and by redirect from the provider, so
    // the query parameter is read reactively rather than once in ngOnInit.
    effect(() => {
      const value = this.checkout();
      this.store.setOutcome(isCheckoutOutcome(value) ? value : null);
      if (value === 'success') {
        // Not a claim that the plan changed — a reason to ask the server again.
        this.store.load();
      }
    });
  }

  ngOnInit(): void {
    this.store.loadIfNeeded();
  }

  protected reload(): void {
    this.store.load();
  }

  protected start(provider: PaymentProvider): void {
    this.store.startCheckout(provider);
  }

  protected statusOf(subscription: Subscription): string {
    return subscription.status;
  }
}
