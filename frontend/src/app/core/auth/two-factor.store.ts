import { computed, inject, Injectable, signal } from '@angular/core';

import { FieldErrors } from '../errors/api-error';
import { fieldErrorsOf } from '../errors/error-code';
import { ToastService } from '../toast/toast.service';
import { TwoFactorEnrolmentResponse } from './auth.models';
import { AuthService } from './auth.service';
import { AuthStore } from './auth.store';

/** Why a set of recovery codes is on screen — the copy differs, the list does not. */
export type RecoveryCodesOrigin = 'enrolment' | 'regeneration';

/**
 * The security section of `/app/settings/profile`.
 *
 * Separate from `ProfileStore` for the same reason that store keeps its two
 * forms apart: these four calls fail for unrelated reasons, and one shared
 * `saving` flag would disable the form the user is not in.
 *
 * Two things are held here and nowhere else, both for exactly as long as the
 * screen shows them:
 *
 *  - the **enrolment** response, whose `secret` and QR the API serves once and
 *    never again. It is never written to storage — a secret that survives a
 *    reload is a secret sitting in `localStorage` (invariant I5).
 *  - the **recovery codes**, returned once at confirmation and once at each
 *    regeneration. Leaving the screen drops them, which is why the UI says to
 *    save them before it lets them go.
 *
 * "Enabled" is read from `AuthStore` rather than tracked here: the server's
 * `two_factor_enabled` means *confirmed* (contract `w10-two-factor.md`), and a
 * second copy of that fact is a second thing that can be wrong.
 */
@Injectable({ providedIn: 'root' })
export class TwoFactorStore {
  private readonly auth = inject(AuthService);
  private readonly authStore = inject(AuthStore);
  private readonly toasts = inject(ToastService);

  private readonly enrolmentSignal = signal<TwoFactorEnrolmentResponse | null>(null);
  private readonly recoveryCodesSignal = signal<readonly string[] | null>(null);
  private readonly recoveryOriginSignal = signal<RecoveryCodesOrigin | null>(null);

  private readonly startingSignal = signal(false);
  private readonly confirmingSignal = signal(false);
  private readonly disablingSignal = signal(false);
  private readonly regeneratingSignal = signal(false);

  private readonly startErrorsSignal = signal<FieldErrors | null>(null);
  private readonly confirmErrorsSignal = signal<FieldErrors | null>(null);
  private readonly disableErrorsSignal = signal<FieldErrors | null>(null);
  private readonly regenerateErrorsSignal = signal<FieldErrors | null>(null);

  readonly enrolment = this.enrolmentSignal.asReadonly();
  readonly recoveryCodes = this.recoveryCodesSignal.asReadonly();
  readonly recoveryOrigin = this.recoveryOriginSignal.asReadonly();
  readonly starting = this.startingSignal.asReadonly();
  readonly confirming = this.confirmingSignal.asReadonly();
  readonly disabling = this.disablingSignal.asReadonly();
  readonly regenerating = this.regeneratingSignal.asReadonly();
  readonly startErrors = this.startErrorsSignal.asReadonly();
  readonly confirmErrors = this.confirmErrorsSignal.asReadonly();
  readonly disableErrors = this.disableErrorsSignal.asReadonly();
  readonly regenerateErrors = this.regenerateErrorsSignal.asReadonly();

  /** Confirmed, not merely started — the only meaning the flag has. */
  readonly enabled = computed(() => this.authStore.user()?.two_factor_enabled === true);

  /** Enrolment is under way: a secret exists and has not been confirmed yet. */
  readonly enrolling = computed(() => this.enrolmentSignal() !== null);

  /**
   * Asks the API for a secret and a QR, re-proving the password first.
   *
   * The password re-proves the session because arming a factor is as durable a
   * takeover, from a stolen session, as removing one (contract
   * `w10-two-factor.md`). A wrong one comes back as a `422` on the `password`
   * field, surfaced through `startErrors` so the form can name it.
   *
   * Calling it again before confirmation replaces the unconfirmed secret
   * server-side, so the screen simply shows whatever the newest call returned.
   */
  start(password: string): void {
    if (this.startingSignal() || this.enabled()) {
      return;
    }
    this.startingSignal.set(true);
    this.startErrorsSignal.set(null);
    this.confirmErrorsSignal.set(null);
    this.recoveryCodesSignal.set(null);
    this.recoveryOriginSignal.set(null);

    this.auth.startTwoFactorEnrolment(password).subscribe({
      next: (enrolment) => {
        this.startingSignal.set(false);
        this.enrolmentSignal.set(enrolment);
      },
      error: (error: unknown) => {
        // A wrong password is a field-level failure the form must name; the
        // section otherwise stays in its previous state, which is the honest
        // outcome of a failed start.
        this.startingSignal.set(false);
        this.startErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  confirm(code: string): void {
    if (this.confirmingSignal() || !this.enrolling()) {
      return;
    }
    this.confirmingSignal.set(true);
    this.confirmErrorsSignal.set(null);

    this.auth.confirmTwoFactor({ code }).subscribe({
      next: (response) => {
        this.confirmingSignal.set(false);
        // The secret is dropped the moment it is no longer needed; the codes
        // take its place on screen because this is their only appearance.
        this.enrolmentSignal.set(null);
        this.recoveryCodesSignal.set(response.recovery_codes);
        this.recoveryOriginSignal.set('enrolment');
        this.toasts.success(
          $localize`:Toast after two-factor was switched on@@settings.twoFactor.enabledToast:Two-step verification is on. Save your recovery codes now — they are shown once.`
        );
      },
      error: (error: unknown) => {
        this.confirmingSignal.set(false);
        this.confirmErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  disable(password: string, code: string): void {
    if (this.disablingSignal() || !this.enabled()) {
      return;
    }
    this.disablingSignal.set(true);
    this.disableErrorsSignal.set(null);

    this.auth.disableTwoFactor({ password, code }).subscribe({
      next: () => {
        this.disablingSignal.set(false);
        this.enrolmentSignal.set(null);
        this.recoveryCodesSignal.set(null);
        this.recoveryOriginSignal.set(null);
        this.toasts.success(
          $localize`:Toast after two-factor was switched off@@settings.twoFactor.disabledToast:Two-step verification is off. Your account is now protected by its password alone.`
        );
      },
      error: (error: unknown) => {
        this.disablingSignal.set(false);
        this.disableErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  regenerate(code: string): void {
    if (this.regeneratingSignal() || !this.enabled()) {
      return;
    }
    this.regeneratingSignal.set(true);
    this.regenerateErrorsSignal.set(null);

    this.auth.regenerateRecoveryCodes({ code }).subscribe({
      next: (response) => {
        this.regeneratingSignal.set(false);
        this.recoveryCodesSignal.set(response.recovery_codes);
        this.recoveryOriginSignal.set('regeneration');
        this.toasts.success(
          $localize`:Toast after recovery codes were replaced@@settings.twoFactor.regeneratedToast:New recovery codes are ready. The old ones no longer work.`
        );
      },
      error: (error: unknown) => {
        this.regeneratingSignal.set(false);
        this.regenerateErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  /** Abandons an unconfirmed enrolment. The server keeps the unusable secret. */
  cancelEnrolment(): void {
    this.enrolmentSignal.set(null);
    this.confirmErrorsSignal.set(null);
  }

  /** The user says they have saved the codes; nothing can show them again. */
  dismissRecoveryCodes(): void {
    this.recoveryCodesSignal.set(null);
    this.recoveryOriginSignal.set(null);
  }

  reset(): void {
    this.enrolmentSignal.set(null);
    this.recoveryCodesSignal.set(null);
    this.recoveryOriginSignal.set(null);
    this.startingSignal.set(false);
    this.confirmingSignal.set(false);
    this.disablingSignal.set(false);
    this.regeneratingSignal.set(false);
    this.startErrorsSignal.set(null);
    this.confirmErrorsSignal.set(null);
    this.disableErrorsSignal.set(null);
    this.regenerateErrorsSignal.set(null);
  }
}
