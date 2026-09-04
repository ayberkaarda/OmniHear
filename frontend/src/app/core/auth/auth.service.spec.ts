import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import {
  makeCompany,
  makeRecoveryCodes,
  makeTwoFactorChallenge,
  makeTwoFactorEnrolment,
  makeUser
} from './auth.fixtures';
import { AuthService } from './auth.service';
import { AuthStore } from './auth.store';

const BASE = `${environment.apiBaseUrl}/v1/auth`;

describe('AuthService', () => {
  let service: AuthService;
  let store: AuthStore;
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    service = TestBed.inject(AuthService);
    store = TestBed.inject(AuthStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('register posts the contract body and stores the returned session', () => {
    const payload = {
      name: 'Ada Lovelace',
      email: 'ada@acme.com',
      password: 'correct-horse-battery',
      password_confirmation: 'correct-horse-battery',
      company_name: 'Acme Inc.'
    };

    service.register(payload).subscribe();

    const request = http.expectOne(`${BASE}/register`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(payload);

    request.flush({ token: '1|new', user: makeUser(), company: makeCompany() });

    expect(store.isAuthenticated()).toBe(true);
    expect(store.token()).toBe('1|new');
  });

  it('login defaults device_name to "web" so the token stays revocable per device', () => {
    service.login({ email: 'ada@acme.com', password: 'secret' }).subscribe();

    const request = http.expectOne(`${BASE}/login`);
    expect(request.request.body).toEqual({ email: 'ada@acme.com', password: 'secret', device_name: 'web' });

    request.flush({ token: '1|abc', user: makeUser(), company: makeCompany() });
    expect(store.token()).toBe('1|abc');
  });

  it('login keeps an explicit device_name', () => {
    service.login({ email: 'ada@acme.com', password: 'secret', device_name: 'ipad' }).subscribe();

    const request = http.expectOne(`${BASE}/login`);
    expect(request.request.body).toEqual({ email: 'ada@acme.com', password: 'secret', device_name: 'ipad' });
    request.flush({ token: '1|abc', user: makeUser(), company: makeCompany() });
  });

  it('logout clears the store', () => {
    store.setSession('1|abc', makeUser(), makeCompany());

    service.logout().subscribe();
    http.expectOne(`${BASE}/logout`).flush(null, { status: 204, statusText: 'No Content' });

    expect(store.isAuthenticated()).toBe(false);
    expect(localStorage.getItem('omnihear.token')).toBeNull();
  });

  it('me() settles a restored session without replacing the token', () => {
    localStorage.setItem('omnihear.token', '1|restored');
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(AuthService);
    store = TestBed.inject(AuthStore);
    http = TestBed.inject(HttpTestingController);

    service.me().subscribe();
    const request = http.expectOne(`${BASE}/me`);
    expect(request.request.method).toBe('GET');
    request.flush({ user: makeUser(), company: makeCompany() });

    expect(store.isAuthenticated()).toBe(true);
    expect(store.token()).toBe('1|restored');
  });

  it('forgot-password and reset-password hit the contract paths', () => {
    service.forgotPassword({ email: 'ada@acme.com' }).subscribe();
    http.expectOne(`${BASE}/forgot-password`).flush({ message: 'ok' }, { status: 202, statusText: 'Accepted' });

    service
      .resetPassword({
        token: 'reset-token',
        email: 'ada@acme.com',
        password: 'correct-horse-battery',
        password_confirmation: 'correct-horse-battery'
      })
      .subscribe();
    http.expectOne(`${BASE}/reset-password`).flush({ message: 'ok' });
  });

  it('verifyEmail forwards the four signed-link values and refreshes the user', () => {
    store.setSession('1|abc', makeUser({ email_verified_at: null }), makeCompany());

    service.verifyEmail({ id: 1, hash: 'h4sh', expires: 1234567890, signature: 's1g' }).subscribe();

    const request = http.expectOne(`${BASE}/email/verify`);
    expect(request.request.body).toEqual({ id: 1, hash: 'h4sh', expires: 1234567890, signature: 's1g' });
    request.flush({ user: makeUser({ email_verified_at: '2026-09-02T12:00:00+00:00' }) });

    expect(store.isEmailVerified()).toBe(true);
  });

  it('resendVerificationEmail posts to email/resend', () => {
    service.resendVerificationEmail().subscribe();
    http.expectOne(`${BASE}/email/resend`).flush({ message: 'ok' }, { status: 202, statusText: 'Accepted' });
  });

  /* ------------------------------------------------------------ two-factor */

  /**
   * The challenge answer is a 200, not an error, and it carries no session.
   * Writing it into the store would hand a five-minute, single-purpose token to
   * every guard and interceptor as though the user were signed in.
   */
  it('login leaves the store alone when the answer is a two-factor challenge', () => {
    let received: unknown = null;
    service.login({ email: 'ada@acme.com', password: 'secret' }).subscribe((response) => (received = response));

    const request = http.expectOne(`${BASE}/login`);
    request.flush(makeTwoFactorChallenge());

    expect(received).toEqual(makeTwoFactorChallenge());
    expect(store.isAuthenticated()).toBe(false);
    expect(store.token()).toBeNull();
  });

  it('twoFactorChallenge sends the challenge token as its own Authorization header', () => {
    service.twoFactorChallenge('9|challenge-token', { code: '123456' }).subscribe();

    const request = http.expectOne(`${BASE}/two-factor/challenge`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ code: '123456' });
    expect(request.request.headers.get('Authorization')).toBe('Bearer 9|challenge-token');

    // Same envelope a plain login returns — one success path for the caller.
    request.flush({ token: '1|full', user: makeUser({ two_factor_enabled: true }), company: makeCompany() });

    expect(store.isAuthenticated()).toBe(true);
    expect(store.token()).toBe('1|full');
  });

  it('twoFactorChallenge can send a recovery code instead of a TOTP code', () => {
    service.twoFactorChallenge('9|challenge-token', { recovery_code: 'a1b2-c3d4' }).subscribe();

    const request = http.expectOne(`${BASE}/two-factor/challenge`);
    expect(request.request.body).toEqual({ recovery_code: 'a1b2-c3d4' });
    request.flush({ token: '1|full', user: makeUser({ two_factor_enabled: true }), company: makeCompany() });
  });

  it('startTwoFactorEnrolment posts an empty body and stores nothing', () => {
    store.setSession('1|abc', makeUser(), makeCompany());

    let received: unknown = null;
    service.startTwoFactorEnrolment().subscribe((response) => (received = response));

    const request = http.expectOne(`${BASE}/two-factor`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({});
    request.flush(makeTwoFactorEnrolment(), { status: 201, statusText: 'Created' });

    expect(received).toEqual(makeTwoFactorEnrolment());
    // Enrolment is not confirmation: the flag must not move here, or a user who
    // closed the tab mid-setup would be locked out by a factor they never armed.
    expect(store.user()?.two_factor_enabled).toBe(false);
  });

  it('confirmTwoFactor flips the cached flag and returns the codes once', () => {
    store.setSession('1|abc', makeUser(), makeCompany());

    let received: string[] = [];
    service.confirmTwoFactor({ code: '123456' }).subscribe((response) => (received = response.recovery_codes));

    const request = http.expectOne(`${BASE}/two-factor/confirm`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ code: '123456' });
    request.flush(makeRecoveryCodes());

    expect(received).toHaveLength(8);
    expect(store.user()?.two_factor_enabled).toBe(true);
  });

  it('disableTwoFactor sends both factors in the DELETE body and clears the flag', () => {
    store.setSession('1|abc', makeUser({ two_factor_enabled: true }), makeCompany());

    service.disableTwoFactor({ password: 'correct-horse-battery', code: '123456' }).subscribe();

    const request = http.expectOne(`${BASE}/two-factor`);
    expect(request.request.method).toBe('DELETE');
    expect(request.request.body).toEqual({ password: 'correct-horse-battery', code: '123456' });
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(store.user()?.two_factor_enabled).toBe(false);
  });

  it('regenerateRecoveryCodes requires a current code and leaves the flag alone', () => {
    store.setSession('1|abc', makeUser({ two_factor_enabled: true }), makeCompany());

    service.regenerateRecoveryCodes({ code: '654321' }).subscribe();

    const request = http.expectOne(`${BASE}/two-factor/recovery-codes`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ code: '654321' });
    request.flush(makeRecoveryCodes({ recovery_codes: ['z9y8-x7w6'] }));

    expect(store.user()?.two_factor_enabled).toBe(true);
  });
});
