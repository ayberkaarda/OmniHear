import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { AuthStore } from '../../../core/auth/auth.store';
import { ToastService } from '../../../core/toast/toast.service';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { ButtonStyleDirective } from '../../../shared/ui/button/button-style.directive';
import { AuthLayoutComponent } from '../shared/auth-layout.component';

type VerifyState = 'awaiting' | 'verifying' | 'verified' | 'failed';

/**
 * Two jobs on one route, because the emailed link and the "check your inbox"
 * screen are the same address (contract section 5):
 *
 *  - with `?id&hash&expires&signature`, the four values are forwarded verbatim
 *    to `POST /auth/email/verify`;
 *  - without them, the screen explains that a link was sent and offers a resend
 *    to a signed-in user.
 *
 * This route is intentionally NOT behind `guestGuard`: `errorInterceptor` sends
 * a signed-in but unverified user here on `403 EMAIL_NOT_VERIFIED`, and a guest
 * guard would bounce them straight back into a redirect loop.
 */
@Component({
    selector: 'app-verify-email',
    imports: [RouterLink, AuthLayoutComponent, ButtonComponent, ButtonStyleDirective],
    templateUrl: './verify-email.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class VerifyEmailComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly authStore = inject(AuthStore);
  private readonly toasts = inject(ToastService);

  readonly id = input<string | undefined>(undefined);
  readonly hash = input<string | undefined>(undefined);
  readonly expires = input<string | undefined>(undefined);
  readonly signature = input<string | undefined>(undefined);

  protected readonly state = signal<VerifyState>('awaiting');
  protected readonly resending = signal(false);

  protected readonly canResend = computed(() => this.authStore.isAuthenticated());
  protected readonly email = computed(() => this.authStore.user()?.email ?? null);

  protected readonly heading = $localize`:Verify email heading@@auth.verify.heading:Confirm your email address`;

  ngOnInit(): void {
    const id = Number.parseInt(this.id() ?? '', 10);
    const hash = this.hash();
    const expires = Number.parseInt(this.expires() ?? '', 10);
    const signature = this.signature();

    if (!Number.isFinite(id) || hash === undefined || !Number.isFinite(expires) || signature === undefined) {
      this.state.set('awaiting');
      return;
    }

    this.state.set('verifying');
    this.auth.verifyEmail({ id, hash, expires, signature }).subscribe({
      next: () => this.state.set('verified'),
      error: () => this.state.set('failed')
    });
  }

  protected onResend(): void {
    if (this.resending()) {
      return;
    }
    this.resending.set(true);
    this.auth.resendVerificationEmail().subscribe({
      next: () => {
        this.resending.set(false);
        this.toasts.success(
          $localize`:Verification email resent toast@@auth.verify.resent:A new confirmation email is on its way.`
        );
      },
      error: () => this.resending.set(false)
    });
  }
}
