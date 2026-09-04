import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { ToastService } from '../toast/toast.service';
import { makeCompany, makeRecoveryCodes, makeTwoFactorEnrolment, makeUser } from './auth.fixtures';
import { AuthStore } from './auth.store';
import { TwoFactorStore } from './two-factor.store';

const BASE = `${environment.apiBaseUrl}/v1/auth`;

describe('TwoFactorStore', () => {
  let store: TwoFactorStore;
  let auth: AuthStore;
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(TwoFactorStore);
    auth = TestBed.inject(AuthStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
    auth.setSession('1|abc', makeUser(), makeCompany());
  });

  afterEach(() => http.verify());

  it('reads "enabled" from the session rather than keeping a second copy', () => {
    expect(store.enabled()).toBe(false);

    auth.setUser(makeUser({ two_factor_enabled: true }));

    expect(store.enabled()).toBe(true);
  });

  it('start() holds the enrolment response without confirming anything', () => {
    store.start();

    const request = http.expectOne(`${BASE}/two-factor`);
    expect(request.request.method).toBe('POST');
    request.flush(makeTwoFactorEnrolment(), { status: 201, statusText: 'Created' });

    expect(store.enrolling()).toBe(true);
    expect(store.enrolment()?.secret).toBe('JBSWY3DPEHPK3PXP');
    expect(store.enrolment()?.qr_svg_data_uri.startsWith('data:image/svg+xml;base64,')).toBe(true);
    expect(store.starting()).toBe(false);
    // A started enrolment is not an armed one.
    expect(store.enabled()).toBe(false);
  });

  /**
   * The secret is served exactly once (contract, "Secrets and logging"). A copy
   * in `localStorage` would survive the tab, the session and the user's memory
   * of having started — invariant I5.
   */
  it('never writes the secret to browser storage', () => {
    store.start();
    http.expectOne(`${BASE}/two-factor`).flush(makeTwoFactorEnrolment());

    const stored = Object.keys(localStorage).map((key) => localStorage.getItem(key) ?? '');
    expect(stored.some((value) => value.includes('JBSWY3DPEHPK3PXP'))).toBe(false);
  });

  it('refuses to start while two-factor is already confirmed', () => {
    auth.setUser(makeUser({ two_factor_enabled: true }));

    store.start();

    // The count is the assertion: a call that reached the network here would
    // mean the guard is decorative and the server answers 409 instead.
    expect(http.match(`${BASE}/two-factor`)).toHaveLength(0);
  });

  it('confirm() drops the secret, keeps the codes and flips the flag', () => {
    store.start();
    http.expectOne(`${BASE}/two-factor`).flush(makeTwoFactorEnrolment());

    store.confirm('123456');

    const request = http.expectOne(`${BASE}/two-factor/confirm`);
    expect(request.request.body).toEqual({ code: '123456' });
    request.flush(makeRecoveryCodes());

    expect(store.enrolment()).toBeNull();
    expect(store.recoveryCodes()).toHaveLength(8);
    expect(store.recoveryOrigin()).toBe('enrolment');
    expect(store.enabled()).toBe(true);
    expect(TestBed.inject(ToastService).toasts()).toHaveLength(1);
  });

  it('confirm() surfaces the field errors of a rejected code and stays enrolling', () => {
    store.start();
    http.expectOne(`${BASE}/two-factor`).flush(makeTwoFactorEnrolment());

    store.confirm('000000');
    http
      .expectOne(`${BASE}/two-factor/confirm`)
      .flush(
        { code: 'TWO_FACTOR_CODE_INVALID', message: 'Invalid code.', errors: { code: ['That code is not valid.'] } },
        { status: 422, statusText: 'Unprocessable Content' }
      );

    expect(store.confirming()).toBe(false);
    expect(store.confirmErrors()?.['code']?.[0]).toBe('That code is not valid.');
    // The QR is still on screen: the user has to try again, not start over.
    expect(store.enrolling()).toBe(true);
    expect(store.enabled()).toBe(false);
  });

  it('confirm() does nothing when no enrolment is under way', () => {
    store.confirm('123456');

    expect(http.match(`${BASE}/two-factor/confirm`)).toHaveLength(0);
  });

  it('disable() sends both factors and clears everything it was holding', () => {
    auth.setUser(makeUser({ two_factor_enabled: true }));

    store.disable('correct-horse-battery', '123456');

    const request = http.expectOne(`${BASE}/two-factor`);
    expect(request.request.method).toBe('DELETE');
    expect(request.request.body).toEqual({ password: 'correct-horse-battery', code: '123456' });
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(store.enabled()).toBe(false);
    expect(store.recoveryCodes()).toBeNull();
    expect(store.enrolment()).toBeNull();
  });

  it('regenerate() replaces the visible codes and says where they came from', () => {
    auth.setUser(makeUser({ two_factor_enabled: true }));

    store.regenerate('654321');

    const request = http.expectOne(`${BASE}/two-factor/recovery-codes`);
    expect(request.request.body).toEqual({ code: '654321' });
    request.flush(makeRecoveryCodes({ recovery_codes: ['z9y8-x7w6', 'v5u4-t3s2'] }));

    expect(store.recoveryCodes()).toEqual(['z9y8-x7w6', 'v5u4-t3s2']);
    expect(store.recoveryOrigin()).toBe('regeneration');
    expect(store.enabled()).toBe(true);
  });

  it('regenerate() refuses while two-factor is off', () => {
    store.regenerate('654321');

    expect(http.match(`${BASE}/two-factor/recovery-codes`)).toHaveLength(0);
  });

  it('dismissing the codes forgets them without touching the server', () => {
    auth.setUser(makeUser({ two_factor_enabled: true }));
    store.regenerate('654321');
    http.expectOne(`${BASE}/two-factor/recovery-codes`).flush(makeRecoveryCodes());

    store.dismissRecoveryCodes();

    expect(store.recoveryCodes()).toBeNull();
    expect(store.recoveryOrigin()).toBeNull();
  });

  it('cancelEnrolment() forgets the unconfirmed secret', () => {
    store.start();
    http.expectOne(`${BASE}/two-factor`).flush(makeTwoFactorEnrolment());

    store.cancelEnrolment();

    expect(store.enrolling()).toBe(false);
    expect(store.enrolment()).toBeNull();
  });
});
