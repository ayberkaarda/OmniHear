import { ChangeDetectionStrategy, Component, computed, effect, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';

import { errorMessageForCode } from '../../../core/errors/error-messages';
import { FeedbackDetailStore } from '../../../core/feedback/feedback-detail.store';
import { BadgeComponent } from '../../../shared/ui/badge/badge.component';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { EM_DASH, formatDateTime, formatPercent, formatScore } from '../../../shared/format/format';
import { analysisStatusLabel, platformLabel } from '../../../shared/labels/domain-labels';

/**
 * `/app/inbox/:id` — one comment, and the reasoning behind its analysis.
 *
 * The reasoning is the point of the screen, so every input the model produced
 * is shown rather than just its verdict: the signed score behind the label, the
 * confidence behind the category, the keywords it keyed on, and the
 * `model_version` that produced all of it. A retrained analyser makes older
 * scores incomparable with newer ones, and that field is the only way to tell.
 *
 * When `analysis` is `null` the screen says which of the four
 * `analysis_status` values it is looking at. It never renders a zero score:
 * "not analysed yet" and "analysed as exactly neutral" are different facts.
 */
@Component({
  selector: 'app-inbox-detail',
  standalone: true,
  imports: [RouterLink, BadgeComponent, ButtonComponent],
  templateUrl: './inbox-detail.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class InboxDetailComponent {
  private readonly store = inject(FeedbackDetailStore);

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

  protected readonly sentimentScore = computed(() => formatScore(this.analysis()?.sentiment_score));
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

  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly openSourceLabel = $localize`:Link to the comment on its original platform@@inboxDetail.openSource:Open on the source platform`;

  constructor() {
    effect(() => {
      const raw = this.id();
      const parsed = raw === undefined ? Number.NaN : Number.parseInt(raw, 10);
      if (Number.isFinite(parsed)) {
        this.store.load(parsed);
      } else {
        this.store.reset();
      }
    });
  }

  protected reload(): void {
    const raw = this.id();
    const parsed = raw === undefined ? Number.NaN : Number.parseInt(raw, 10);
    if (Number.isFinite(parsed)) {
      this.store.load(parsed);
    }
  }
}
