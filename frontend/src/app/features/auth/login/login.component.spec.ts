import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { makeCompany, makeTwoFactorChallenge, makeUser } from '../../../core/auth/auth.fixtures';
import { AuthStore } from '../../../core/auth/auth.store';
import { errorInterceptor } from '../../../core/http/error.interceptor';
import { LoginComponent } from './login.component';

interface Control {
  setValue(v: string): void;
  value: string;
}

interface LoginInternals {
  form: { controls: { email: Control; password: Control } };
  challengeForm: { controls: { code: Control; recovery_code: Control } };
  step(): 'credentials' | 'challenge';
  usingRecoveryCode(): boolean;
  challengeExpired(): boolean;
  onSubmit(): void;
  onChallengeSubmit(): void;
  useRecoveryCode(enabled: boolean): void;
  cancelChallenge(): void;
  submitting(): boolean;
  errorFor(field: string): string | undefined;
  challengeErrorFor(field: 'code' | 'recovery_code'): string | undefined;
}

describe('LoginComponent', () => {
  let fixture: ComponentFixture<LoginComponent>;
  let component: LoginInternals;
  let http: HttpTestingController;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([errorInterceptor])),
        provideHttpClientTesting()
      ]
    }).compileComponents();

    fixture = TestBed.createComponent(LoginComponent);
    component = fixture.componentInstance as unknown as LoginInternals;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('renders an accessible form: labelled fields and a submit button', () => {
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('h1')?.textContent).toBeTruthy();
    const inputs = Array.from(element.querySelectorAll('input'));
    expect(inputs).toHaveLength(2);
    for (const input of inputs) {
      const label = element.querySelector(`label[for="${input.id}"]`);
      expect(label).toBeTruthy();
    }
    expect(element.querySelector('button[type="submit"]')).toBeTruthy();
  });

  it('does not call the API while the form is invalid, and surfaces field errors', async () => {
    component.onSubmit();
    fixture.detectChanges();
    await fixture.whenStable();

    http.expectNone(`${environment.apiBaseUrl}/v1/auth/login`);
    expect(component.errorFor('email')).toBeTruthy();
    expect(component.errorFor('password')).toBeTruthy();
  });

  it('signs in and follows the redirect query parameter is absent -> dashboard', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    component.form.controls.email.setValue('ada@acme.com');
    component.form.controls.password.setValue('correct-horse-battery');
    component.onSubmit();

    const request = http.expectOne(`${environment.apiBaseUrl}/v1/auth/login`);
    expect(request.request.body).toEqual({
      email: 'ada@acme.com',
      password: 'correct-horse-battery',
      device_name: 'web'
    });
    request.flush({ token: '1|abc', user: makeUser(), company: makeCompany() });

    fixture.detectChanges();
    await fixture.whenStable();

    expect(TestBed.inject(AuthStore).isAuthenticated()).toBe(true);
    expect(navigate).toHaveBeenCalledWith('/app/overview');
    expect(component.submitting()).toBe(false);
  });

  it('shows the server field error returned by a 422 and stops the spinner', async () => {
    component.form.controls.email.setValue('ada@acme.com');
    component.form.controls.password.setValue('correct-horse-battery');
    component.onSubmit();

    http.expectOne(`${environment.apiBaseUrl}/v1/auth/login`).flush(
      { code: 'VALIDATION_ERROR', message: 'Invalid.', errors: { email: ['This account is locked.'] } },
      { status: 422, statusText: 'Unprocessable Content' }
    );

    fixture.detectChanges();
    await fixture.whenStable();

    expect(component.submitting()).toBe(false);
    expect(component.errorFor('email')).toBe('This account is locked.');
  });

  /* ---------------------------------------------- the second factor (W10) */

  const LOGIN_URL = `${environment.apiBaseUrl}/v1/auth/login`;
  const CHALLENGE_URL = `${environment.apiBaseUrl}/v1/auth/two-factor/challenge`;

  /** First factor accepted, second still owed. Always a 200 — never a 401. */
  async function reachChallengeStep(): Promise<void> {
    component.form.controls.email.setValue('ada@acme.com');
    component.form.controls.password.setValue('correct-horse-battery');
    component.onSubmit();
    http.expectOne(LOGIN_URL).flush(makeTwoFactorChallenge(), { status: 200, statusText: 'OK' });
    fixture.detectChanges();
    await fixture.whenStable();
  }

  it('swaps to the code step on a 200 challenge, without navigating anywhere', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    const store = TestBed.inject(AuthStore);

    await reachChallengeStep();

    expect(component.step()).toBe('challenge');
    // No session yet: a challenge is a completed first factor, not a login.
    expect(store.isAuthenticated()).toBe(false);
    expect(store.token()).toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    expect(component.submitting()).toBe(false);

    const element = fixture.nativeElement as HTMLElement;
    const form = element.querySelector('[data-testid="two-factor-form"]');
    expect(form).toBeTruthy();
    // The whole second factor is one field; it has to be labelled and reachable.
    const input = form?.querySelector('input');
    expect(input).toBeTruthy();
    expect(element.querySelector(`label[for="${input?.id}"]`)).toBeTruthy();
    expect(input?.getAttribute('autocomplete')).toBe('one-time-code');
    // The page's single h1 is the step the user is actually on.
    expect(element.querySelectorAll('h1')).toHaveLength(1);
    expect(element.querySelector('h1')?.textContent).toContain('Confirm it is you');
  });

  it('sends the code with the challenge token, then completes the same login', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    await reachChallengeStep();

    component.challengeForm.controls.code.setValue('123456');
    component.onChallengeSubmit();

    const request = http.expectOne(CHALLENGE_URL);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ code: '123456' });
    // The challenge token travels here and only here — never in AuthStore.
    expect(request.request.headers.get('Authorization')).toBe('Bearer 9|challenge-token');

    request.flush({ token: '1|full', user: makeUser({ two_factor_enabled: true }), company: makeCompany() });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(TestBed.inject(AuthStore).isAuthenticated()).toBe(true);
    expect(navigate).toHaveBeenCalledWith('/app/overview');
  });

  it('does not call the challenge endpoint while the code is malformed', async () => {
    await reachChallengeStep();

    component.challengeForm.controls.code.setValue('12ab');
    component.onChallengeSubmit();
    fixture.detectChanges();
    await fixture.whenStable();

    // The count is the point: a request here would mean the six-digit rule is
    // decorative and the server is being asked to do the form's job.
    expect(http.match(CHALLENGE_URL)).toHaveLength(0);
    expect(component.challengeErrorFor('code')).toContain('six digits');
  });

  it('keeps the user on the code step when the code is rejected', async () => {
    await reachChallengeStep();

    component.challengeForm.controls.code.setValue('000000');
    component.onChallengeSubmit();
    http
      .expectOne(CHALLENGE_URL)
      .flush(
        { code: 'TWO_FACTOR_CODE_INVALID', message: 'Invalid code.' },
        { status: 422, statusText: 'Unprocessable Content' }
      );
    fixture.detectChanges();
    await fixture.whenStable();

    expect(component.step()).toBe('challenge');
    expect(component.submitting()).toBe(false);
    expect(component.challengeErrorFor('code')).toBeTruthy();
    expect(TestBed.inject(AuthStore).isAuthenticated()).toBe(false);
  });

  it('offers a recovery code and sends it under its own field name', async () => {
    jest.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    await reachChallengeStep();

    component.useRecoveryCode(true);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(component.usingRecoveryCode()).toBe(true);
    component.challengeForm.controls.recovery_code.setValue('a1b2-c3d4');
    component.onChallengeSubmit();

    const request = http.expectOne(CHALLENGE_URL);
    // Exactly one of the two fields, never both: the disabled control must not
    // smuggle an empty `code` into the body.
    expect(request.request.body).toEqual({ recovery_code: 'a1b2-c3d4' });
    request.flush({ token: '1|full', user: makeUser({ two_factor_enabled: true }), company: makeCompany() });
  });

  /**
   * The challenge token lives five minutes and dies on too many wrong codes.
   * Leaving the user on a form whose credential no longer exists is a form that
   * can only fail, so the first factor is asked again — and said so.
   */
  it('returns to the password step, saying why, when the challenge token is gone', async () => {
    // `errorInterceptor` sends an UNAUTHENTICATED anywhere back to /auth/login,
    // which this test bed has no route for. The component's own behaviour is
    // what is under test here, not the interceptor's redirect.
    jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);

    await reachChallengeStep();

    component.challengeForm.controls.code.setValue('123456');
    component.onChallengeSubmit();
    http
      .expectOne(CHALLENGE_URL)
      .flush({ code: 'UNAUTHENTICATED', message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(component.step()).toBe('credentials');
    expect(component.challengeExpired()).toBe(true);
    expect((fixture.nativeElement as HTMLElement).querySelector('[data-testid="two-factor-expired"]')).toBeTruthy();
    // The password box is emptied rather than left holding a live secret.
    expect(component.form.controls.password.value).toBe('');
  });

  it('drops the challenge token when the user goes back', async () => {
    await reachChallengeStep();

    component.cancelChallenge();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(component.step()).toBe('credentials');

    // Nothing left to submit with: the token is gone, so no request is made.
    component.onChallengeSubmit();
    expect(http.match(CHALLENGE_URL)).toHaveLength(0);
  });
});
