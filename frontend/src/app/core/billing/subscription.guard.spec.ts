import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, RouterStateSnapshot } from '@angular/router';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { PaywallService } from '../paywall/paywall.service';
import { QuotaStore } from '../quota/quota.store';
import { makeBillingSummary } from '../settings/settings.fixtures';
import { BillingStore } from './billing.store';
import { subscriptionGuard } from './subscription.guard';

const SUBSCRIPTION = `${environment.apiBaseUrl}/v1/billing/subscription`;

describe('subscriptionGuard', () => {
  let http: HttpTestingController;
  let auth: AuthStore;
  let quota: QuotaStore;
  let paywall: PaywallService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    http = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthStore);
    quota = TestBed.inject(QuotaStore);
    paywall = TestBed.inject(PaywallService);
    TestBed.inject(BillingStore).reset();
    quota.reset();
    paywall.close();
  });

  afterEach(() => http.verify());

  function activate(): boolean {
    return TestBed.runInInjectionContext(
      () => subscriptionGuard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot) as boolean
    );
  }

  it('primes the billing summary once and does not wait for it', () => {
    auth.setSession('1|abc', makeUser(), makeCompany());

    expect(activate()).toBe(true);
    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());

    // Already read: entering another `/app` screen must not read it again.
    expect(activate()).toBe(true);
    http.expectNone(SUBSCRIPTION);
  });

  /**
   * Spec 7.5's wall, raised on arrival instead of only after the next request
   * comes back `402`.
   */
  it('opens the paywall when the quota is already exhausted', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200, quota_remaining: 0 }));

    expect(paywall.isOpen()).toBe(false);
    expect(activate()).toBe(true);
    expect(paywall.isOpen()).toBe(true);

    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary({ quota: { limit: 200, used: 200, remaining: 0 } }));
  });

  it('leaves the wall down while there is quota left', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200, quota_remaining: 12 }));

    expect(activate()).toBe(true);
    expect(paywall.isOpen()).toBe(false);

    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());
  });

  /**
   * The decision recorded in the guard itself: an exhausted quota pauses
   * *analysis*, not the product. Refusing navigation would make the collected
   * data unreachable for a state spec 7.4 calls recoverable — and `/402`'s own
   * link back to the dashboard would bounce off this guard forever.
   */
  it('never refuses navigation, even with the quota at zero', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200, quota_remaining: 0 }));

    const result = activate();
    expect(result).toBe(true);
    expect(typeof result).toBe('boolean');

    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary({ quota: { limit: 200, used: 200, remaining: 0 } }));
  });
});
