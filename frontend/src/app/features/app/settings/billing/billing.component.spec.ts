import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../../environments/environment';
import { makeCompany, makeUser } from '../../../../core/auth/auth.fixtures';
import { UserRole } from '../../../../core/auth/auth.models';
import { AuthStore } from '../../../../core/auth/auth.store';
import { BillingStore, CHECKOUT_REDIRECT } from '../../../../core/billing/billing.store';
import { makeBillingSummary, makeSubscription } from '../../../../core/settings/settings.fixtures';
import { BillingComponent } from './billing.component';

const SUBSCRIPTION = `${environment.apiBaseUrl}/v1/billing/subscription`;
const CHECKOUT = `${environment.apiBaseUrl}/v1/billing/checkout`;

describe('BillingComponent', () => {
  let fixture: ComponentFixture<BillingComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;
  let redirects: string[];

  function mount(role: UserRole = 'owner'): void {
    redirects = [];
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [BillingComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: CHECKOUT_REDIRECT, useValue: (url: string) => redirects.push(url) }
      ]
    });
    TestBed.inject(BillingStore).reset();
    TestBed.inject(AuthStore).setSession('1|abc', makeUser({ role }), makeCompany());
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(BillingComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  }

  function buttonWith(text: string): HTMLButtonElement {
    const match = Array.from(element.querySelectorAll('button')).find((button) =>
      (button.textContent ?? '').includes(text)
    );
    if (match === undefined) {
      throw new Error(`No button containing "${text}"`);
    }
    return match as HTMLButtonElement;
  }

  afterEach(() => http.verify());

  it('reports the free plan and its usage', () => {
    mount();
    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary({ quota: { limit: 200, used: 160, remaining: 40 } }));
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="billing-plan"]')?.textContent).toContain('Free');
    expect(element.querySelector('[data-testid="billing-quota-used"]')?.textContent).toContain('160');
    expect(element.textContent).toContain('You are on the free plan');
  });

  /** Spec 7.5's loop, from the modal's destination to the provider's page. */
  it('takes the owner to the provider and sends the plan the API expects', () => {
    mount('owner');
    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());
    fixture.detectChanges();

    buttonWith('Pay with card').click();

    const request = http.expectOne(CHECKOUT);
    expect(request.request.body).toEqual({ provider: 'stripe', plan: 'pro' });
    request.flush({ provider: 'stripe', checkout_url: 'https://checkout.stripe.com/c/pay/cs_1', session_id: 'cs_1' });

    expect(redirects).toEqual(['https://checkout.stripe.com/c/pay/cs_1']);
  });

  it('offers iyzico as the second provider', () => {
    mount('owner');
    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());
    fixture.detectChanges();

    buttonWith('Pay with iyzico').click();
    const request = http.expectOne(CHECKOUT);
    expect(request.request.body).toEqual({ provider: 'iyzico', plan: 'pro' });
    request.flush({ provider: 'iyzico', checkout_url: 'https://sandbox-cpp.iyzipay.com/x', session_id: 'x' });

    expect(redirects).toEqual(['https://sandbox-cpp.iyzipay.com/x']);
  });

  it('explains rather than offering a button an admin cannot use', () => {
    mount('admin');
    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="billing-checkout"]')).toBeNull();
    expect(element.querySelector('[data-testid="billing-owner-only"]')?.textContent).toContain(
      'Only the company owner'
    );
  });

  it('hides the upgrade panel once the plan is paid', () => {
    mount('owner');
    http.expectOne(SUBSCRIPTION).flush(
      makeBillingSummary({ plan: 'pro', subscription: makeSubscription(), quota: { limit: 5000, used: 1, remaining: 4999 } })
    );
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="billing-checkout"]')).toBeNull();
    expect(element.querySelector('[data-testid="billing-plan"]')?.textContent).toContain('Pro');
    expect(element.textContent).toContain('stripe');
  });

  /**
   * `?checkout=success` means the browser came back, not that the plan changed:
   * the webhook decides that (spec 7.5, 7.6). The banner says so and the screen
   * re-reads the subscription rather than asserting an upgrade.
   */
  it('re-reads the subscription on return and does not claim the upgrade', () => {
    mount('owner');
    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());
    fixture.detectChanges();

    fixture.componentRef.setInput('checkout', 'success');
    fixture.detectChanges();

    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());
    fixture.detectChanges();

    const banner = element.querySelector('[data-testid="checkout-success"]');
    expect(banner?.textContent).toContain('Your payment was submitted');
    // Still free, because that is what the server said.
    expect(element.querySelector('[data-testid="billing-plan"]')?.textContent).toContain('Free');
  });

  it('says nothing was charged when the checkout was abandoned', () => {
    mount('owner');
    http.expectOne(SUBSCRIPTION).flush(makeBillingSummary());
    fixture.detectChanges();

    fixture.componentRef.setInput('checkout', 'cancel');
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="checkout-cancel"]')?.textContent).toContain('nothing was charged');
    // A cancel is not a reason to re-read anything.
    http.expectNone(SUBSCRIPTION);
  });
});
