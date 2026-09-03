import { ChangeDetectionStrategy, Component, inject, input, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { PendingInvitation, UserRole } from '../../../core/auth/auth.models';
import { InvitationService } from '../../../core/auth/invitation.service';
import { isApiError } from '../../../core/errors/api-error';
import { ToastService } from '../../../core/toast/toast.service';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { AuthFormBase, passwordsMatchValidator } from '../shared/auth-form-base';
import { AuthLayoutComponent } from '../shared/auth-layout.component';
import { MIN_PASSWORD_LENGTH } from '../shared/form-errors';

type InvitationState = 'loading' | 'ready' | 'invalid';

/**
 * Target of the emailed invitation link: `/auth/accept-invitation?token=`.
 * The token arrives as a component input (`withComponentInputBinding()` in
 * `app.config.ts`), exactly as the reset-password screen takes its own.
 *
 * The page loads before it asks for anything: `GET /invitations/{token}` says
 * which company invited this address and at what role, and a person is
 * entitled to see that before they choose a password for an account inside
 * someone else's tenant.
 *
 * There is one failure state, not three. Expired, already-accepted and unknown
 * tokens all answer `404`, deliberately, so that an outsider cannot probe which
 * tokens ever existed — this screen must not undo that by guessing at which of
 * the three happened and saying so.
 */
@Component({
    selector: 'app-accept-invitation',
    imports: [ReactiveFormsModule, RouterLink, AuthLayoutComponent, InputComponent, ButtonComponent],
    templateUrl: './accept-invitation.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class AcceptInvitationComponent extends AuthFormBase implements OnInit {
  private readonly invitations = inject(InvitationService);
  private readonly router = inject(Router);
  private readonly toasts = inject(ToastService);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly token = input<string | undefined>(undefined);

  protected readonly form = this.fb.group(
    {
      name: ['', [Validators.required, Validators.maxLength(255)]],
      password: ['', [Validators.required, Validators.minLength(MIN_PASSWORD_LENGTH)]],
      password_confirmation: ['', [Validators.required]]
    },
    { validators: passwordsMatchValidator('password', 'password_confirmation') }
  );

  protected readonly state = signal<InvitationState>('loading');
  protected readonly invitation = signal<PendingInvitation | null>(null);

  protected readonly heading = $localize`:Accept invitation heading@@auth.invitation.heading:Join your team`;
  protected readonly nameLabel = $localize`:Field label@@auth.field.name:Your name`;
  protected readonly passwordLabel = $localize`:Field label@@auth.field.password:Password`;
  protected readonly passwordConfirmationLabel = $localize`:Field label@@auth.field.passwordConfirmation:Repeat password`;
  protected readonly passwordHelper = $localize`:Field helper@@auth.register.passwordHelper:At least 12 characters.`;

  ngOnInit(): void {
    const token = this.token();

    // A link without a token cannot be told from a bad one, and there is no
    // request worth making for it.
    if (token === undefined || token === '') {
      this.state.set('invalid');
      return;
    }

    this.invitations.show(token).subscribe({
      next: (response) => {
        this.invitation.set(response.invitation);
        this.state.set('ready');
      },
      error: () => this.state.set('invalid')
    });
  }

  protected onSubmit(): void {
    const token = this.token();
    if (this.submitting() || token === undefined || this.state() !== 'ready') {
      return;
    }
    if (this.form.invalid) {
      this.markAllTouched();
      return;
    }

    this.submitting.set(true);
    this.serverErrors.set(null);

    this.invitations.accept(token, this.form.getRawValue()).subscribe({
      next: () => {
        this.submitting.set(false);
        this.toasts.success(
          $localize`:Invitation accepted toast@@auth.invitation.success:Welcome aboard. Your account is ready.`
        );
        // Straight into the product, with no verification detour: the account
        // was created already verified, because the token proved the address.
        void this.router.navigate(['/app/overview']);
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.serverErrors.set(isApiError(error) ? error.fieldErrors : null);
        this.onFieldBlur();
      }
    });
  }

  protected roleLabel(role: UserRole): string {
    return roleLabel(role);
  }
}

/**
 * The same three labels the team screen uses, under the same message ids, so
 * the two never drift and the translator sees one entry.
 *
 * Copied rather than imported on purpose: the original lives in the team
 * settings component, which is inside the `/app` chunk. Importing it from here
 * would pull that whole component — and its store, its table and its modal —
 * into the `/auth` chunk to obtain three words.
 */
function roleLabel(role: UserRole): string {
  switch (role) {
    case 'owner':
      return $localize`:Team role@@label.role.owner:Owner`;
    case 'admin':
      return $localize`:Team role@@label.role.admin:Administrator`;
    default:
      return $localize`:Team role@@label.role.member:Member`;
  }
}
