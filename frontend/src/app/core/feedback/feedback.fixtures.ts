import { Paginated } from '../api/pagination';
import { OverviewKpis } from '../overview/overview.models';
import { AiAnalysis, Feedback } from './feedback.models';

/**
 * Test-only builders for the F5 resources.
 *
 * They exist so a spec asserts against the exact shape of
 * `docs/contracts/wave2-seams.md` section 3 rather than an ad-hoc object that
 * quietly drifts from the wire format — the same reason `auth.fixtures.ts`
 * exists. Not referenced by application code.
 */
export function makeAnalysis(overrides: Partial<AiAnalysis> = {}): AiAnalysis {
  return {
    sentiment_score: -0.5497,
    sentiment_label: 'negative',
    category: 'bug',
    confidence: 0.745,
    keywords: ['crash', 'login'],
    model_version: 'omnihear-onnx-f50df013ccc9',
    analyzed_at: '2026-09-02T11:04:03+00:00',
    ...overrides
  };
}

export function makeFeedback(overrides: Partial<Feedback> = {}): Feedback {
  return {
    id: 1,
    integration_id: 1,
    platform: 'appstore',
    external_id: 'rss-9001',
    author: 'A. Reviewer',
    body: 'The app crashes every time I try to sign in.',
    source_url: 'https://apps.apple.com/review/9001',
    published_at: '2026-09-01T08:00:00+00:00',
    analysis_status: 'analyzed',
    analysis: makeAnalysis(),
    ...overrides
  };
}

export function makeFeedbackPage(
  data: readonly Feedback[] = [makeFeedback()],
  meta: Partial<Paginated<Feedback>['meta']> = {}
): Paginated<Feedback> {
  return {
    data,
    meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1, ...meta }
  };
}

export function makeKpis(overrides: Partial<OverviewKpis> = {}): OverviewKpis {
  return {
    total_feedbacks: 12,
    analyzed_count: 9,
    pending_analysis_count: 3,
    average_sentiment: 0.12,
    sentiment_breakdown: { positive: 4, neutral: 2, negative: 3 },
    category_breakdown: { complaint: 3, praise: 4, bug: 1, feature_request: 1 },
    trend: [
      { date: '2026-08-30', average_sentiment: -0.2, count: 2 },
      { date: '2026-09-01', average_sentiment: 0.4, count: 3 }
    ],
    quota: { limit: 200, used: 12, remaining: 188 },
    ...overrides
  };
}
