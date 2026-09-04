import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { TwoFactorStore } from '../../../../core/auth/two-factor.store';
import { errorMessageForCode } from '../../../../core/errors/error-messages';
import { ProfileStore } from '../../../../core/settings/profile.store';
import { ButtonComponent } from '../../../../shared/ui/button/button.component';
import { InputComponent } from '../../../../shared/ui/form-field/input.component';
import { formatDate } from '../../../../shared/format/format';
import { passwordsMatchValidator } from '../../../auth/shared/auth-form-base';
import {
  controlErrorMessage,
  MIN_PASSWORD_LENGTH,
  serverFieldError,
  TOTP_CODE_PATTERN,
  twoFactorControlMessage
} from '../../../auth/shared/form-errors';

/**
 * `/app/settings/profile` — name, email address, password, second factor.
 *
 * Independent forms on one screen, each with its own submit and its own error
 * surface. They fail for unrelated reasons and a shared pending flag would
 * disable the one the user is not in.
 *
 * The second factor is a third section here rather than a
 * `/app/settings/security` route: spec section 4's page tree lists no such
 * route, and adding one would be a deviation from it. Its state lives in
 * `TwoFactorStore` — see that class for why the secret and the recovery codes
 * are held for exactly as long as they are on screen and no longer.
 *
 * The email field carries a standing warning rather than a surprise: changing
 * the address **un-verifies the account** and sends a new verification mail
 * (`docs/contracts/settings-api.md` section 1). Saying so before the change is
 * the difference between a consequence and an accident.
 *
 * `formTick` is the same device the `/auth` forms use: `ReactiveFormsModule` is
 * observable-based, so reading a signal inside the template's error getter is
 * what makes a blur re-render under zoneless change detection.
 */
@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [ReactiveFormsModule, ButtonComponent, InputComponent],
  templateUrl: './profile.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ProfileComponent implements OnInit {
  private readonly store = inject(ProfileStore);
  private readonly twoFactor = inject(TwoFactorStore);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly user = this.store.user;
  protected readonly loading = this.store.loading;
  protected readonly savingProfile = this.store.savingProfile;
  protected readonly savingPassword = this.store.savingPassword;
  protected readonly emailVerificationRequired = this.store.emailVerificationRequired;
  protected readonly passwordChanged = this.store.passwordChanged;

  protected readonly twoFactorEnabled = this.twoFactor.enabled;
  protected readonly enrolment = this.twoFactor.enrolment;
  protected readonly enrolling = this.twoFactor.enrolling;
  protected readonly recoveryCodes = this.twoFactor.recoveryCodes;
  protected readonly recoveryOrigin = this.twoFactor.recoveryOrigin;
  protected readonly startingEnrolment = this.twoFactor.starting;
  protected readonly confirmingEnrolment = this.twoFactor.confirming;
  protected readonly disablingTwoFactor = this.twoFactor.disabling;
  protected readonly regeneratingCodes = this.twoFactor.regenerating;

  private readonly formTick = signal(0);

  protected readonly detailsForm = this.fb.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    email: ['', [Validators.required, Validators.email]]
  });

  protected readonly passwordForm = this.fb.group(
    {
      current_password: ['', [Validators.required]],
      password: ['', [Validators.required, Validators.minLength(MIN_PASSWORD_LENGTH)]],
      password_confirmation: ['', [Validators.required]]
    },
    { validators: passwordsMatchValidator('password', 'password_confirmation') }
  );

  protected readonly confirmForm = this.fb.group({
    code: ['', [Validators.required, Validators.pattern(TOTP_CODE_PATTERN)]]
  });

  /**
   * Both factors, because removing one is exactly the moment an attacker on a
   * stolen session would act (contract `w10-two-factor.md`).
   */
  protected readonly disableForm = this.fb.group({
    password: ['', [Validators.required]],
    code: ['', [Validators.required, Validators.pattern(TOTP_CODE_PATTERN)]]
  });

  protected readonly regenerateForm = this.fb.group({
    code: ['', [Validators.required, Validators.pattern(TOTP_CODE_PATTERN)]]
  });

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  protected readonly verifiedOn = computed(() => formatDate(this.user()?.email_verified_at));
  protected readonly isVerified = computed(() => this.user()?.email_verified_at != null);

  protected readonly nameLabel = $localize`:Profile form field@@settings.profile.field.name:Full name`;
  protected readonly emailLabel = $localize`:Profile form field@@settings.profile.field.email:Email address`;
  protected readonly emailHelper = $localize`:Warning under the email field@@settings.profile.field.emailHelper:Changing this address signs your account out of its confirmed state and sends a new confirmation email.`;
  protected readonly currentPasswordLabel = $localize`:Password form field@@settings.password.field.current:Current password`;
  protected readonly newPasswordLabel = $localize`:Password form field@@settings.password.field.new:New password`;
  protected readonly confirmPasswordLabel = $localize`:Password form field@@settings.password.field.confirm:Repeat the new password`;
  protected readonly passwordHelper = $localize`:Helper under the new password field@@settings.password.field.newHelper:At least 12 characters. Every other signed-in device is signed out when you change it.`;
  protected readonly saveLabel = $localize`:Save a settings form@@settings.action.save:Save changes`;
  protected readonly changePasswordLabel = $localize`:Submit the password form@@settings.password.submit:Change password`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly codeLabel = $localize`:Field label@@auth.field.twoFactorCode:Six-digit code`;
  protected readonly confirmCodeHelper = $localize`:Helper under the enrolment code field@@settings.twoFactor.confirmHelper:Type the code your authenticator app is showing right now.`;
  protected readonly qrAltText = $localize`:Alt text for the enrolment QR code@@settings.twoFactor.qrAlt:QR code that sets up this account in an authenticator app`;

  ngOnInit(): void {
    this.store.loadIfNeeded();
    this.syncDetailsForm();
  }

  protected reload(): void {
    this.store.load();
  }

  protected onFieldBlur(): void {
    this.formTick.update((value) => value + 1);
  }

  protected detailsError(field: string): string | undefined {
    this.formTick();
    return serverFieldError(this.store.profileErrors(), field) ?? controlErrorMessage(this.detailsForm.get(field));
  }

  protected passwordError(field: string): string | undefined {
    this.formTick();
    return serverFieldError(this.store.passwordErrors(), field) ?? controlErrorMessage(this.passwordForm.get(field));
  }

  protected submitDetails(): void {
    if (this.detailsForm.invalid) {
      this.detailsForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }
    const { name, email } = this.detailsForm.getRawValue();
    this.store.updateProfile({ name: name.trim(), email: email.trim() });
  }

  protected submitPassword(): void {
    if (this.passwordForm.invalid) {
      this.passwordForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }
    this.store.updatePassword(this.passwordForm.getRawValue());
    // Cleared unconditionally: the values are secrets, and leaving them in the
    // DOM after a submit is a longer window than the request needs.
    this.passwordForm.reset({ current_password: '', password: '', password_confirmation: '' });
  }

  /* ---------------------------------------------------------- two-factor */

  protected startEnrolment(): void {
    this.confirmForm.reset({ code: '' });
    this.twoFactor.start();
  }

  protected cancelEnrolment(): void {
    this.confirmForm.reset({ code: '' });
    this.twoFactor.cancelEnrolment();
  }

  protected submitConfirm(): void {
    if (this.confirmForm.invalid) {
      this.confirmForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }
    this.twoFactor.confirm(this.confirmForm.getRawValue().code.trim());
    this.confirmForm.reset({ code: '' });
  }

  protected submitDisable(): void {
    if (this.disableForm.invalid) {
      this.disableForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }
    const { password, code } = this.disableForm.getRawValue();
    this.twoFactor.disable(password, code.trim());
    // Same reason the password form clears itself: these are secrets, and the
    // DOM is a longer-lived place than the request needs.
    this.disableForm.reset({ password: '', code: '' });
  }

  protected submitRegenerate(): void {
    if (this.regenerateForm.invalid) {
      this.regenerateForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }
    this.twoFactor.regenerate(this.regenerateForm.getRawValue().code.trim());
    this.regenerateForm.reset({ code: '' });
  }

  protected dismissRecoveryCodes(): void {
    this.twoFactor.dismissRecoveryCodes();
  }

  protected confirmError(field: string): string | undefined {
    this.formTick();
    return (
      serverFieldError(this.twoFactor.confirmErrors(), field) ??
      twoFactorControlMessage(this.confirmForm.get(field), 'code')
    );
  }

  protected disableError(field: 'password' | 'code'): string | undefined {
    this.formTick();
    const control = this.disableForm.get(field);
    const fromServer = serverFieldError(this.twoFactor.disableErrors(), field);
    if (fromServer !== undefined) {
      return fromServer;
    }
    return field === 'code' ? twoFactorControlMessage(control, 'code') : controlErrorMessage(control);
  }

  protected regenerateError(field: string): string | undefined {
    this.formTick();
    return (
      serverFieldError(this.twoFactor.regenerateErrors(), field) ??
      twoFactorControlMessage(this.regenerateForm.get(field), 'code')
    );
  }

  /** Seeds the form from the session so the fields are never empty on arrival. */
  private syncDetailsForm(): void {
    const user = this.user();
    if (user !== null) {
      this.detailsForm.setValue({ name: user.name, email: user.email });
    }
  }
}
