import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../environments/environment';
import { InboxComponent } from '../../features/app/inbox/inbox.component';
import { makeFeedback, makeFeedbackPage } from '../feedback/feedback.fixtures';
import { FeedbackListStore } from '../feedback/feedback-list.store';
import { IntegrationsStore } from '../integrations/integrations.store';
import { RealtimeBridge } from './realtime.bridge';

const FEEDBACKS = `${environment.apiBaseUrl}/v1/feedbacks`;
const INTEGRATIONS = `${environment.apiBaseUrl}/v1/integrations`;

/**
 * The zoneless probe, in the shape virtual scroll was probed in the previous phase.
 *
 * The risk this rules out is specific and silent: under
 * `provideZonelessChangeDetection` there is no zone patching `WebSocket`, so a
 * callback arriving from pusher-js runs entirely outside anything Angular is
 * watching. If the realtime path wrote to a plain field instead of a signal,
 * the store would hold the new value and **the screen would keep showing the
 * old one** — state changed, view stale, no error anywhere.
 *
 * So the event is delivered the way a socket delivers one: from a `setTimeout`
 * callback, outside any Angular API, with **no `detectChanges()` anywhere in
 * this file**. What is asserted is the rendered DOM, not the store.
 */
describe('realtime under zoneless change detection', () => {
  it('re-renders the inbox row from a callback fired outside Angular', async () => {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(FeedbackListStore).reset();
    TestBed.inject(IntegrationsStore).reset();

    const http = TestBed.inject(HttpTestingController);
    const bridge = TestBed.inject(RealtimeBridge);

    const fixture = TestBed.createComponent(InboxComponent);
    const element = fixture.nativeElement as HTMLElement;
    await fixture.whenStable();

    http
      .expectOne((candidate) => candidate.url === FEEDBACKS)
      .flush(makeFeedbackPage([makeFeedback({ id: 1, analysis_status: 'pending_analysis', analysis: null })]));
    http.expectOne((candidate) => candidate.url === INTEGRATIONS).flush({
      data: [],
      meta: { current_page: 1, per_page: 100, total: 0, last_page: 1 }
    });
    await fixture.whenStable();

    // Scoped to the table body: the filter selects list every sentiment and
    // status as <option> text, so a page-wide assertion would pass on the
    // filter bar rather than on the row.
    const body = (): string => element.querySelector('tbody')?.textContent ?? '';

    expect(body()).toContain('Waiting for analysis');
    expect(body()).not.toContain('Negative');

    // Delivered the way the socket delivers it: from a macrotask, through no
    // Angular API at all.
    await new Promise<void>((resolve) => {
      setTimeout(() => {
        bridge.applyFeedbackAnalyzed({
          feedback_id: 1,
          sentiment_label: 'negative',
          sentiment_score: -0.5497,
          category: 'bug',
          model_version: 'omnihear-onnx-f50df013ccc9'
        });
        resolve();
      }, 0);
    });

    await fixture.whenStable();

    expect(body()).toContain('Negative');
    expect(body()).toContain('-0.55');
    expect(body()).toContain('Bug');
    expect(body()).toContain('Analysed');
    expect(body()).not.toContain('Waiting for analysis');

    http.verify();
  });
});
