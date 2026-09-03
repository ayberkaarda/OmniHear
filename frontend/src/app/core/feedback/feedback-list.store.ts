import { computed, inject, Injectable, signal } from '@angular/core';

import { DEFAULT_PER_PAGE, EMPTY_META, PaginationMeta } from '../api/pagination';
import { RequestState } from '../api/request-state';
import { errorCodeOf } from '../errors/error-code';
import {
  EMPTY_FILTERS,
  Feedback,
  FeedbackCategory,
  FeedbackFilters,
  hasActiveFilter,
  SentimentLabel
} from './feedback.models';
import { FeedbackService } from './feedback.service';

/**
 * The inbox list: filters, page, rows, and the state of the read that produced
 * them.
 *
 * Signal-only state with the network call delegated to `FeedbackService` — the
 * shape `AuthStore` established. Two details are load-bearing:
 *
 * - **Stale-response guard.** Typing in the search box fires overlapping reads
 *   and the server is under no obligation to answer them in order. Each read
 *   carries a token, and a late answer to a superseded request is dropped —
 *   which is what stops the list from snapping back to an earlier filter.
 * - **Rows survive a failed refresh.** `state` becomes `error` while `items` is
 *   left alone, so a filter change that fails does not blank a screen the user
 *   was already reading.
 */
@Injectable({ providedIn: 'root' })
export class FeedbackListStore {
  private readonly service = inject(FeedbackService);

  private readonly filtersSignal = signal<FeedbackFilters>(EMPTY_FILTERS);
  private readonly pageSignal = signal(1);
  private readonly perPageSignal = signal(DEFAULT_PER_PAGE);
  private readonly itemsSignal = signal<readonly Feedback[]>([]);
  private readonly metaSignal = signal<PaginationMeta>(EMPTY_META);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private requestToken = 0;

  readonly filters = this.filtersSignal.asReadonly();
  readonly page = this.pageSignal.asReadonly();
  readonly perPage = this.perPageSignal.asReadonly();
  readonly items = this.itemsSignal.asReadonly();
  readonly meta = this.metaSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();

  readonly hasFilters = computed(() => hasActiveFilter(this.filtersSignal()));
  readonly isEmpty = computed(() => this.stateSignal() === 'ready' && this.itemsSignal().length === 0);
  readonly canPrevious = computed(() => this.pageSignal() > 1);
  readonly canNext = computed(() => this.pageSignal() < this.metaSignal().last_page);

  /** A filter change always returns to page 1: page 7 of the previous result set means nothing. */
  setFilters(patch: Partial<FeedbackFilters>): void {
    this.filtersSignal.update((current) => ({ ...current, ...patch }));
    this.pageSignal.set(1);
    this.load();
  }

  clearFilters(): void {
    this.filtersSignal.set(EMPTY_FILTERS);
    this.pageSignal.set(1);
    this.load();
  }

  setPage(page: number): void {
    const target = Math.max(1, Math.min(page, this.metaSignal().last_page));
    if (target === this.pageSignal()) {
      return;
    }
    this.pageSignal.set(target);
    this.load();
  }

  setPerPage(perPage: number): void {
    this.perPageSignal.set(perPage);
    this.pageSignal.set(1);
    this.load();
  }

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.list(this.filtersSignal(), this.pageSignal(), this.perPageSignal()).subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.itemsSignal.set(response.data);
        this.metaSignal.set(response.meta);
        this.pageSignal.set(response.meta.current_page);
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
   * Applies a `feedback.analyzed` broadcast to the row that is already on
   * screen (`docs/contracts/realtime.md` section 4).
   *
   * Three deliberate choices:
   *
   * - **No re-fetch.** A row that is not on this page is left alone and no
   *   request is made. A burst of analyses is the normal shape of a finished
   *   sync run, and one read per event would be a request storm.
   * - **The row stays where it is**, even when the active filter is
   *   `analysis_status=pending_analysis` and the update no longer matches it.
   *   Rearranging the list under a reader is worse than a row that is briefly
   *   out of step with its filter; the next read settles it.
   * - **Only the four broadcast fields are written.** `confidence`,
   *   `keywords` and `analyzed_at` are not in the payload, so they stay empty
   *   rather than being invented. The detail screen re-reads the full record.
   */
  applyAnalysis(event: {
    readonly feedback_id: number;
    readonly sentiment_label: SentimentLabel;
    readonly sentiment_score: number;
    readonly category: FeedbackCategory;
    readonly model_version: string;
  }): void {
    this.itemsSignal.update((items) => {
      const index = items.findIndex((item) => item.id === event.feedback_id);
      if (index === -1) {
        return items;
      }

      const next = [...items];
      next[index] = {
        ...items[index],
        analysis_status: 'analyzed',
        analysis: {
          sentiment_score: event.sentiment_score,
          sentiment_label: event.sentiment_label,
          category: event.category,
          confidence: null,
          keywords: [],
          model_version: event.model_version,
          analyzed_at: null
        }
      };
      return next;
    });
  }

  /** Called on entering the screen; a store that already holds rows is not re-fetched. */
  loadIfNeeded(): void {
    if (this.stateSignal() === 'idle') {
      this.load();
    }
  }

  reset(): void {
    this.requestToken++;
    this.filtersSignal.set(EMPTY_FILTERS);
    this.pageSignal.set(1);
    this.perPageSignal.set(DEFAULT_PER_PAGE);
    this.itemsSignal.set([]);
    this.metaSignal.set(EMPTY_META);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
  }
}
