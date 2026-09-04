import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { authInterceptor } from './auth.interceptor';

describe('authInterceptor', () => {
  let http: HttpClient;
  let controller: HttpTestingController;
  let store: AuthStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(withInterceptors([authInterceptor])), provideHttpClientTesting()]
    });
    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
    store = TestBed.inject(AuthStore);
  });

  afterEach(() => controller.verify());

  it('adds Accept and Accept-Language but no Authorization while signed out', () => {
    http.get(`${environment.apiBaseUrl}/v1/auth/me`).subscribe({ error: () => undefined });

    const request = controller.expectOne(`${environment.apiBaseUrl}/v1/auth/me`);
    expect(request.request.headers.get('Accept')).toBe('application/json');
    expect(request.request.headers.get('Accept-Language')).toBeTruthy();
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({});
  });

  it('attaches the bearer token once a session exists', () => {
    store.setSession('1|abc', makeUser(), makeCompany());

    http.get(`${environment.apiBaseUrl}/v1/feedbacks`).subscribe();

    const request = controller.expectOne(`${environment.apiBaseUrl}/v1/feedbacks`);
    expect(request.request.headers.get('Authorization')).toBe('Bearer 1|abc');
    request.flush({});
  });

  it('leaves third-party requests untouched so the token cannot leak', () => {
    store.setSession('1|abc', makeUser(), makeCompany());

    http.get('https://cdn.example.com/logo.svg').subscribe();

    const request = controller.expectOne('https://cdn.example.com/logo.svg');
    expect(request.request.headers.has('Authorization')).toBe(false);
    expect(request.request.headers.has('Accept-Language')).toBe(false);
    request.flush({});
  });
  /**
   * The two-factor challenge carries a five-minute challenge token that is
   * deliberately never stored (`docs/contracts/w10-two-factor.md`). If the
   * interceptor overwrote it with a leftover session token, the one endpoint
   * that only accepts a challenge token would receive the wrong credential —
   * and the failure would look like a wrong TOTP code.
   */
  it('does not overwrite an Authorization header the caller set itself', () => {
    store.setSession('1|session', makeUser(), makeCompany());

    http
      .post(
        `${environment.apiBaseUrl}/v1/auth/two-factor/challenge`,
        { code: '123456' },
        { headers: { Authorization: 'Bearer 9|challenge' } }
      )
      .subscribe();

    const request = controller.expectOne(`${environment.apiBaseUrl}/v1/auth/two-factor/challenge`);
    expect(request.request.headers.get('Authorization')).toBe('Bearer 9|challenge');
    // The rest of the contract's headers are still added.
    expect(request.request.headers.get('Accept')).toBe('application/json');
    expect(request.request.headers.get('Accept-Language')).toBeTruthy();
    request.flush({});
  });
});
