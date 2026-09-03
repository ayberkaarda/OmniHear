import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { ProfileStore } from './profile.store';

const PROFILE = `${environment.apiBaseUrl}/v1/settings/profile`;
const PASSWORD = `${environment.apiBaseUrl}/v1/settings/password`;

describe('ProfileStore', () => {
  let store: ProfileStore;
  let auth: AuthStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(ProfileStore);
    auth = TestBed.inject(AuthStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
    auth.setSession('1|abc', makeUser(), makeCompany());
  });

  afterEach(() => http.verify());

  it('writes the read profile back into the session', () => {
    store.load();
    http.expectOne(PROFILE).flush({ user: makeUser({ name: 'Grace Hopper' }) });

    expect(store.state()).toBe('ready');
    expect(auth.user()?.name).toBe('Grace Hopper');
  });

  /**
   * The contract's whole reason for `email_verification_required`: the account
   * has just been un-verified, and the screen has to say so at the moment it
   * happens rather than let the user discover it on the next `403`.
   */
  it('surfaces the un-verification an email change causes', () => {
    store.updateProfile({ email: 'grace@navy.mil' });

    const request = http.expectOne(PROFILE);
    expect(request.request.method).toBe('PATCH');
    request.flush({ user: makeUser({ email: 'grace@navy.mil', email_verified_at: null }), email_verification_required: true });

    expect(store.emailVerificationRequired()).toBe(true);
    expect(auth.isEmailVerified()).toBe(false);
  });

  it('does not claim an un-verification that did not happen', () => {
    store.updateProfile({ name: 'Grace' });
    http.expectOne(PROFILE).flush({ user: makeUser({ name: 'Grace' }) });

    expect(store.emailVerificationRequired()).toBe(false);
  });

  it('keeps the 422 field map for the form and stops saving', () => {
    store.updateProfile({ email: 'taken@acme.com' });
    http
      .expectOne(PROFILE)
      .flush(
        { code: 'VALIDATION_ERROR', message: 'invalid', errors: { email: ['That address is already in use.'] } },
        { status: 422, statusText: 'Unprocessable Content' }
      );

    expect(store.savingProfile()).toBe(false);
    expect(store.profileErrors()).toEqual({ email: ['That address is already in use.'] });
  });

  it('reports a changed password and puts a wrong current password on its own field', () => {
    store.updatePassword({
      current_password: 'wrong-password',
      password: 'a-long-enough-password',
      password_confirmation: 'a-long-enough-password'
    });
    http
      .expectOne(PASSWORD)
      .flush(
        { code: 'VALIDATION_ERROR', message: 'invalid', errors: { current_password: ['That is not your password.'] } },
        { status: 422, statusText: 'Unprocessable Content' }
      );

    expect(store.passwordChanged()).toBe(false);
    expect(store.passwordErrors()).toEqual({ current_password: ['That is not your password.'] });

    store.updatePassword({
      current_password: 'right-password',
      password: 'a-long-enough-password',
      password_confirmation: 'a-long-enough-password'
    });
    http.expectOne(PASSWORD).flush(null, { status: 204, statusText: 'No Content' });

    expect(store.passwordChanged()).toBe(true);
    expect(store.passwordErrors()).toBeNull();
  });

  it('keeps the two forms independent', () => {
    store.updateProfile({ name: 'Grace' });
    expect(store.savingProfile()).toBe(true);
    // The password form is untouched while the details form is in flight.
    expect(store.savingPassword()).toBe(false);
    http.expectOne(PROFILE).flush({ user: makeUser() });
  });
});
