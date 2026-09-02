import { computed, inject, Injectable, signal } from '@angular/core';

import { DEFAULT_PER_PAGE, EMPTY_META, PaginationMeta } from '../api/pagination';
import { RequestState } from '../api/request-state';
import { errorCodeOf } from '../errors/error-code';
import { EMPTY_FILTERS, Feedback, FeedbackFilters, hasActiveFilter } from './feedback.models';
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
