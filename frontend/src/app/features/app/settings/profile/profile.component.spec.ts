import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../../environments/environment';
import { makeCompany, makeUser } from '../../../../core/auth/auth.fixtures';
import { AuthStore } from '../../../../core/auth/auth.store';
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

    buttonWith('Save changes').click();
    http.expectOne(PROFILE).flush({
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
});
