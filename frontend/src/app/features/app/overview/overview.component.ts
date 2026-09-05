import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Router } from '@angular/router';

import { errorMessageForCode } from '../../../core/errors/error-messages';
import {
  FEEDBACK_CATEGORIES,
  FeedbackCategory,
  SENTIMENT_LABELS,
  SentimentLabel
} from '../../../core/feedback/feedback.models';
import { FeedbackListStore } from '../../../core/feedback/feedback-list.store';
import { OverviewStore } from '../../../core/overview/overview.store';
import { BadgeComponent } from '../../../shared/ui/badge/badge.component';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { KpiCardComponent } from '../../../shared/ui/kpi-card/kpi-card.component';
import { formatCount, formatPercent } from '../../../shared/format/format';
import { categoryLabel, sentimentLabel } from '../../../shared/labels/domain-labels';
import { SentimentTrendComponent } from './sentiment-trend.component';

interface Segment<T extends string> {
  readonly key: T;
  readonly label: string;
  readonly count: number;
  readonly share: number;
  readonly percentLabel: string;
  /**
   * The CSS custom property the filled shape is painted with. Read from the
   * token layer, never a literal: `docs/playbooks/omnihear-tokens` section 4
   * bans raw hex and primitive Tailwind colours in application code.
   */
  readonly fillVar: string;
}

/** Sentiment fills only. A magnitude bar that carries no sentiment stays neutral brand. */
const SENTIMENT_FILL: Readonly<Record<SentimentLabel, string>> = {
  negative: 'var(--sentiment-negative-fill)',
  neutral: 'var(--sentiment-neutral-fill)',
  positive: 'var(--sentiment-positive-fill)'
};

const NEUTRAL_FILL = 'var(--brand)';

/**
 * `/app/overview` — the KPI aggregate of `GET /api/v1/overview/kpis`.
 *
 * Every number on this screen comes from one request, so there is a single
 * loading state and a single error state; a per-card spinner would suggest the
 * cards are independently refreshable, which they are not.
 *
 * The cards and the breakdown rows are navigation, not decoration: each one
 * seeds `FeedbackListStore` with the matching filter and moves to the inbox.
 * `shared/ui/kpi-card` renders as a `<button>`, so a card that did nothing on
 * activation would be a focusable control with no behaviour.
 */
@Component({
  selector: 'app-overview',
  standalone: true,
  imports: [KpiCardComponent, BadgeComponent, ButtonComponent, SentimentTrendComponent],
  templateUrl: './overview.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class OverviewComponent {
  private readonly store = inject(OverviewStore);
  private readonly inbox = inject(FeedbackListStore);
  private readonly router = inject(Router);

  protected readonly kpis = this.store.kpis;
  protected readonly state = this.store.state;
  protected readonly loading = this.store.loading;
  protected readonly trend = this.store.trend;
  protected readonly isEmpty = this.store.isEmpty;

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  /**
   * Fixed stack order — negative, neutral, positive — with the legend in the
   * same order, per the tokens skill. The order is itself a channel: it holds
   * when the colours do not.
   */
  protected readonly sentimentSegments = computed<readonly Segment<SentimentLabel>[]>(() => {
    const breakdown = this.kpis()?.sentiment_breakdown;
    if (!breakdown) {
      return [];
    }
    const order: readonly SentimentLabel[] = ['negative', 'neutral', 'positive'];
    const total = SENTIMENT_LABELS.reduce((sum, key) => sum + breakdown[key], 0);
    return order.map((key) => toSegment(key, sentimentLabel(key), breakdown[key], total, SENTIMENT_FILL[key]));
  });

  protected readonly analysedTotal = computed(() =>
    this.sentimentSegments().reduce((sum, segment) => sum + segment.count, 0)
  );

  protected readonly categorySegments = computed<readonly Segment<FeedbackCategory>[]>(() => {
    const breakdown = this.kpis()?.category_breakdown;
    if (!breakdown) {
      return [];
    }
    const total = FEEDBACK_CATEGORIES.reduce((sum, key) => sum + breakdown[key], 0);
    return FEEDBACK_CATEGORIES.map((key) => toSegment(key, categoryLabel(key), breakdown[key], total, NEUTRAL_FILL));
  });

  protected readonly quotaUsedLabel = computed(() => {
    const quota = this.kpis()?.quota;
    if (!quota) {
      return '';
    }
    return `${formatCount(quota.used)} / ${formatCount(quota.limit)}`;
  });

  protected readonly totalLabel = $localize`:Overview KPI card title@@overview.kpi.total:Comments collected`;
  protected readonly analysedLabel = $localize`:Overview KPI card title@@overview.kpi.analysed:Analysed`;
  protected readonly pendingLabel = $localize`:Overview KPI card title@@overview.kpi.pending:Waiting for analysis`;
  protected readonly averageLabel = $localize`:Overview KPI card title@@overview.kpi.average:Average sentiment`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;

  constructor() {
    this.store.load();
  }

  protected reload(): void {
    this.store.load();
  }

  protected countOf(segment: Segment<string>): string {
    return formatCount(segment.count);
  }

  protected goToInbox(): void {
    this.inbox.clearFilters();
    void this.router.navigate(['/app/inbox']);
  }

  protected goToStatus(status: 'analyzed' | 'pending_analysis'): void {
    this.inbox.setFilters({ analysis_status: status, sentiment: null, category: null });
    void this.router.navigate(['/app/inbox']);
  }

  protected goToSentiment(sentiment: SentimentLabel): void {
    this.inbox.setFilters({ sentiment, category: null, analysis_status: null });
    void this.router.navigate(['/app/inbox']);
  }

  protected goToCategory(category: FeedbackCategory): void {
    this.inbox.setFilters({ category, sentiment: null, analysis_status: null });
    void this.router.navigate(['/app/inbox']);
  }
}

function toSegment<T extends string>(
  key: T,
  label: string,
  count: number,
  total: number,
  fillVar: string
): Segment<T> {
  const share = total === 0 ? 0 : count / total;
  return { key, label, count, share, percentLabel: formatPercent(share), fillVar };
}
