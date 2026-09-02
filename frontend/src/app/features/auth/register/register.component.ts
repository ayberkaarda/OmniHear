import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { isApiError } from '../../../core/errors/api-error';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { AuthFormBase, passwordsMatchValidator } from '../shared/auth-form-base';
import { AuthLayoutComponent } from '../shared/auth-layout.component';
import { MIN_PASSWORD_LENGTH } from '../shared/form-errors';

/**
 * `POST /auth/register` creates the company and its first `owner` user in one
 * transaction and returns a session, so a successful submit lands the user
 * straight on the verification screen rather than the login form.
 */
@Component({
    selector: 'app-register',
    imports: [ReactiveFormsModule, RouterLink, AuthLayoutComponent, InputComponent, ButtonComponent],
    templateUrl: './register.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class RegisterComponent extends AuthFormBase {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly form = this.fb.group(
    {
      name: ['', [Validators.required, Validators.maxLength(255)]],
      company_name: ['', [Validators.required, Validators.maxLength(255)]],
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(MIN_PASSWORD_LENGTH)]],
      password_confirmation: ['', [Validators.required]]
    },
    { validators: passwordsMatchValidator('password', 'password_confirmation') }
  );

  protected readonly heading = $localize`:Register page heading@@auth.register.heading:Create your OmniHear account`;
  protected readonly lead = $localize`:Register page lead@@auth.register.lead:Your workspace is created together with your account.`;
  protected readonly nameLabel = $localize`:Field label@@auth.field.name:Your name`;
  protected readonly companyLabel = $localize`:Field label@@auth.field.companyName:Company name`;
  protected readonly emailLabel = $localize`:Field label@@auth.field.email:Email address`;
  protected readonly passwordLabel = $localize`:Field label@@auth.field.password:Password`;
  protected readonly passwordConfirmationLabel = $localize`:Field label@@auth.field.passwordConfirmation:Repeat password`;
  protected readonly passwordHelper = $localize`:Field helper@@auth.register.passwordHelper:At least 12 characters.`;
  protected readonly emailHelper = $localize`:Field helper@@auth.register.emailHelper:Use your work email address.`;

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

    this.auth.register(this.form.getRawValue()).subscribe({
      next: () => {
        this.submitting.set(false);
        void this.router.navigate(['/auth/verify-email']);
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.serverErrors.set(isApiError(error) ? error.fieldErrors : null);
        this.onFieldBlur();
      }
    });
  }
}
