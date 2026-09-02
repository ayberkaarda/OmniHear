import { ChangeDetectionStrategy, Component, computed, inject, OnDestroy, OnInit } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { debounceTime, distinctUntilChanged, Subscription } from 'rxjs';

import { DEFAULT_PER_PAGE } from '../../../core/api/pagination';
import { errorMessageForCode } from '../../../core/errors/error-messages';
import {
  ANALYSIS_STATUSES,
  AnalysisStatus,
  Feedback,
  FEEDBACK_CATEGORIES,
  FeedbackCategory,
  FeedbackFilters,
  SENTIMENT_LABELS,
  SentimentLabel
} from '../../../core/feedback/feedback.models';
import { FeedbackListStore } from '../../../core/feedback/feedback-list.store';
import { PLATFORMS, Platform } from '../../../core/integrations/integration.models';
import { IntegrationsStore } from '../../../core/integrations/integrations.store';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { DataTableComponent } from '../../../shared/ui/data-table/data-table.component';
import { ColumnDef, DataTableState, EmptyStateConfig } from '../../../shared/ui/data-table/data-table.types';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { SelectComponent, SelectOption } from '../../../shared/ui/form-field/select.component';
import { EM_DASH, formatCount, formatDateTime, formatScore, truncate } from '../../../shared/format/format';
import {
  analysisStatusLabel,
  categoryLabel,
  platformLabel,
  sentimentLabel
} from '../../../shared/labels/domain-labels';

/** Typing must not fire a request per keystroke; every other control is a discrete choice. */
const SEARCH_DEBOUNCE_MS = 300;

const PER_PAGE_CHOICES = [25, 50, 100] as const;

const BODY_PREVIEW_LENGTH = 120;

/**
 * `/app/inbox` — the feedback list of `GET /api/v1/feedbacks`.
 *
 * **Pagination, not virtual scrolling.** Spec section 4 asks for virtual
 * scroll; the measurement said otherwise and is recorded in the phase report.
 * `@angular/cdk`'s viewport does work under `provideZonelessChangeDetection`
 * (probed: the rendered range moved from 0–10 to 495–510 with no manual
 * `detectChanges`), but adding it moved the **initial** bundle from 328.20 kB
 * to 347.00 kB raw — the entire remaining headroom, spent before a single
 * screen was written, because the CDK's root-provided injectables land in the
 * initial chunk however lazily the viewport itself is imported. And the
 * contract caps `per_page` at 100, so the DOM never holds more rows than that;
 * virtualization would have had nothing to remove.
 *
 * The filter form is a reactive form because `shared/ui`'s field components are
 * `ControlValueAccessor`s — they have no `value` input to bind a signal to —
 * and `ReactiveFormsModule` is already in the lazy chunk the `/auth` forms
 * share, so it costs nothing here.
 */
@Component({
  selector: 'app-inbox',
  standalone: true,
  imports: [ReactiveFormsModule, DataTableComponent, ButtonComponent, InputComponent, SelectComponent],
  templateUrl: './inbox.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class InboxComponent implements OnInit, OnDestroy {
  private readonly store = inject(FeedbackListStore);
  private readonly integrations = inject(IntegrationsStore);
  private readonly router = inject(Router);
  private readonly fb = inject(NonNullableFormBuilder);

  private readonly subscriptions = new Subscription();
  /** Guards the form -> store direction while the store is writing into the form. */
  private syncingFromStore = false;

  protected readonly items = this.store.items;
  /** `data-table` takes a mutable array; the store deliberately hands out a readonly one. */
  protected readonly rows = computed(() => [...this.store.items()]);
  protected readonly meta = this.store.meta;
  protected readonly page = this.store.page;
  protected readonly perPage = this.store.perPage;
  protected readonly canPrevious = this.store.canPrevious;
  protected readonly canNext = this.store.canNext;
  protected readonly hasFilters = this.store.hasFilters;

  /** '' is the "any" option, and is translated to `null` before it reaches the store. */
  protected readonly form = this.fb.group({
    q: '',
    sentiment: '',
    category: '',
    platform: '',
    analysis_status: '',
    integration_id: '',
    from: '',
    to: ''
  });

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  /**
   * A refresh that already has rows on screen stays in `ready`: swapping the
   * table for skeletons on every debounced keystroke makes the list flicker
   * and loses the user's scroll position.
   */
  protected readonly tableState = computed<DataTableState>(() => {
    const state = this.store.state();
    if (state === 'error') {
      return 'error';
    }
    if (state === 'idle' || (state === 'loading' && this.items().length === 0)) {
      return 'loading';
    }
    return this.items().length === 0 ? 'empty' : 'ready';
  });

  protected readonly refreshing = computed(() => this.store.state() === 'loading' && this.items().length > 0);

  protected readonly emptyState = computed<EmptyStateConfig>(() =>
    this.hasFilters()
      ? {
          title: $localize`:Inbox empty state with filters applied@@inbox.empty.filtered.title:No comment matches these filters`,
          description: $localize`:Inbox empty state with filters applied@@inbox.empty.filtered.description:Widen the date range or clear a filter to see more.`,
          actionLabel: $localize`:Clear every inbox filter@@inbox.filters.clear:Clear filters`
        }
      : {
          title: $localize`:Inbox empty state@@inbox.empty.title:Your inbox is empty`,
          description: $localize`:Inbox empty state@@inbox.empty.description:Connect a channel on the Integrations screen and collected comments land here.`
        }
  );

  protected readonly columns = computed<ColumnDef<Feedback>[]>(() => [
    {
      key: 'published_at',
      header: $localize`:Inbox table column@@inbox.column.published:Published`,
      width: 160,
      cell: (row) => formatDateTime(row.published_at)
    },
    {
      key: 'platform',
      header: $localize`:Inbox table column@@inbox.column.platform:Source`,
      width: 120,
      cell: (row) => (row.platform === null ? EM_DASH : platformLabel(row.platform))
    },
    {
      key: 'author',
      header: $localize`:Inbox table column@@inbox.column.author:Author`,
      width: 150,
      cell: (row) => row.author ?? EM_DASH
    },
    {
      key: 'body',
      header: $localize`:Inbox table column@@inbox.column.comment:Comment`,
      min: 240,
      cell: (row) => truncate(row.body, BODY_PREVIEW_LENGTH)
    },
    {
      key: 'sentiment',
      header: $localize`:Inbox table column@@inbox.column.sentiment:Sentiment`,
      width: 150,
      // Label *and* score, never colour alone: a data-table cell renders plain
      // text, so the words are the whole signal here.
      cell: (row) =>
        row.analysis === null
          ? EM_DASH
          : `${sentimentLabel(row.analysis.sentiment_label)} ${formatScore(row.analysis.sentiment_score)}`
    },
    {
      key: 'category',
      header: $localize`:Inbox table column@@inbox.column.category:Category`,
      width: 150,
      cell: (row) => (row.analysis === null ? EM_DASH : categoryLabel(row.analysis.category))
    },
    {
      key: 'analysis_status',
      header: $localize`:Inbox table column@@inbox.column.status:Status`,
      width: 170,
      cell: (row) => analysisStatusLabel(row.analysis_status)
    }
  ]);

  protected readonly sentimentOptions = computed<SelectOption[]>(() => [
    { value: '', label: this.anySentimentLabel },
    ...SENTIMENT_LABELS.map((value) => ({ value, label: sentimentLabel(value) }))
  ]);

  protected readonly categoryOptions = computed<SelectOption[]>(() => [
    { value: '', label: this.anyCategoryLabel },
    ...FEEDBACK_CATEGORIES.map((value) => ({ value, label: categoryLabel(value) }))
  ]);

  protected readonly platformOptions = computed<SelectOption[]>(() => [
    { value: '', label: this.anyPlatformLabel },
    ...PLATFORMS.map((value) => ({ value, label: platformLabel(value) }))
  ]);

  protected readonly statusOptions = computed<SelectOption[]>(() => [
    { value: '', label: this.anyStatusLabel },
    ...ANALYSIS_STATUSES.map((value) => ({ value, label: analysisStatusLabel(value) }))
  ]);

  protected readonly integrationOptions = computed<SelectOption[]>(() => [
    { value: '', label: this.anyIntegrationLabel },
    ...this.integrations.items().map((integration) => ({
      value: String(integration.id),
      label: `${platformLabel(integration.platform)} #${integration.id}`
    }))
  ]);

  protected readonly perPageOptions: SelectOption[] = PER_PAGE_CHOICES.map((value) => ({
    value: String(value),
    label: String(value)
  }));

  /**
   * Its own group rather than an `ngModel` on the same template: mixing
   * `FormsModule` into a component that already imports `ReactiveFormsModule`
   * pulls a second forms runtime in for one `<select>`.
   */
  protected readonly paginationForm = this.fb.group({ perPage: String(DEFAULT_PER_PAGE) });

  protected readonly pageSummary = computed(() => {
    const meta = this.meta();
    const total = formatCount(meta.total);
    const page = formatCount(meta.current_page);
    const lastPage = formatCount(meta.last_page);
    return $localize`:Inbox pagination summary@@inbox.pagination.summary:Page ${page}:page: of ${lastPage}:lastPage: · ${total}:total: comments`;
  });

  protected readonly searchLabel = $localize`:Inbox search field label@@inbox.filters.search:Search comments`;
  protected readonly searchHelper = $localize`:Inbox search field hint@@inbox.filters.searchHelper:Matches the comment body and the author name.`;
  protected readonly sentimentFilterLabel = $localize`:Inbox filter label@@inbox.filters.sentiment:Sentiment`;
  protected readonly categoryFilterLabel = $localize`:Inbox filter label@@inbox.filters.category:Category`;
  protected readonly platformFilterLabel = $localize`:Inbox filter label@@inbox.filters.platform:Source`;
  protected readonly statusFilterLabel = $localize`:Inbox filter label@@inbox.filters.status:Analysis status`;
  protected readonly integrationFilterLabel = $localize`:Inbox filter label@@inbox.filters.integration:Connection`;
  protected readonly perPageLabel = $localize`:Inbox rows-per-page label@@inbox.pagination.perPage:Rows per page`;
  protected readonly clearFiltersLabel = $localize`:Clear every inbox filter@@inbox.filters.clear:Clear filters`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly paginationLabel = $localize`:Pagination landmark label@@inbox.pagination.label:Inbox pages`;

  private readonly anySentimentLabel = $localize`:Filter option that applies no constraint@@inbox.filters.anySentiment:Any sentiment`;
  private readonly anyCategoryLabel = $localize`:Filter option that applies no constraint@@inbox.filters.anyCategory:Any category`;
  private readonly anyPlatformLabel = $localize`:Filter option that applies no constraint@@inbox.filters.anyPlatform:Any source`;
  private readonly anyStatusLabel = $localize`:Filter option that applies no constraint@@inbox.filters.anyStatus:Any status`;
  private readonly anyIntegrationLabel = $localize`:Filter option that applies no constraint@@inbox.filters.anyIntegration:Any connection`;

  ngOnInit(): void {
    this.writeFormFromStore();

    this.subscriptions.add(
      this.form.controls.q.valueChanges
        .pipe(debounceTime(SEARCH_DEBOUNCE_MS), distinctUntilChanged())
        .subscribe(() => this.pushFilters())
    );

    for (const control of [
      this.form.controls.sentiment,
      this.form.controls.category,
      this.form.controls.platform,
      this.form.controls.analysis_status,
      this.form.controls.integration_id,
      this.form.controls.from,
      this.form.controls.to
    ]) {
      this.subscriptions.add(control.valueChanges.pipe(distinctUntilChanged()).subscribe(() => this.pushFilters()));
    }

    this.subscriptions.add(
      this.paginationForm.controls.perPage.valueChanges
        .pipe(distinctUntilChanged())
        .subscribe((value) => this.onPerPageChange(value))
    );

    this.store.loadIfNeeded();
    this.integrations.loadIfNeeded();
  }

  ngOnDestroy(): void {
    this.subscriptions.unsubscribe();
  }

  protected onRowActivate(row: Feedback): void {
    void this.router.navigate(['/app/inbox', row.id]);
  }

  protected onRetry(): void {
    this.store.load();
  }

  protected onClearFilters(): void {
    this.store.clearFilters();
    this.writeFormFromStore();
  }

  protected onPreviousPage(): void {
    this.store.setPage(this.page() - 1);
  }

  protected onNextPage(): void {
    this.store.setPage(this.page() + 1);
  }

  private onPerPageChange(value: string): void {
    const parsed = Number.parseInt(value, 10);
    if (Number.isFinite(parsed) && parsed !== this.store.perPage()) {
      this.store.setPerPage(parsed);
    }
  }

  /** Mirrors store state into the form — used on entry, and after a programmatic clear. */
  private writeFormFromStore(): void {
    const filters = this.store.filters();
    this.syncingFromStore = true;
    this.form.setValue({
      q: filters.q ?? '',
      sentiment: filters.sentiment ?? '',
      category: filters.category ?? '',
      platform: filters.platform ?? '',
      analysis_status: filters.analysis_status ?? '',
      integration_id: filters.integration_id === null ? '' : String(filters.integration_id),
      from: filters.from ?? '',
      to: filters.to ?? ''
    });
    this.syncingFromStore = false;
  }

  private pushFilters(): void {
    if (this.syncingFromStore) {
      return;
    }
    const value = this.form.getRawValue();
    const integrationId = Number.parseInt(value.integration_id, 10);

    const filters: FeedbackFilters = {
      q: blankToNull(value.q),
      sentiment: blankToNull(value.sentiment) as SentimentLabel | null,
      category: blankToNull(value.category) as FeedbackCategory | null,
      platform: blankToNull(value.platform) as Platform | null,
      analysis_status: blankToNull(value.analysis_status) as AnalysisStatus | null,
      integration_id: Number.isFinite(integrationId) ? integrationId : null,
      from: blankToNull(value.from),
      to: blankToNull(value.to)
    };

    this.store.setFilters(filters);
  }
}

/**
 * An empty control is "no filter", not an empty value. The server validates
 * every filter with `sometimes`, so sending `sentiment=` would fail `Rule::in`
 * and turn the whole read into a 422.
 */
function blankToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed.length === 0 ? null : trimmed;
}
