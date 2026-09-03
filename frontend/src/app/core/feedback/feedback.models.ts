/**
 * Feedback shapes — `docs/contracts/wave2-seams.md` section 3 (F5).
 *
 * `raw_payload` is absent because the API never serializes it: it is unbounded
 * provider data full of author PII, and a client type that named it would
 * invite someone to ask for it.
 */
import { Platform } from '../integrations/integration.models';

/** `AiAnalysis::SENTIMENT_LABELS`. */
export const SENTIMENT_LABELS = ['positive', 'neutral', 'negative'] as const;
export type SentimentLabel = (typeof SENTIMENT_LABELS)[number];

/** `AiAnalysis::CATEGORIES`. */
export const FEEDBACK_CATEGORIES = ['complaint', 'praise', 'bug', 'feature_request'] as const;
export type FeedbackCategory = (typeof FEEDBACK_CATEGORIES)[number];

/** `Feedback::STATUSES`. */
export const ANALYSIS_STATUSES = ['pending_analysis', 'analyzing', 'analyzed', 'failed'] as const;
export type AnalysisStatus = (typeof ANALYSIS_STATUSES)[number];

export interface AiAnalysis {
  readonly sentiment_score: number;
  readonly sentiment_label: SentimentLabel;
  readonly category: FeedbackCategory;
  /**
   * `null` when the analysis arrived over the websocket rather than from the
   * API: `feedback.analyzed` is deliberately an invalidation signal plus the
   * four fields a row needs, not the whole record (`docs/contracts/realtime.md`
   * section 2). A zero would render as "0% confident", which is a measurement
   * nobody made.
   */
  readonly confidence: number | null;
  readonly keywords: readonly string[];
  readonly model_version: string;
  readonly analyzed_at: string | null;
}

export interface Feedback {
  readonly id: number;
  readonly integration_id: number;
  readonly platform: Platform | null;
  readonly external_id: string;
  readonly author: string | null;
  readonly body: string;
  readonly source_url: string | null;
  readonly published_at: string | null;
  readonly analysis_status: AnalysisStatus;
  /** `null` until `analysis_status` is `analyzed`. Never faked into a zero score. */
  readonly analysis: AiAnalysis | null;
}

/** `{ "feedback": {...} }` — a single resource carries a named key, not `data`. */
export interface FeedbackResponse {
  readonly feedback: Feedback;
}

/**
 * Every filter `GET /api/v1/feedbacks` accepts. A field left `null` is omitted
 * from the query string entirely — the server validates presence with
 * `sometimes`, so sending an empty string would fail validation rather than
 * mean "no filter".
 */
export interface FeedbackFilters {
  readonly sentiment: SentimentLabel | null;
  readonly category: FeedbackCategory | null;
  readonly platform: Platform | null;
  readonly integration_id: number | null;
  readonly analysis_status: AnalysisStatus | null;
  /** `YYYY-MM-DD`, from a native date input. */
  readonly from: string | null;
  readonly to: string | null;
  readonly q: string | null;
}

export const EMPTY_FILTERS: FeedbackFilters = {
  sentiment: null,
  category: null,
  platform: null,
  integration_id: null,
  analysis_status: null,
  from: null,
  to: null,
  q: null
};

export function hasActiveFilter(filters: FeedbackFilters): boolean {
  return Object.values(filters).some((value) => value !== null && value !== '');
}
