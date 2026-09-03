import { inject, Injectable } from '@angular/core';

import { FeedbackListStore } from '../feedback/feedback-list.store';
import { OverviewStore } from '../overview/overview.store';
import { QuotaStore } from '../quota/quota.store';
import { ToastService } from '../toast/toast.service';
import { FeedbackAnalyzedEvent, QuotaThresholdReachedEvent } from './realtime.models';
import { RealtimeService } from './realtime.service';

/**
 * Turns broadcast events into application state.
 *
 * Split from `RealtimeService` so the transport has no dependency on any store
 * and this file has no dependency on a socket: each half is testable without
 * the other, and the store updates below can be exercised by calling the
 * handlers directly — which is exactly what the zoneless probe does.
 *
 * **The list is never re-fetched on an event.** Spec 6.6 asks for an optimistic
 * update, and a refetch per event would turn a burst of analyses — the normal
 * shape of a sync run finishing — into a request storm. A row that is not on
 * the current page is simply not updated; the next read shows it correctly.
 */
@Injectable({ providedIn: 'root' })
export class RealtimeBridge {
  private readonly realtime = inject(RealtimeService);
  private readonly feedback = inject(FeedbackListStore);
  private readonly overview = inject(OverviewStore);
  private readonly quota = inject(QuotaStore);
  private readonly toasts = inject(ToastService);

  /** The 80% warning is a nudge, not a nag: once per session is enough. */
  private thresholdAnnounced = false;

  readonly status = this.realtime.status;

  start(): void {
    void this.realtime.connect({
      onFeedbackAnalyzed: (event) => this.applyFeedbackAnalyzed(event),
      onQuotaThresholdReached: (event) => this.applyQuotaThreshold(event)
    });
  }

  stop(): void {
    this.realtime.disconnect();
  }

  /** Exposed for the bridge's own tests; the socket calls it through `start()`. */
  applyFeedbackAnalyzed(event: FeedbackAnalyzedEvent): void {
    this.feedback.applyAnalysis(event);
    this.overview.applyAnalysis(event);
  }

  applyQuotaThreshold(event: QuotaThresholdReachedEvent): void {
    this.quota.setUsage(event.limit, event.remaining);

    if (this.thresholdAnnounced) {
      return;
    }
    this.thresholdAnnounced = true;
    this.toasts.show(
      $localize`:In-app 80% quota warning (spec 7.3)@@realtime.quotaWarning:You have used 80% of your analysis quota. Upgrade before it runs out to keep new feedback being analysed.`,
      'info'
    );
  }
}
