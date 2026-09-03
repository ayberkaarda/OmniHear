import { inject } from '@angular/core';
import { CanActivateFn } from '@angular/router';

import { PaywallService } from '../paywall/paywall.service';
import { QuotaStore } from '../quota/quota.store';
import { BillingStore } from './billing.store';

/**
 * The `SubscriptionGuard` spec section 4 puts on `/app` alongside `AuthGuard`.
 *
 * It was left out in F2-FE with a note saying a guard with nothing to check
 * would be a no-op or a guess. `GET /billing/subscription` exists now, so it
 * has something to read — and this is what it does with it:
 *
 * 1. **Primes the billing state.** One read, at most once per session
 *    (`loadIfNeeded`), fired without being awaited. The dashboard must not wait
 *    on billing: a slow payment provider is not a reason to stall the overview.
 * 2. **Raises the wall on arrival.** When the quota is already known to be
 *    exhausted, the paywall opens as the user enters `/app`, instead of only
 *    after the next request comes back `402`. That closes spec 7.5's loop from
 *    the other end — the wall no longer needs a failed request to appear.
 *
 * **It refuses no navigation, and that is a decision, not an oversight.**
 * Spec 7.4 is explicit that an exhausted quota pauses *analysis* and that
 * comments accumulate rather than being lost; the collected data stays readable
 * and the account stays usable. Sending an exhausted tenant to `/402` instead
 * of `/app/overview` would make the product unreachable for a state the spec
 * describes as recoverable — and `/402`'s own "Back to the dashboard" link
 * would bounce straight back into the guard, which is a redirect loop, not a
 * paywall.
 */
export const subscriptionGuard: CanActivateFn = (): boolean => {
  inject(BillingStore).loadIfNeeded();

  if (inject(QuotaStore).level() === 'exceeded') {
    inject(PaywallService).open();
  }

  return true;
};
