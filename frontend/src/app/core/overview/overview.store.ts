import { computed, inject, Injectable, signal } from '@angular/core';

import { RequestState } from '../api/request-state';
import { errorCodeOf } from '../errors/error-code';
import { OverviewKpis } from './overview.models';
import { OverviewService } from './overview.service';

/**
 * The dashboard aggregate.
 *
 * `kpis()` stays `null` until the first successful read so the KPI cards can
 * render their own skeleton (`value = null`) rather than a zero, which would
 * read as a measured result.
 */
@Injectable({ providedIn: 'root' })
export class OverviewStore {
  private readonly service = inject(OverviewService);

  private readonly kpisSignal = signal<OverviewKpis | null>(null);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private requestToken = 0;

  readonly kpis = this.kpisSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();

  readonly loading = computed(() => this.stateSignal() === 'idle' || this.stateSignal() === 'loading');
  readonly trend = computed(() => this.kpisSignal()?.trend ?? []);

  /** Nothing has ever been collected — a different statement from "collected but not analysed". */
  readonly isEmpty = computed(() => this.stateSignal() === 'ready' && (this.kpisSignal()?.total_feedbacks ?? 0) === 0);

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.kpis().subscribe({
      next: (kpis) => {
        if (token !== this.requestToken) {
          return;
        }
        this.kpisSignal.set(kpis);
        this.stateSignal.set('ready');
      },
      error: (error: unknown) => {
        if (token !== this.requestToken) {
          return;
        }
        this.errorCodeSignal.set(errorCodeOf(error));
        this.stateSignal.set('error');
      }
    });
  }

  reset(): void {
    this.requestToken++;
    this.kpisSignal.set(null);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
  }
}
