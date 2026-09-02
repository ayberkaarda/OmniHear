import { ChangeDetectionStrategy, Component, inject, input, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { isApiError } from '../../../core/errors/api-error';
import { ToastService } from '../../../core/toast/toast.service';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { AuthFormBase, passwordsMatchValidator } from '../shared/auth-form-base';
import { AuthLayoutComponent } from '../shared/auth-layout.component';
import { MIN_PASSWORD_LENGTH } from '../shared/form-errors';

/**
 * Target of the emailed reset link: `/auth/reset-password?token=&email=`.
 * The two query parameters arrive as component inputs
 * (`withComponentInputBinding()` in `app.config.ts`).
 *
 * An expired or already-used token surfaces as `422 VALIDATION_ERROR`, which is
 * why there is no separate "link expired" state to guess at.
 */
@Component({
  selector: 'app-reset-password',
  standalone: true,
  imports: [ReactiveFormsModule, RouterLink, AuthLayoutComponent, InputComponent, ButtonComponent],
  templateUrl: './reset-password.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ResetPasswordComponent extends AuthFormBase implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toasts = inject(ToastService);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly token = input<string | undefined>(undefined);
  readonly email = input<string | undefined>(undefined);

  protected readonly form = this.fb.group(
    {
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(MIN_PASSWORD_LENGTH)]],
      password_confirmation: ['', [Validators.required]]
    },
    { validators: passwordsMatchValidator('password', 'password_confirmation') }
  );

  protected readonly linkIncomplete = signal(false);

  protected readonly heading = $localize`:Reset password heading@@auth.reset.heading:Choose a new password`;
  protected readonly lead = $localize`:Reset password lead@@auth.reset.lead:Signing in again on your other devices will be required.`;
  protected readonly emailLabel = $localize`:Field label@@auth.field.email:Email address`;
  protected readonly passwordLabel = $localize`:Field label@@auth.field.newPassword:New password`;
  protected readonly passwordConfirmationLabel = $localize`:Field label@@auth.field.passwordConfirmation:Repeat password`;
  protected readonly passwordHelper = $localize`:Field helper@@auth.register.passwordHelper:At least 12 characters.`;

  ngOnInit(): void {
    const email = this.email();
    if (email !== undefined) {
      this.form.controls.email.setValue(email);
    }
    this.linkIncomplete.set(this.token() === undefined);
  }

  protected onSubmit(): void {
    const token = this.token();
    if (this.submitting() || token === undefined) {
      return;
    }
    if (this.form.invalid) {
      this.markAllTouched();
      return;
    }

    this.submitting.set(true);
    this.serverErrors.set(null);

    this.auth.resetPassword({ token, ...this.form.getRawValue() }).subscribe({
      next: () => {
        this.submitting.set(false);
        this.toasts.success($localize`:Reset password success toast@@auth.reset.success:Your password has been changed. Sign in with the new one.`);
        void this.router.navigate(['/auth/login']);
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.serverErrors.set(isApiError(error) ? error.fieldErrors : null);
        this.onFieldBlur();
      }
    });
  }
}
