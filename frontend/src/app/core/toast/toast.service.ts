import { Injectable, signal } from '@angular/core';

export type ToastTone = 'error' | 'success' | 'info';

export interface Toast {
  readonly id: number;
  readonly tone: ToastTone;
  readonly message: string;
}

const DEFAULT_DURATION_MS = 6000;

/**
 * Transient, non-blocking notifications. Quota exhaustion (`402`) never comes
 * through here — the contract requires a blocking modal for that, because a
 * toast that scrolls away would hide the only path forward.
 */
@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly items = signal<readonly Toast[]>([]);
  private nextId = 0;
  private readonly timers = new Map<number, ReturnType<typeof setTimeout>>();

  readonly toasts = this.items.asReadonly();

  show(message: string, tone: ToastTone = 'info', durationMs: number = DEFAULT_DURATION_MS): number {
    const id = ++this.nextId;
    this.items.update((current) => [...current, { id, tone, message }]);

    if (durationMs > 0) {
      this.timers.set(
        id,
        setTimeout(() => this.dismiss(id), durationMs)
      );
    }
    return id;
  }

  error(message: string, durationMs?: number): number {
    return this.show(message, 'error', durationMs);
  }

  success(message: string, durationMs?: number): number {
    return this.show(message, 'success', durationMs);
  }

  dismiss(id: number): void {
    const timer = this.timers.get(id);
    if (timer !== undefined) {
      clearTimeout(timer);
      this.timers.delete(id);
    }
    this.items.update((current) => current.filter((toast) => toast.id !== id));
  }

  clear(): void {
    for (const timer of this.timers.values()) {
      clearTimeout(timer);
    }
    this.timers.clear();
    this.items.set([]);
  }
}
