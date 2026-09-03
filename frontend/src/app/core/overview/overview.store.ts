import { computed, inject, Injectable, signal } from '@angular/core';

import { RequestState } from '../api/request-state';
import { errorCodeOf } from '../errors/error-code';
import { FeedbackCategory, SentimentLabel } from '../feedback/feedback.models';
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

  /**
   * Nudges the KPI aggregate with one `feedback.analyzed` broadcast, instead of
   * re-reading `GET /overview/kpis` per event.
   *
   * Nothing is nudged before the first successful read: without a baseline the
   * counters would start from an invented zero, and a KPI card that says "1
   * analysed" on a tenant with 4 000 is worse than one that is still loading.
   *
   * `average_sentiment` is folded in as a running mean over `analyzed_count`,
   * which is exactly how the server computes it. `trend` is **not** touched:
   * the payload carries no date, so which day's bucket to move is unknowable —
   * the chart stays as last read and corrects on the next load.
   *
   * `quota` is not touched either, on purpose. `X-Quota-Remaining` rides on
   * every response and `quota.threshold-reached` carries the counter as well;
   * incrementing here too would double-count the same analysis.
   */
  applyAnalysis(event: {
    readonly sentiment_label: SentimentLabel;
    readonly sentiment_score: number;
    readonly category: FeedbackCategory;
  }): void {
    this.kpisSignal.update((kpis) => {
      if (kpis === null) {
        return kpis;
      }

      const analyzed = kpis.analyzed_count + 1;

      return {
        ...kpis,
        analyzed_count: analyzed,
        pending_analysis_count: Math.max(0, kpis.pending_analysis_count - 1),
        average_sentiment: (kpis.average_sentiment * kpis.analyzed_count + event.sentiment_score) / analyzed,
        sentiment_breakdown: {
          ...kpis.sentiment_breakdown,
          [event.sentiment_label]: kpis.sentiment_breakdown[event.sentiment_label] + 1
        },
        category_breakdown: {
          ...kpis.category_breakdown,
          [event.category]: kpis.category_breakdown[event.category] + 1
        }
      };
    });
  }

  reset(): void {
    this.requestToken++;
    this.kpisSignal.set(null);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
  }
}
