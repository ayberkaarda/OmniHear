/**
 * `GET /api/v1/overview/kpis` — `docs/contracts/wave2-seams.md` section 3.
 *
 * Both breakdowns arrive with every enum key present and zero-filled, so the
 * UI never has to distinguish "no negative feedback" from "the key is missing".
 */
import { FeedbackCategory, SentimentLabel } from '../feedback/feedback.models';

export type SentimentBreakdown = Readonly<Record<SentimentLabel, number>>;
export type CategoryBreakdown = Readonly<Record<FeedbackCategory, number>>;

/**
 * One day of the trailing 30-day window.
 *
 * Days with no analysis are **absent from the series**, not zero: a zero
 * average would draw as neutral sentiment, which says something different from
 * "nothing was analysed that day". The trend chart has to render the gap.
 */
export interface TrendPoint {
  readonly date: string;
  readonly average_sentiment: number;
  readonly count: number;
}

export interface OverviewQuota {
  readonly limit: number;
  readonly used: number;
  readonly remaining: number;
}

export interface OverviewKpis {
  readonly total_feedbacks: number;
  readonly analyzed_count: number;
  readonly pending_analysis_count: number;
  readonly average_sentiment: number;
  readonly sentiment_breakdown: SentimentBreakdown;
  readonly category_breakdown: CategoryBreakdown;
  readonly trend: readonly TrendPoint[];
  readonly quota: OverviewQuota;
}
