import { Injectable, signal } from '@angular/core';

/**
 * Owns the single blocking paywall modal.
 *
 * `hasBeenOpened` exists so the host template can `@defer` the modal (keeping
 * it out of the initial bundle) *without* unmounting it again on close — the
 * modal has to survive the close in order to return focus to the element that
 * had it before it opened.
 */
@Injectable({ providedIn: 'root' })
export class PaywallService {
  private readonly openSignal = signal(false);
  private readonly everOpenedSignal = signal(false);

  readonly isOpen = this.openSignal.asReadonly();
  readonly hasBeenOpened = this.everOpenedSignal.asReadonly();

  open(): void {
    this.everOpenedSignal.set(true);
    this.openSignal.set(true);
  }

  close(): void {
    this.openSignal.set(false);
  }
}
