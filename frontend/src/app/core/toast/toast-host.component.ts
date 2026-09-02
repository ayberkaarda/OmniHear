import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';

import { Toast, ToastService } from './toast.service';

/**
 * Global notification region.
 *
 * Two permanently-mounted live regions, not one: assertive for errors, polite
 * for the rest. They are rendered even when empty because a live region that
 * appears at the same moment as its content is frequently not announced.
 *
 * Deliberately free of `shared/ui` imports — this component sits in the initial
 * bundle, so its three tone glyphs are inlined rather than pulling the whole
 * icon registry across the 250 kB budget line.
 */
@Component({
  selector: 'app-toast-host',
  standalone: true,
  imports: [],
  templateUrl: './toast-host.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ToastHostComponent {
  private readonly toastService = inject(ToastService);

  protected readonly errorToasts = computed<readonly Toast[]>(() =>
    this.toastService.toasts().filter((toast) => toast.tone === 'error')
  );

  protected readonly politeToasts = computed<readonly Toast[]>(() =>
    this.toastService.toasts().filter((toast) => toast.tone !== 'error')
  );

  protected toneClasses(toast: Toast): string {
    switch (toast.tone) {
      case 'error':
        return 'border-[var(--sentiment-negative-border)] text-[var(--sentiment-negative-text)]';
      case 'success':
        return 'border-[var(--sentiment-positive-border)] text-[var(--sentiment-positive-text)]';
      default:
        return 'border-[var(--border)] text-[var(--text-secondary)]';
    }
  }

  protected dismiss(id: number): void {
    this.toastService.dismiss(id);
  }
}
