import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { isApiError } from '../../../core/errors/api-error';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { AuthFormBase } from '../shared/auth-form-base';
import { AuthLayoutComponent } from '../shared/auth-layout.component';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule, RouterLink, AuthLayoutComponent, InputComponent, ButtonComponent],
  templateUrl: './login.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class LoginComponent extends AuthFormBase {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]]
  });

  protected readonly heading = $localize`:Login page heading@@auth.login.heading:Sign in to OmniHear`;
  protected readonly lead = $localize`:Login page lead@@auth.login.lead:Use the email address you registered with.`;
  protected readonly emailLabel = $localize`:Field label@@auth.field.email:Email address`;
  protected readonly passwordLabel = $localize`:Field label@@auth.field.password:Password`;

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

    const { email, password } = this.form.getRawValue();

    this.auth.login({ email, password }).subscribe({
      next: () => {
        this.submitting.set(false);
        // `redirect` is set by authGuard when it bounces a deep link.
        const redirect = this.route.snapshot.queryParamMap.get('redirect');
        void this.router.navigateByUrl(redirect ?? '/app/overview');
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        // The interceptor has already shown the toast for the error code; the
        // form only needs the per-field detail of a 422.
        this.serverErrors.set(isApiError(error) ? error.fieldErrors : null);
        this.onFieldBlur();
      }
    });
  }
}
