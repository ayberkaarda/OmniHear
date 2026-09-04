import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  ElementRef,
  inject,
  Injector,
  signal
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { isTwoFactorChallenge, TwoFactorChallengeRequest } from '../../../core/auth/auth.models';
import { AuthService } from '../../../core/auth/auth.service';
import { FieldErrors, isApiError } from '../../../core/errors/api-error';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { AuthFormBase } from '../shared/auth-form-base';
import { AuthLayoutComponent } from '../shared/auth-layout.component';
import {
  RECOVERY_CODE_PATTERN,
  serverFieldError,
  TOTP_CODE_PATTERN,
  twoFactorControlMessage
} from '../shared/form-errors';

type LoginStep = 'credentials' | 'challenge';

/**
 * `/auth/login` — one route, two steps.
 *
 * The second factor is a *step*, not a page. `POST /auth/login` may answer
 * `200 {two_factor_required:true}` (contract `w10-two-factor.md`), which is a
 * successful first factor rather than a failure, and the component swaps the
 * form it shows on a signal. There is deliberately no `/auth/two-factor` route:
 * spec section 4's page tree does not have one, and a route would need somewhere
 * to keep the challenge token across a navigation — which is exactly the thing
 * that must not be persisted.
 *
 * The challenge token never reaches `AuthStore`. It lives in this component for
 * the seconds the step is on screen, is passed explicitly to the one call that
 * accepts it, and is dropped on success, on failure and on going back.
 */
@Component({
    selector: 'app-login',
    imports: [ReactiveFormsModule, RouterLink, AuthLayoutComponent, InputComponent, ButtonComponent],
    templateUrl: './login.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class LoginComponent extends AuthFormBase {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);

  protected readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]]
  });

  /**
   * Both controls exist from the start and the inactive one is *disabled*, so
   * `challengeForm.invalid` answers about the field actually on screen and
   * `.value` carries only that field. Toggling validators by hand instead is
   * how a form ends up rejecting a code because of the box nobody can see.
   */
  protected readonly challengeForm = this.fb.group({
    code: ['', [Validators.required, Validators.pattern(TOTP_CODE_PATTERN)]],
    recovery_code: [{ value: '', disabled: true }, [Validators.required, Validators.pattern(RECOVERY_CODE_PATTERN)]]
  });

  protected readonly step = signal<LoginStep>('credentials');
  protected readonly usingRecoveryCode = signal(false);

  /** Not a signal by accident: nothing renders it, and nothing else may read it. */
  private challengeToken: string | null = null;

  private readonly challengeMessage = signal<string | null>(null);
  private readonly challengeFieldErrors = signal<FieldErrors | null>(null);
  protected readonly challengeExpired = signal(false);

  protected readonly activeChallengeField = computed(() => (this.usingRecoveryCode() ? 'recovery_code' : 'code'));

  protected readonly credentialsHeading = $localize`:Login page heading@@auth.login.heading:Sign in to OmniHear`;
  protected readonly credentialsLead = $localize`:Login page lead@@auth.login.lead:Use the email address you registered with.`;
  protected readonly challengeHeading = $localize`:Two-factor step heading@@auth.twoFactor.heading:Confirm it is you`;
  protected readonly challengeLead = $localize`:Two-factor step lead@@auth.twoFactor.lead:Your password was accepted. Enter the six-digit code from your authenticator app to finish signing in.`;
  protected readonly recoveryLead = $localize`:Two-factor recovery step lead@@auth.twoFactor.recoveryLead:Enter one of the recovery codes you saved when you switched on two-step verification. Each code works once.`;

  protected readonly heading = computed(() =>
    this.step() === 'challenge' ? this.challengeHeading : this.credentialsHeading
  );
  protected readonly lead = computed(() => {
    if (this.step() === 'credentials') {
      return this.credentialsLead;
    }
    return this.usingRecoveryCode() ? this.recoveryLead : this.challengeLead;
  });

  protected readonly emailLabel = $localize`:Field label@@auth.field.email:Email address`;
  protected readonly passwordLabel = $localize`:Field label@@auth.field.password:Password`;
  protected readonly codeLabel = $localize`:Field label@@auth.field.twoFactorCode:Six-digit code`;
  protected readonly recoveryCodeLabel = $localize`:Field label@@auth.field.recoveryCode:Recovery code`;
  protected readonly codeHelper = $localize`:Helper under the two-factor code field@@auth.twoFactor.codeHelper:The code changes every 30 seconds.`;

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
    this.challengeExpired.set(false);

    const { email, password } = this.form.getRawValue();

    this.auth.login({ email, password }).subscribe({
      next: (response) => {
        this.submitting.set(false);
        if (isTwoFactorChallenge(response)) {
          this.challengeToken = response.challenge_token;
          this.step.set('challenge');
          this.focusChallengeField();
          return;
        }
        this.completeSignIn();
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

  protected onChallengeSubmit(): void {
    const token = this.challengeToken;
    if (this.submitting() || token === null) {
      return;
    }
    if (this.challengeForm.invalid) {
      this.challengeForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }

    this.submitting.set(true);
    this.challengeMessage.set(null);
    this.challengeFieldErrors.set(null);

    const value = this.challengeForm.value;
    const payload: TwoFactorChallengeRequest = this.usingRecoveryCode()
      ? { recovery_code: (value.recovery_code ?? '').trim() }
      : { code: (value.code ?? '').trim() };

    this.auth.twoFactorChallenge(token, payload).subscribe({
      next: () => {
        this.submitting.set(false);
        this.challengeToken = null;
        this.completeSignIn();
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.onChallengeError(error);
      }
    });
  }

  /**
   * Recovery codes are the way back in when the phone is gone, so the switch has
   * to be on this screen — a user locked out of their authenticator cannot reach
   * a settings page to find it.
   */
  protected useRecoveryCode(enabled: boolean): void {
    this.setChallengeMode(enabled);
    this.focusChallengeField();
  }

  /** Back to the first step; the challenge token is dropped rather than reused. */
  protected cancelChallenge(): void {
    this.challengeToken = null;
    this.step.set('credentials');
    this.setChallengeMode(false);
    this.form.controls.password.reset('');
  }

  private setChallengeMode(recovery: boolean): void {
    this.usingRecoveryCode.set(recovery);
    this.challengeMessage.set(null);
    this.challengeFieldErrors.set(null);

    const codeControl = this.challengeForm.controls.code;
    const recoveryControl = this.challengeForm.controls.recovery_code;
    const [active, inactive] = recovery ? [recoveryControl, codeControl] : [codeControl, recoveryControl];

    inactive.reset('');
    inactive.disable();
    active.reset('');
    active.enable();
    this.onFieldBlur();
  }

  protected challengeErrorFor(field: 'code' | 'recovery_code'): string | undefined {
    this.formTick();
    const fromServer = serverFieldError(this.challengeFieldErrors(), field);
    if (fromServer !== undefined) {
      return fromServer;
    }
    if (field === this.activeChallengeField() && this.challengeMessage() !== null) {
      return this.challengeMessage() ?? undefined;
    }
    return twoFactorControlMessage(this.challengeForm.get(field), field);
  }

  private onChallengeError(error: unknown): void {
    if (!isApiError(error)) {
      this.challengeMessage.set(this.rejectedMessage());
      this.onFieldBlur();
      return;
    }

    this.challengeFieldErrors.set(error.fieldErrors);

    // The challenge token is gone: expired, or spent by too many wrong codes.
    // Staying on a step whose credential no longer exists would let the user
    // type into a form that can only fail, so the first factor is asked again.
    if (error.code === 'UNAUTHENTICATED' || error.status === 401) {
      this.challengeToken = null;
      this.step.set('credentials');
      this.setChallengeMode(false);
      this.form.controls.password.reset('');
      this.challengeExpired.set(true);
      return;
    }

    if (error.fieldErrors === null) {
      this.challengeMessage.set(this.rejectedMessage());
    }
    this.onFieldBlur();
  }

  private rejectedMessage(): string {
    return $localize`:Two-factor rejection message@@auth.twoFactor.error.rejected:That code was not accepted. Try the current code, or use a recovery code.`;
  }

  private completeSignIn(): void {
    // `redirect` is set by authGuard when it bounces a deep link.
    const redirect = this.route.snapshot.queryParamMap.get('redirect');
    void this.router.navigateByUrl(redirect ?? '/app/overview');
  }

  /**
   * Moves focus to the field the user now has to fill.
   *
   * Without it the second step appears with focus still on the submit button of
   * a form that is no longer rendered, which for a keyboard or screen-reader
   * user is indistinguishable from nothing having happened (WCAG 2.4.3).
   */
  private focusChallengeField(): void {
    afterNextRender(
      () => {
        this.host.nativeElement.querySelector<HTMLInputElement>('[data-testid="two-factor-form"] input')?.focus();
      },
      { injector: this.injector }
    );
  }
}
