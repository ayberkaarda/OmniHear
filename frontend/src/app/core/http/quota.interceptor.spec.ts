import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { QuotaStore } from '../quota/quota.store';
import { QUOTA_REMAINING_HEADER, quotaInterceptor } from './quota.interceptor';

const URL = `${environment.apiBaseUrl}/v1/feedbacks`;

describe('quotaInterceptor', () => {
  let http: HttpClient;
  let controller: HttpTestingController;
  let quota: QuotaStore;
  let auth: AuthStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(withInterceptors([quotaInterceptor])), provideHttpClientTesting()]
    });
    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
    quota = TestBed.inject(QuotaStore);
    auth = TestBed.inject(AuthStore);
  });

  afterEach(() => controller.verify());

  it('reads X-Quota-Remaining off a successful response', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200, quota_remaining: 188 }));

    http.get(URL).subscribe();
    controller.expectOne(URL).flush({}, { headers: { [QUOTA_REMAINING_HEADER]: '150' } });

    expect(quota.remaining()).toBe(150);
    expect(quota.used()).toBe(50);
    expect(quota.level()).toBe('ok');
  });

  it('reads the header off an error response too - the 402 carries the final 0', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200, quota_remaining: 1 }));

    http.get(URL).subscribe({ error: () => undefined });
    controller.expectOne(URL).flush(
      { code: 'QUOTA_EXCEEDED', message: 'Quota exceeded.' },
      { status: 402, statusText: 'Payment Required', headers: { [QUOTA_REMAINING_HEADER]: '0' } }
    );

    expect(quota.remaining()).toBe(0);
    expect(quota.level()).toBe('exceeded');
  });

  it('falls back to the session value while no header has been seen', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200, quota_remaining: 40 }));

    expect(quota.remaining()).toBe(40);
    expect(quota.level()).toBe('warning');
  });

  it('reports nothing at all before a session exists', () => {
    expect(quota.remaining()).toBeNull();
    expect(quota.limit()).toBeNull();
    expect(quota.usedRatio()).toBeNull();
  });

  it('ignores a header that is not a number', () => {
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200, quota_remaining: 188 }));

    http.get(URL).subscribe();
    controller.expectOne(URL).flush({}, { headers: { [QUOTA_REMAINING_HEADER]: 'not-a-number' } });

    expect(quota.remaining()).toBe(188);
  });
});
