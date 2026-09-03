import { computed, inject, Injectable, signal } from '@angular/core';

import { RequestState } from '../api/request-state';
import { AuthStore } from '../auth/auth.store';
import { FieldErrors } from '../errors/api-error';
import { errorCodeOf, fieldErrorsOf } from '../errors/error-code';
import { ToastService } from '../toast/toast.service';
import { PasswordUpdateBody, ProfileUpdateBody } from './settings.models';
import { SettingsService } from './settings.service';

/**
 * `/app/settings/profile` — name, email address and password.
 *
 * The two forms carry separate pending and error state. They fail
 * independently and for unrelated reasons (a taken email versus a wrong current
 * password), and one shared `saving` flag would disable the form the user is
 * not in.
 *
 * `emailVerificationRequired` exists because changing the address un-verifies
 * the account (contract section 1). The screen has to say so at the moment it
 * happens; discovering it on the next `403 EMAIL_NOT_VERIFIED` is how a user
 * ends up on a redirect they cannot explain.
 */
@Injectable({ providedIn: 'root' })
export class ProfileStore {
  private readonly service = inject(SettingsService);
  private readonly auth = inject(AuthStore);
  private readonly toasts = inject(ToastService);

  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private readonly savingProfileSignal = signal(false);
  private readonly profileErrorsSignal = signal<FieldErrors | null>(null);
  private readonly verificationRequiredSignal = signal(false);

  private readonly savingPasswordSignal = signal(false);
  private readonly passwordErrorsSignal = signal<FieldErrors | null>(null);
  private readonly passwordChangedSignal = signal(false);

  private requestToken = 0;

  readonly user = this.auth.user;
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();
  readonly savingProfile = this.savingProfileSignal.asReadonly();
  readonly profileErrors = this.profileErrorsSignal.asReadonly();
  readonly emailVerificationRequired = this.verificationRequiredSignal.asReadonly();
  readonly savingPassword = this.savingPasswordSignal.asReadonly();
  readonly passwordErrors = this.passwordErrorsSignal.asReadonly();
  readonly passwordChanged = this.passwordChangedSignal.asReadonly();

  readonly loading = computed(() => this.stateSignal() === 'idle' || this.stateSignal() === 'loading');

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.profile().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.auth.setUser(response.user);
        this.stateSignal.set('ready');
      },
      error: (error: unknown) => {
        if (token !== this.requestToken) {
          return;
        }
        this.errorCodeSignal.set(errorCodeOf(error));
        this.stateSignal.set('error');
      }
    });
  }

  loadIfNeeded(): void {
    if (this.stateSignal() === 'idle') {
      this.load();
    }
  }

  updateProfile(body: ProfileUpdateBody): void {
    if (this.savingProfileSignal()) {
      return;
    }
    this.savingProfileSignal.set(true);
    this.profileErrorsSignal.set(null);
    this.verificationRequiredSignal.set(false);

    this.service.updateProfile(body).subscribe({
      next: (response) => {
        this.savingProfileSignal.set(false);
        // Writing the returned user back into `AuthStore` is what keeps the
        // shell's header, the verification banner and `isEmailVerified()` in
        // step with the change that just happened.
        this.auth.setUser(response.user);
        this.verificationRequiredSignal.set(response.email_verification_required === true);
        this.toasts.success(
          $localize`:Toast after saving the profile@@settings.profile.saved:Your profile has been saved.`
        );
      },
      error: (error: unknown) => {
        this.savingProfileSignal.set(false);
        this.profileErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  updatePassword(body: PasswordUpdateBody): void {
    if (this.savingPasswordSignal()) {
      return;
    }
    this.savingPasswordSignal.set(true);
    this.passwordErrorsSignal.set(null);
    this.passwordChangedSignal.set(false);

    this.service.updatePassword(body).subscribe({
      next: () => {
        this.savingPasswordSignal.set(false);
        this.passwordChangedSignal.set(true);
        this.toasts.success(
          $localize`:Toast after changing the password@@settings.password.changed:Your password has been changed. Every other signed-in device has been signed out.`
        );
      },
      error: (error: unknown) => {
        this.savingPasswordSignal.set(false);
        this.passwordErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  reset(): void {
    this.requestToken++;
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
    this.savingProfileSignal.set(false);
    this.profileErrorsSignal.set(null);
    this.verificationRequiredSignal.set(false);
    this.savingPasswordSignal.set(false);
    this.passwordErrorsSignal.set(null);
    this.passwordChangedSignal.set(false);
  }
}
