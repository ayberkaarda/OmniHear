import { computed, inject, Injectable, signal } from '@angular/core';

import { RequestState } from '../api/request-state';
import { errorCodeOf } from '../errors/error-code';
import { Feedback } from './feedback.models';
import { FeedbackService } from './feedback.service';

/**
 * One feedback row and the analysis attached to it.
 *
 * `analysis === null` is a first-class outcome here, not a loading state: the
 * record exists and the analyser has simply not reached it yet. The screen has
 * to distinguish the two, so `analysis_status` is rendered rather than
 * inferred from the absence of a score.
 */
@Injectable({ providedIn: 'root' })
export class FeedbackDetailStore {
  private readonly service = inject(FeedbackService);

  private readonly feedbackSignal = signal<Feedback | null>(null);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private requestToken = 0;

  readonly feedback = this.feedbackSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();

  readonly analysis = computed(() => this.feedbackSignal()?.analysis ?? null);
  readonly analysisStatus = computed(() => this.feedbackSignal()?.analysis_status ?? null);

  load(id: number): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);
    this.feedbackSignal.set(null);

    this.service.get(id).subscribe({
      next: (feedback) => {
        if (token !== this.requestToken) {
          return;
        }
        this.feedbackSignal.set(feedback);
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
    this.feedbackSignal.set(null);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
  }
}
