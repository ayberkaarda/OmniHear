import { ChangeDetectionStrategy, Component, computed, effect, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';

import { errorMessageForCode } from '../../../core/errors/error-messages';
import { FeedbackDetailStore } from '../../../core/feedback/feedback-detail.store';
import { FeedbackListStore } from '../../../core/feedback/feedback-list.store';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { IconComponent } from '../../../shared/ui/icon/icon.component';
import { EM_DASH, formatDateTime, formatPercent, formatScore, truncate } from '../../../shared/format/format';
import {
  analysisStatusLabel,
  categoryLabel,
  platformLabel,
  sentimentLabel
} from '../../../shared/labels/domain-labels';
import { markRuns } from '../marked-text';

/** One line of the list pane beside the reading pane. */
interface PaneRow {
  readonly id: number;
  readonly author: string;
  readonly source: string;
  readonly preview: string;
  readonly score: string | null;
  readonly tone: string;
}

const PANE_PREVIEW_LENGTH = 120;

/**
 * `/app/inbox/:id` — one comment, and the reasoning behind its analysis.
 *
 * The customer's words are the largest thing on the screen, with the keywords
 * the analyser keyed on marked in place. The analysis sits beside it as a thin
 * ruled panel, and every input the model produced is shown rather than just
 * its verdict: the signed score behind the label, the confidence behind the
 * category, the keywords and the `model_version` that produced all of it. A
 * retrained analyser makes older scores incomparable with newer ones, and that
 * field is the only way to tell.
 *
 * When `analysis` is `null` the screen says which of the four
 * `analysis_status` values it is looking at. It never renders a zero score:
 * "not analysed yet" and "analysed as exactly neutral" are different facts.
 *
 * On wide screens the inbox page you came from stays on the left as a list
 * pane. It is read from `FeedbackListStore` as it already is; this screen
 * never fetches a list of its own, so a direct link shows the reading pane
 * alone.
 */
@Component({
  selector: 'app-inbox-detail',
  standalone: true,
  imports: [RouterLink, ButtonComponent, IconComponent],
  templateUrl: './inbox-detail.component.html',
  styleUrls: [
    './inbox-detail.component.scss',
    './inbox-detail-quote.scss',
    './inbox-detail-analysis.scss',
    './inbox-detail-extra.scss'
  ],
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class InboxDetailComponent {
  private readonly store = inject(FeedbackDetailStore);
  private readonly list = inject(FeedbackListStore);

  /** Bound from the route by `withComponentInputBinding()`, so always a string. */
  readonly id = input<string | undefined>(undefined);

  protected readonly feedback = this.store.feedback;
  protected readonly analysis = this.store.analysis;
  protected readonly state = this.store.state;

  protected readonly loading = computed(() => {
    const state = this.store.state();
    return state === 'idle' || state === 'loading';
  });

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  protected readonly publishedAt = computed(() => formatDateTime(this.feedback()?.published_at));
  protected readonly analyzedAt = computed(() => formatDateTime(this.analysis()?.analyzed_at));
  protected readonly authorName = computed(() => this.feedback()?.author ?? EM_DASH);
  protected readonly sourceLabel = computed(() => {
    const platform = this.feedback()?.platform;
    return platform ? platformLabel(platform) : EM_DASH;
  });

  /** The body as runs, every keyword marked. */
  protected readonly bodyRuns = computed(() => {
    const record = this.feedback();
    return record ? markRuns(record.body, this.analysis()?.keywords ?? []) : [];
  });

  /** Long comments step the quote down a size so they still read as a quote. */
  protected readonly quoteSize = computed(() => {
    const length = this.feedback()?.body.length ?? 0;
    return length > 420 ? 'long' : length > 180 ? 'medium' : 'short';
  });

  protected readonly sentimentScore = computed(() => formatScore(this.analysis()?.sentiment_score));
  protected readonly sentimentWord = computed(() => {
    const label = this.analysis()?.sentiment_label;
    return label ? sentimentLabel(label) : EM_DASH;
  });
  protected readonly categoryWord = computed(() => {
    const category = this.analysis()?.category;
    return category ? categoryLabel(category) : EM_DASH;
  });
  /** -1..+1 mapped onto 0..100 % of the scale track. */
  protected readonly scorePosition = computed(() => {
    const score = this.analysis()?.sentiment_score ?? 0;
    return Math.min(100, Math.max(0, ((score + 1) / 2) * 100));
  });
  protected readonly confidencePercent = computed(() => formatPercent(this.analysis()?.confidence));
  protected readonly confidenceWidth = computed(() => (this.analysis()?.confidence ?? 0) * 100);

  /** Localized sentence for the state the record is in while `analysis` is null. */
  protected readonly pendingExplanation = computed(() => {
    switch (this.feedback()?.analysis_status) {
      case 'pending_analysis':
        return $localize`:Explains why no analysis is shown@@inboxDetail.pending.queued:This comment is queued for analysis. It appears here as soon as the analyser reaches it.`;
      case 'analyzing':
        return $localize`:Explains why no analysis is shown@@inboxDetail.pending.running:The analyser is working on this comment right now.`;
      case 'failed':
        return $localize`:Explains why no analysis is shown@@inboxDetail.pending.failed:Analysis of this comment failed. It is retried automatically.`;
      default:
        return $localize`:Explains why no analysis is shown@@inboxDetail.pending.unknown:There is no analysis for this comment yet.`;
    }
  });

  protected readonly statusLabel = computed(() => {
    const status = this.feedback()?.analysis_status;
    return status ? analysisStatusLabel(status) : EM_DASH;
  });

  /** The inbox page already in memory, for the list pane. Empty on a direct link. */
  protected readonly pane = computed<readonly PaneRow[]>(() =>
    this.list.items().map((item) => ({
      id: item.id,
      author: item.author ?? EM_DASH,
      source: item.platform === null ? EM_DASH : platformLabel(item.platform),
      preview: truncate(item.body, PANE_PREVIEW_LENGTH),
      score: item.analysis === null ? null : formatScore(item.analysis.sentiment_score),
      tone: item.analysis?.sentiment_label ?? 'pending'
    }))
  );

  protected readonly currentId = computed(() => {
    const raw = this.id();
    return raw === undefined ? Number.NaN : Number.parseInt(raw, 10);
  });

  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly openSourceLabel = $localize`:Link to the comment on its original platform@@inboxDetail.openSource:Open on the source platform`;

  constructor() {
    effect(() => {
      const parsed = this.currentId();
      if (Number.isFinite(parsed)) {
        this.store.load(parsed);
      } else {
        this.store.reset();
      }
    });
  }

  protected reload(): void {
    const parsed = this.currentId();
    if (Number.isFinite(parsed)) {
      this.store.load(parsed);
    }
  }
}
