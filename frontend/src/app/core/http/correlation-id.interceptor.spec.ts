import { HttpClient, HttpHeaders, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import {
  CORRELATION_ID_HEADER,
  correlationIdInterceptor,
  generateCorrelationId
} from './correlation-id.interceptor';

describe('correlationIdInterceptor', () => {
  let http: HttpClient;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(withInterceptors([correlationIdInterceptor])), provideHttpClientTesting()]
    });
    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => controller.verify());

  it('stamps a correlation id on every API request', () => {
    http.get(`${environment.apiBaseUrl}/v1/auth/me`).subscribe();

    const request = controller.expectOne(`${environment.apiBaseUrl}/v1/auth/me`);
    expect(request.request.headers.get(CORRELATION_ID_HEADER)).toBeTruthy();
    request.flush({});
  });

  it('gives two requests two different ids', () => {
    http.get(`${environment.apiBaseUrl}/v1/a`).subscribe();
    http.get(`${environment.apiBaseUrl}/v1/b`).subscribe();

    const first = controller.expectOne(`${environment.apiBaseUrl}/v1/a`);
    const second = controller.expectOne(`${environment.apiBaseUrl}/v1/b`);

    expect(first.request.headers.get(CORRELATION_ID_HEADER)).not.toBe(
      second.request.headers.get(CORRELATION_ID_HEADER)
    );
    first.flush({});
    second.flush({});
  });

  it('does not overwrite an id the caller set explicitly', () => {
    http
      .get(`${environment.apiBaseUrl}/v1/auth/me`, {
        headers: new HttpHeaders({ [CORRELATION_ID_HEADER]: 'caller-supplied' })
      })
      .subscribe();

    const request = controller.expectOne(`${environment.apiBaseUrl}/v1/auth/me`);
    expect(request.request.headers.get(CORRELATION_ID_HEADER)).toBe('caller-supplied');
    request.flush({});
  });

  it('leaves third-party requests alone', () => {
    http.get('https://cdn.example.com/logo.svg').subscribe();

    const request = controller.expectOne('https://cdn.example.com/logo.svg');
    expect(request.request.headers.has(CORRELATION_ID_HEADER)).toBe(false);
    request.flush({});
  });

  it('falls back to a non-crypto id when randomUUID is unavailable', () => {
    const original = globalThis.crypto;
    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });

    expect(generateCorrelationId()).toMatch(/^omnihear-/);

    Object.defineProperty(globalThis, 'crypto', { value: original, configurable: true });
  });
});
