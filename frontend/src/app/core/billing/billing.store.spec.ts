import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { makeBillingSummary, makeSubscription } from '../settings/settings.fixtures';
import { CHECKOUT_REDIRECT, BillingStore } from './billing.store';

const SUBSCRIPTION = `${environment.apiBaseUrl}/v1/billing/subscription`;
const CHECKOUT = `${environment.apiBaseUrl}/v1/billing/checkout`;

describe('BillingStore', () => {
  let store: BillingStore;
  let auth: AuthStore;
  let http: HttpTestingController;
  let redirects: string[];

  beforeEach(() => {
    redirects = [];
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: CHECKOUT_REDIRECT, useValue: (url: string) => redirects.push(url) }
      ]
    });
    store = TestBed.inject(BillingStore);
    auth = TestBed.inject(AuthStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
    auth.setSession('1|abc', makeUser({ role: 'owner' }), makeCompany());
  });

  afterEach(() => http.verify());

  it('reads the plan, the subscription and the quota', () => {
    store.load();
    http.expectOne(SUBSCRIPTION).flush(
      makeBillingSummary({
        subscription: makeSubscription(),
        plan: 'pro',
        quota: { limit: 5000, used: 120, remaining: 4880 }
      })
    );

    expect(store.plan()).toBe('pro');
    expect(store.isPaid()).toBe(true);
    expect(store.subscription()?.provider).toBe('stripe');
    expect(store.quota()).toEqual({ limit: 5000, used: 120, remaining: 4880 });
  });

  it('falls back to the session plan until the first read lands', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ plan: 'free' }));
    expect(store.plan()).toBe('free');
    expect(store.isPaid()).toBe(false);
  });

  it('sends the browser to the provider, and only ever once', () => {
    store.startCheckout('stripe');

    const request = http.expectOne(CHECKOUT);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ provider: 'stripe', plan: 'pro' });
    request.flush({ provider: 'stripe', checkout_url: 'https://checkout.stripe.com/c/pay/cs_test', session_id: 'cs_test' });

    expect(redirects).toEqual(['https://checkout.stripe.com/c/pay/cs_test']);

    // The browser is on its way out; a second press must not open a second session.
    store.startCheckout('stripe');
    http.expectNone(CHECKOUT);
  });

  it('leaves the user where they were when the provider fails', () => {
    store.startCheckout('iyzico');
    http
      .expectOne(CHECKOUT)
      .flush({ code: 'PAYMENT_PROVIDER_ERROR', message: 'gateway down' }, { status: 502, statusText: 'Bad Gateway' });

    expect(redirects).toEqual([]);
    expect(store.starting()).toBe(false);
  });

  /** `POST /billing/checkout` is `owner` only, so nothing else may even try. */
  it('does not start a checkout for a non-owner', () => {
    auth.setSession('1|abc', makeUser({ role: 'admin' }), makeCompany());
    expect(store.canCheckout()).toBe(false);

    store.startCheckout('stripe');
    http.expectNone(CHECKOUT);
    expect(redirects).toEqual([]);
  });
});
