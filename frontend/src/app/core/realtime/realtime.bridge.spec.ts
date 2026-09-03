import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { makeFeedback, makeFeedbackPage, makeKpis } from '../feedback/feedback.fixtures';
import { FeedbackListStore } from '../feedback/feedback-list.store';
import { OverviewStore } from '../overview/overview.store';
import { QuotaStore } from '../quota/quota.store';
import { ToastService } from '../toast/toast.service';
import { RealtimeBridge } from './realtime.bridge';
import { RealtimeService } from './realtime.service';

const FEEDBACKS = `${environment.apiBaseUrl}/v1/feedbacks`;
const KPIS = `${environment.apiBaseUrl}/v1/overview/kpis`;

const ANALYZED = {
  feedback_id: 1,
  sentiment_label: 'negative',
  sentiment_score: -0.5497,
  category: 'bug',
  model_version: 'omnihear-onnx-f50df013ccc9'
} as const;

describe('RealtimeBridge', () => {
  let bridge: RealtimeBridge;
  let feedback: FeedbackListStore;
  let overview: OverviewStore;
  let quota: QuotaStore;
  let toasts: ToastService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    bridge = TestBed.inject(RealtimeBridge);
    feedback = TestBed.inject(FeedbackListStore);
    overview = TestBed.inject(OverviewStore);
    quota = TestBed.inject(QuotaStore);
    toasts = TestBed.inject(ToastService);
    http = TestBed.inject(HttpTestingController);
    feedback.reset();
    overview.reset();
    quota.reset();
    toasts.clear();
  });

  afterEach(() => http.verify());

  function loadInbox(): void {
    feedback.load();
    http
      .expectOne((candidate) => candidate.url === FEEDBACKS)
      .flush(makeFeedbackPage([makeFeedback({ id: 1, analysis_status: 'pending_analysis', analysis: null })]));
  }

  it('updates the row in place and makes no request at all', () => {
    loadInbox();

    bridge.applyFeedbackAnalyzed(ANALYZED);

    const row = feedback.items()[0];
    expect(row.analysis_status).toBe('analyzed');
    expect(row.analysis?.sentiment_label).toBe('negative');
    expect(row.analysis?.category).toBe('bug');
    expect(row.analysis?.sentiment_score).toBeCloseTo(-0.5497);

    // The point of the whole design: `afterEach`'s `http.verify()` is what
    // proves it, and a burst is what would break it.
    for (let id = 2; id <= 40; id++) {
      bridge.applyFeedbackAnalyzed({ ...ANALYZED, feedback_id: id });
    }
    http.expectNone(() => true);
  });

  it('invents nothing the broadcast did not carry', () => {
    loadInbox();
    bridge.applyFeedbackAnalyzed(ANALYZED);

    const analysis = feedback.items()[0].analysis;
    // The payload has five fields; confidence, keywords and analyzed_at are not
    // among them, and a zero confidence would read as a measurement.
    expect(analysis?.confidence).toBeNull();
    expect(analysis?.keywords).toEqual([]);
    expect(analysis?.analyzed_at).toBeNull();
  });

  it('leaves a row that is not on this page alone', () => {
    loadInbox();
    const before = feedback.items();

    bridge.applyFeedbackAnalyzed({ ...ANALYZED, feedback_id: 9999 });

    expect(feedback.items()).toBe(before);
  });

  it('nudges the overview counters instead of re-reading them', () => {
    overview.load();
    http.expectOne(KPIS).flush(
      makeKpis({
        analyzed_count: 3,
        pending_analysis_count: 7,
        average_sentiment: 0.3,
        sentiment_breakdown: { positive: 2, neutral: 1, negative: 0 },
        category_breakdown: { complaint: 1, praise: 2, bug: 0, feature_request: 0 },
        quota: { limit: 200, used: 3, remaining: 197 }
      })
    );

    bridge.applyFeedbackAnalyzed(ANALYZED);

    const kpis = overview.kpis();
    expect(kpis?.analyzed_count).toBe(4);
    expect(kpis?.pending_analysis_count).toBe(6);
    expect(kpis?.sentiment_breakdown.negative).toBe(1);
    expect(kpis?.category_breakdown.bug).toBe(1);
    // Running mean over the previous analyzed_count, the way the server computes it.
    expect(kpis?.average_sentiment).toBeCloseTo((0.3 * 3 + -0.5497) / 4);
    // Quota is owned by the header and by `quota.threshold-reached`; counting it
    // here as well would double-count the same analysis.
    expect(kpis?.quota).toEqual({ limit: 200, used: 3, remaining: 197 });
  });

  it('does not invent KPI numbers before the first read', () => {
    bridge.applyFeedbackAnalyzed(ANALYZED);
    expect(overview.kpis()).toBeNull();
  });

  it('drives the 80% warning once, and only once', () => {
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());

    bridge.applyQuotaThreshold({ used: 160, limit: 200, remaining: 40 });

    expect(quota.remaining()).toBe(40);
    expect(quota.limit()).toBe(200);
    expect(quota.level()).toBe('warning');
    expect(toasts.toasts()).toHaveLength(1);

    bridge.applyQuotaThreshold({ used: 161, limit: 200, remaining: 39 });
    expect(quota.remaining()).toBe(39);
    expect(toasts.toasts()).toHaveLength(1);
  });

  it('lets the event limit win over the limit the session was opened with', () => {
    const auth = TestBed.inject(AuthStore);
    auth.setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200 }));

    bridge.applyQuotaThreshold({ used: 4000, limit: 5000, remaining: 1000 });

    expect(quota.limit()).toBe(5000);
  });

  it('never opens a socket without a Reverb key, and says so', async () => {
    // The build ships `reverb.key = ''` by default, so this is the shipped path:
    // realtime degrades to nothing and the application is unaffected.
    expect(environment.reverb.key).toBe('');

    bridge.start();
    await TestBed.inject(RealtimeService).connect({
      onFeedbackAnalyzed: () => undefined,
      onQuotaThresholdReached: () => undefined
    });

    expect(bridge.status()).toBe('disabled');
    http.expectNone(() => true);
  });

  it('survives a stop with nothing open', () => {
    expect(() => bridge.stop()).not.toThrow();
  });
});
