import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { isApiError } from '../../../core/errors/api-error';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { AuthFormBase } from '../shared/auth-form-base';
import { AuthLayoutComponent } from '../shared/auth-layout.component';

/**
 * The API answers `202` whether or not the address exists, so the screen shows
 * the same confirmation either way — anything else would turn this form into an
 * account-enumeration oracle (contract section 5).
 */
@Component({
    selector: 'app-forgot-password',
    imports: [ReactiveFormsModule, RouterLink, AuthLayoutComponent, InputComponent, ButtonComponent],
    templateUrl: './forgot-password.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class ForgotPasswordComponent extends AuthFormBase {
  private readonly auth = inject(AuthService);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]]
  });

  protected readonly requested = signal(false);

  protected readonly heading = $localize`:Forgot password heading@@auth.forgot.heading:Reset your password`;
  protected readonly lead = $localize`:Forgot password lead@@auth.forgot.lead:We will email you a link to choose a new password.`;
  protected readonly emailLabel = $localize`:Field label@@auth.field.email:Email address`;

  protected onSubmit(): void {
    if (this.submitting()) {
      return;
    }
    if (this.form.invalid) {
      this.markAllTouched();
      return;
    }

    this.submitting.set(true);
    this.serverErrors.set(null);

    this.auth.forgotPassword(this.form.getRawValue()).subscribe({
      next: () => {
        this.submitting.set(false);
        this.requested.set(true);
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.serverErrors.set(isApiError(error) ? error.fieldErrors : null);
        this.onFieldBlur();
      }
    });
  }
}
