import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../../environments/environment';
import {
  makeCompany,
  makeRecoveryCodes,
  makeTwoFactorEnrolment,
  makeUser
} from '../../../../core/auth/auth.fixtures';
import { AuthStore } from '../../../../core/auth/auth.store';
import { TwoFactorStore } from '../../../../core/auth/two-factor.store';
import { ProfileStore } from '../../../../core/settings/profile.store';
import { ProfileComponent } from './profile.component';

const PROFILE = `${environment.apiBaseUrl}/v1/settings/profile`;
const PASSWORD = `${environment.apiBaseUrl}/v1/settings/password`;

describe('ProfileComponent', () => {
  let fixture: ComponentFixture<ProfileComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [ProfileComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(ProfileStore).reset();
    TestBed.inject(TwoFactorStore).reset();
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(ProfileComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    http.expectOne(PROFILE).flush({ user: makeUser() });
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  function inputs(): HTMLInputElement[] {
    return Array.from(element.querySelectorAll('input'));
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

  it('seeds the form from the session', () => {
    expect(inputs()[0].value).toBe('Ada Lovelace');
    expect(inputs()[1].value).toBe('ada@acme.com');
  });

  /**
   * The consequence is stated before the change, not after it: an email change
   * un-verifies the account, and a user who learns that from a later redirect
   * has been surprised by their own settings screen.
   */
  it('warns about un-verification before the address is changed', () => {
    expect(element.textContent).toContain('Changing this address signs your account out of its confirmed state');
  });

  it('says so again, plainly, once the change has landed', () => {
    const email = inputs()[1];
    email.value = 'grace@navy.mil';
    email.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    // The address moved, so the form now asks for the password (contract
    // section 1); the request does not fire until it is supplied.
    buttonWith('Save changes').click();
    http.expectNone(PROFILE);

    const password = inputs()[2];
    expect(password.type).toBe('password');
    password.value = 'correct-horse-battery';
    password.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Save changes').click();
    const request = http.expectOne(PROFILE);
    expect(request.request.body).toEqual({ name: 'Ada Lovelace', email: 'grace@navy.mil', password: 'correct-horse-battery' });
    request.flush({
      user: makeUser({ email: 'grace@navy.mil', email_verified_at: null }),
      email_verification_required: true
    });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="profile-reverify"]')?.textContent).toContain(
      'needs confirming again'
    );
    expect(element.textContent).toContain('This address is not confirmed yet');
  });

  it('will not submit a password change the client already knows is wrong', () => {
    const [, , current, next, confirm] = inputs();
    current.value = 'current-password';
    current.dispatchEvent(new Event('input'));
    next.value = 'a-long-enough-password';
    next.dispatchEvent(new Event('input'));
    confirm.value = 'something-else-entirely';
    confirm.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Change password').click();
    http.expectNone(PASSWORD);
    fixture.detectChanges();

    expect(element.textContent).toContain('The two passwords do not match');
  });

  it('reports the other devices being signed out after a password change', () => {
    const [, , current, next, confirm] = inputs();
    for (const [input, value] of [
      [current, 'current-password'],
      [next, 'a-long-enough-password'],
      [confirm, 'a-long-enough-password']
    ] as const) {
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
    fixture.detectChanges();

    buttonWith('Change password').click();
    http.expectOne(PASSWORD).flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="password-changed"]')?.textContent).toContain(
      'every other signed-in device has been signed out'
    );
    // The secrets do not linger in the DOM after the submit.
    expect(inputs()[2].value).toBe('');
    expect(inputs()[3].value).toBe('');
  });

  /* ------------------------------------------- the security section (W10) */

  const TWO_FACTOR = `${environment.apiBaseUrl}/v1/auth/two-factor`;

  function setUser2fa(enabled: boolean): void {
    TestBed.inject(AuthStore).setUser(makeUser({ two_factor_enabled: enabled }));
    fixture.detectChanges();
  }

  function startEnrolment(): void {
    // Enrolment re-proves the password (contract w10-two-factor.md), so the
    // off-state carries a password field the button submits.
    const form = element.querySelector('[data-testid="two-factor-enrol-start-form"]');
    expect(form).toBeTruthy();
    const password = form!.querySelector('input') as HTMLInputElement;
    expect(password.type).toBe('password');
    password.value = 'correct-horse-battery';
    password.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Set up two-step verification').click();
    const request = http.expectOne(TWO_FACTOR);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ password: 'correct-horse-battery' });
    request.flush(makeTwoFactorEnrolment(), { status: 201, statusText: 'Created' });
    fixture.detectChanges();
  }

  it('is a section on this page, not a route of its own', () => {
    // Spec section 4's page tree has no /app/settings/security; the second
    // factor lives beside the password it supplements.
    expect(element.querySelector('[data-testid="two-factor-section"]')).toBeTruthy();
    expect(element.querySelector('[data-testid="two-factor-off"]')).toBeTruthy();
    expect(element.querySelector('[data-testid="two-factor-on"]')).toBeNull();
  });

  /**
   * The QR is a base64 SVG data URI rendered by the server. It goes straight
   * into `<img src>`: no `bypassSecurityTrustUrl`, and no QR library in the
   * browser (Trap 2 — the initial bundle must not grow for this).
   */
  it('renders the enrolment QR from the data URI exactly as the API sent it', () => {
    startEnrolment();

    const image = element.querySelector<HTMLImageElement>('[data-testid="two-factor-qr"]');
    expect(image).toBeTruthy();
    // getAttribute, not .src: a sanitizer that rejected the URI would leave
    // "unsafe:" in the attribute while .src resolved to something plausible.
    expect(image?.getAttribute('src')).toBe(makeTwoFactorEnrolment().qr_svg_data_uri);
    expect(image?.getAttribute('alt')).toBeTruthy();
    expect(element.querySelector('[data-testid="two-factor-secret"]')?.textContent?.trim()).toBe(
      makeTwoFactorEnrolment().secret
    );
  });

  it('confirms the code and shows the recovery codes once', () => {
    startEnrolment();

    const code = element.querySelector<HTMLInputElement>('[data-testid="two-factor-confirm-form"] input');
    expect(code).toBeTruthy();
    expect(element.querySelector(`label[for="${code?.id}"]`)).toBeTruthy();

    code!.value = '123456';
    code!.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Confirm and switch on').click();
    const request = http.expectOne(`${TWO_FACTOR}/confirm`);
    expect(request.request.body).toEqual({ code: '123456' });
    request.flush(makeRecoveryCodes());
    fixture.detectChanges();

    const codes = element.querySelector('[data-testid="recovery-codes"]');
    expect(codes).toBeTruthy();
    expect(codes?.querySelectorAll('li')).toHaveLength(8);
    expect(element.querySelector('[data-testid="two-factor-on"]')).toBeTruthy();
    expect(element.querySelector('[data-testid="two-factor-qr"]')).toBeNull();
  });

  it('does not call the API when the confirmation code is not six digits', () => {
    startEnrolment();

    const code = element.querySelector<HTMLInputElement>('[data-testid="two-factor-confirm-form"] input');
    code!.value = '12345';
    code!.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Confirm and switch on').click();
    fixture.detectChanges();

    expect(http.match(`${TWO_FACTOR}/confirm`)).toHaveLength(0);
    expect(element.textContent).toContain('Enter the six digits');
  });

  it('asks for both factors before it will switch two-step verification off', () => {
    setUser2fa(true);

    const form = element.querySelector('[data-testid="two-factor-disable-form"]');
    expect(form).toBeTruthy();
    const fields = Array.from(form!.querySelectorAll('input'));
    expect(fields).toHaveLength(2);
    expect(fields[0].type).toBe('password');

    fields[0].value = 'correct-horse-battery';
    fields[0].dispatchEvent(new Event('input'));
    fields[1].value = '123456';
    fields[1].dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Turn it off').click();

    const request = http.expectOne(TWO_FACTOR);
    expect(request.request.method).toBe('DELETE');
    expect(request.request.body).toEqual({ password: 'correct-horse-battery', code: '123456' });
    request.flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="two-factor-off"]')).toBeTruthy();
  });

  it('regenerates the recovery codes against a current code', () => {
    setUser2fa(true);

    const code = element.querySelector<HTMLInputElement>('[data-testid="two-factor-regenerate-form"] input');
    code!.value = '654321';
    code!.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Generate new codes').click();
    const request = http.expectOne(`${TWO_FACTOR}/recovery-codes`);
    expect(request.request.body).toEqual({ code: '654321' });
    request.flush(makeRecoveryCodes({ recovery_codes: ['z9y8-x7w6'] }));
    fixture.detectChanges();

    const codes = element.querySelector('[data-testid="recovery-codes"]');
    expect(codes?.textContent).toContain('z9y8-x7w6');
    expect(codes?.textContent).toContain('Your new recovery codes');
  });
});
