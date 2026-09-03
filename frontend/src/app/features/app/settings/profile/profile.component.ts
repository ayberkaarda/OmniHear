import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { errorMessageForCode } from '../../../../core/errors/error-messages';
import { ProfileStore } from '../../../../core/settings/profile.store';
import { ButtonComponent } from '../../../../shared/ui/button/button.component';
import { InputComponent } from '../../../../shared/ui/form-field/input.component';
import { formatDate } from '../../../../shared/format/format';
import { passwordsMatchValidator } from '../../../auth/shared/auth-form-base';
import { controlErrorMessage, MIN_PASSWORD_LENGTH, serverFieldError } from '../../../auth/shared/form-errors';

/**
 * `/app/settings/profile` — name, email address, password.
 *
 * Two independent forms on one screen, each with its own submit and its own
 * error surface. They fail for unrelated reasons and a shared pending flag
 * would disable the one the user is not in.
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
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly user = this.store.user;
  protected readonly loading = this.store.loading;
  protected readonly savingProfile = this.store.savingProfile;
  protected readonly savingPassword = this.store.savingPassword;
  protected readonly emailVerificationRequired = this.store.emailVerificationRequired;
  protected readonly passwordChanged = this.store.passwordChanged;

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

  /** Seeds the form from the session so the fields are never empty on arrival. */
  private syncDetailsForm(): void {
    const user = this.user();
    if (user !== null) {
      this.detailsForm.setValue({ name: user.name, email: user.email });
    }
  }
}
