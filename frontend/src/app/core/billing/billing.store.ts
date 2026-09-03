import { computed, inject, Injectable, InjectionToken, signal } from '@angular/core';

import { RequestState } from '../api/request-state';
import { AuthStore } from '../auth/auth.store';
import { errorCodeOf } from '../errors/error-code';
import { BillingSummary, CheckoutOutcome, PaymentProvider, UPGRADE_PLAN } from './billing.models';
import { BillingService } from './billing.service';

/**
 * Leaving the SPA for the provider's hosted page.
 *
 * Behind a token so the redirect is a seam rather than a hard call to
 * `window.location`: a test can assert *which* URL checkout would have sent the
 * browser to, which is the only way to prove the loop from `402` to a payment
 * page without a real Stripe session.
 */
export const CHECKOUT_REDIRECT = new InjectionToken<(url: string) => void>('CHECKOUT_REDIRECT', {
  providedIn: 'root',
  factory: () => (url: string) => {
    if (typeof window !== 'undefined') {
      window.location.assign(url);
    }
  }
});

/**
 * `/app/settings/billing` — the plan a company is on, and the way off it.
 *
 * The checkout journey has three legs and this store owns the first and the
 * third:
 *
 *  1. `POST /billing/checkout` returns a `checkout_url`; the browser leaves.
 *  2. The provider takes the payment and calls the **webhook**, which is what
 *     actually activates the subscription (spec 7.5). The browser is not
 *     involved and must never be treated as proof of payment.
 *  3. The provider returns the browser to `/billing/{success,cancel}`, which
 *     redirect into this screen with `?checkout=`. `success` therefore means
 *     "the user came back from the provider", **not** "the plan is upgraded":
 *     the webhook may not have landed yet, so the screen re-reads the
 *     subscription and reports what the server actually says.
 */
@Injectable({ providedIn: 'root' })
export class BillingStore {
  private readonly service = inject(BillingService);
  private readonly auth = inject(AuthStore);
  private readonly redirect = inject(CHECKOUT_REDIRECT);

  private readonly summarySignal = signal<BillingSummary | null>(null);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);
  private readonly startingSignal = signal(false);
  private readonly outcomeSignal = signal<CheckoutOutcome | null>(null);

  private requestToken = 0;

  readonly summary = this.summarySignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();
  readonly starting = this.startingSignal.asReadonly();
  readonly outcome = this.outcomeSignal.asReadonly();

  readonly loading = computed(() => this.stateSignal() === 'idle' || this.stateSignal() === 'loading');
  readonly subscription = computed(() => this.summarySignal()?.subscription ?? null);
  readonly quota = computed(() => this.summarySignal()?.quota ?? null);

  /** Falls back to the session's company while the first read is in flight. */
  readonly plan = computed(() => this.summarySignal()?.plan ?? this.auth.company()?.plan ?? null);
  readonly isPaid = computed(() => this.plan() === UPGRADE_PLAN);

  /** Only an `owner` may start a checkout (contract: `POST /billing/checkout`). */
  readonly canCheckout = computed(() => this.auth.role() === 'owner');

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.subscription().subscribe({
      next: (summary) => {
        if (token !== this.requestToken) {
          return;
        }
        this.summarySignal.set(summary);
        this.stateSignal.set('ready');
      },
      error: (error: unknown) => {
        if (token !== this.requestToken) {
          return;
        }
        this.errorCodeSignal.set(errorCodeOf(error));
        this.stateSignal.set('error');
      }
    });
  }

  loadIfNeeded(): void {
    if (this.stateSignal() === 'idle') {
      this.load();
    }
  }

  setOutcome(outcome: CheckoutOutcome | null): void {
    this.outcomeSignal.set(outcome);
  }

  /**
   * Leg 1. `starting` is deliberately never cleared on success: the browser is
   * on its way to the provider and re-enabling the button would invite a second
   * checkout session for the same upgrade.
   */
  startCheckout(provider: PaymentProvider): void {
    if (this.startingSignal() || !this.canCheckout()) {
      return;
    }
    this.startingSignal.set(true);

    this.service.checkout({ provider, plan: UPGRADE_PLAN }).subscribe({
      next: (session) => {
        this.redirect(session.checkout_url);
      },
      error: () => {
        // `502 PAYMENT_PROVIDER_ERROR` and `403 FORBIDDEN` are already on screen
        // as a toast, keyed by their code. The user stays where they were.
        this.startingSignal.set(false);
      }
    });
  }

  reset(): void {
    this.requestToken++;
    this.summarySignal.set(null);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
    this.startingSignal.set(false);
    this.outcomeSignal.set(null);
  }
}
