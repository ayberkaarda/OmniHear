import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { ApiError, isApiError } from '../errors/api-error';
import { errorMessageForCode } from '../errors/error-messages';
import { PaywallService } from '../paywall/paywall.service';
import { ToastService } from '../toast/toast.service';
import { errorInterceptor } from './error.interceptor';

const URL = `${environment.apiBaseUrl}/v1/feedbacks`;

interface Harness {
  http: HttpClient;
  controller: HttpTestingController;
  store: AuthStore;
  toasts: ToastService;
  paywall: PaywallService;
  navigate: jest.Mock;
}

function setup(): Harness {
  localStorage.clear();
  const navigate = jest.fn().mockResolvedValue(true);

  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(withInterceptors([errorInterceptor])),
      provideHttpClientTesting(),
      { provide: Router, useValue: { navigate } }
    ]
  });

  return {
    http: TestBed.inject(HttpClient),
    controller: TestBed.inject(HttpTestingController),
    store: TestBed.inject(AuthStore),
    toasts: TestBed.inject(ToastService),
    paywall: TestBed.inject(PaywallService),
    navigate
  };
}

/** Fires the request, flushes the given failure, and hands back the thrown value. */
function failWith(h: Harness, body: unknown, status: number, statusText: string): Promise<unknown> {
  const thrown = new Promise<unknown>((resolve) => {
    h.http.get(URL).subscribe({ error: (error: unknown) => resolve(error) });
  });
  h.controller.expectOne(URL).flush(body, { status, statusText });
  return thrown;
}

describe('errorInterceptor', () => {
  it('401 UNAUTHENTICATED clears the session and sends the user to the login page', async () => {
    const h = setup();
    h.store.setSession('1|abc', makeUser(), makeCompany());

    await failWith(h, { code: 'UNAUTHENTICATED', message: 'Unauthenticated.' }, 401, 'Unauthorized');

    expect(h.store.isAuthenticated()).toBe(false);
    expect(h.navigate).toHaveBeenCalledWith(['/auth/login']);
    expect(h.toasts.toasts()).toHaveLength(0);
    h.controller.verify();
  });

  it('401 INVALID_CREDENTIALS does NOT sign the user out - it only toasts', async () => {
    const h = setup();
    h.store.setSession('1|abc', makeUser(), makeCompany());

    await failWith(h, { code: 'INVALID_CREDENTIALS', message: 'Invalid credentials.' }, 401, 'Unauthorized');

    expect(h.store.isAuthenticated()).toBe(true);
    expect(h.navigate).not.toHaveBeenCalled();
    expect(h.toasts.toasts()[0].message).toBe(errorMessageForCode('INVALID_CREDENTIALS'));
    h.controller.verify();
  });

  it('402 QUOTA_EXCEEDED opens the blocking paywall modal and never a toast', async () => {
    const h = setup();

    await failWith(h, { code: 'QUOTA_EXCEEDED', message: 'Quota exceeded.' }, 402, 'Payment Required');

    expect(h.paywall.isOpen()).toBe(true);
    expect(h.toasts.toasts()).toHaveLength(0);
    expect(h.navigate).not.toHaveBeenCalled();
    h.controller.verify();
  });

  it('403 EMAIL_NOT_VERIFIED redirects to the verification screen', async () => {
    const h = setup();

    await failWith(h, { code: 'EMAIL_NOT_VERIFIED', message: 'Your email address is not verified.' }, 403, 'Forbidden');

    expect(h.navigate).toHaveBeenCalledWith(['/auth/verify-email']);
    expect(h.toasts.toasts()).toHaveLength(0);
    h.controller.verify();
  });

  it('403 FORBIDDEN is a plain toast, not a redirect', async () => {
    const h = setup();

    await failWith(h, { code: 'FORBIDDEN', message: 'This action is unauthorized.' }, 403, 'Forbidden');

    expect(h.navigate).not.toHaveBeenCalled();
    expect(h.toasts.toasts()[0].message).toBe(errorMessageForCode('FORBIDDEN'));
    h.controller.verify();
  });

  it('rethrows a normalised ApiError so the calling form can read field errors', async () => {
    const h = setup();

    const error = await failWith(
      h,
      { code: 'VALIDATION_ERROR', message: 'Invalid.', errors: { email: ['Already taken.'] } },
      422,
      'Unprocessable Content'
    );

    expect(isApiError(error)).toBe(true);
    expect((error as ApiError).code).toBe('VALIDATION_ERROR');
    expect((error as ApiError).fieldErrors).toEqual({ email: ['Already taken.'] });
    h.controller.verify();
  });

  it('an unknown server code still toasts a localised message, never the raw string', async () => {
    const h = setup();
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);

    await failWith(h, { code: 'SOMETHING_NEW', message: 'raw server text' }, 500, 'Server Error');

    expect(h.toasts.toasts()[0].message).not.toContain('raw server text');
    expect(h.toasts.toasts()[0].message).toBe(errorMessageForCode('SOMETHING_NEW'));
    warn.mockRestore();
    h.controller.verify();
  });
});
