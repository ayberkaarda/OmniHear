import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from './auth.fixtures';
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
});
