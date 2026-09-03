import { computed, inject, Injectable, signal } from '@angular/core';

import { AuthStore } from '../auth/auth.store';

/** Usage share above which the meter switches to its warning styling (spec 7.3). */
const WARNING_THRESHOLD = 0.8;

export type QuotaLevel = 'ok' | 'warning' | 'exceeded';

/**
 * Live view of the tenant's analysis quota.
 *
 * Two sources, in priority order:
 *  1. the `X-Quota-Remaining` header, which rides on every authenticated
 *     `/api/v1` response, so ordinary traffic keeps the meter fresh (contract 1);
 *  2. `company.quota_remaining` from the session, used until the first header
 *     is seen.
 *
 * Both are `null` before a session exists — the UI renders that state rather
 * than inventing a number.
 */
@Injectable({ providedIn: 'root' })
export class QuotaStore {
  private readonly auth = inject(AuthStore);
  private readonly headerRemaining = signal<number | null>(null);
  /**
   * Set only by the `quota.threshold-reached` broadcast, which carries the
   * limit alongside the counter. It wins over the session's `quota_limit`
   * because a plan upgrade raises the server-side limit while the `company`
   * held in `AuthStore` is still the one `/auth/me` returned at sign-in.
   */
  private readonly eventLimit = signal<number | null>(null);

  readonly limit = computed<number | null>(() => this.eventLimit() ?? this.auth.company()?.quota_limit ?? null);

  readonly remaining = computed<number | null>(() => this.headerRemaining() ?? this.auth.company()?.quota_remaining ?? null);

  readonly used = computed<number | null>(() => {
    const limit = this.limit();
    const remaining = this.remaining();
    if (limit === null || remaining === null) {
      return null;
    }
    return Math.max(0, limit - remaining);
  });

  readonly usedRatio = computed<number | null>(() => {
    const limit = this.limit();
    const used = this.used();
    if (limit === null || limit <= 0 || used === null) {
      return null;
    }
    return Math.min(1, used / limit);
  });

  readonly level = computed<QuotaLevel>(() => {
    const remaining = this.remaining();
    if (remaining !== null && remaining <= 0) {
      return 'exceeded';
    }
    const ratio = this.usedRatio();
    if (ratio !== null && ratio >= WARNING_THRESHOLD) {
      return 'warning';
    }
    return 'ok';
  });

  /** Called by `quotaInterceptor` for every response that carries the header. */
  setRemaining(remaining: number): void {
    this.headerRemaining.set(Math.max(0, remaining));
  }

  /** `quota.threshold-reached` — `docs/contracts/realtime.md` section 2. */
  setUsage(limit: number, remaining: number): void {
    this.eventLimit.set(Math.max(0, limit));
    this.headerRemaining.set(Math.max(0, remaining));
  }

  reset(): void {
    this.headerRemaining.set(null);
    this.eventLimit.set(null);
  }
}
