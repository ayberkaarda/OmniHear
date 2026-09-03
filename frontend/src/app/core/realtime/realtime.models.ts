/**
 * Wire shapes of the two broadcast events — `docs/contracts/realtime.md` section 2.
 *
 * The names are what `broadcastAs()` returns on the server, so the client
 * subscribes to these strings rather than to class names. Echo prefixes an
 * event with the application namespace unless it starts with a dot, which is
 * why every listener registers `.${EVENT}`.
 *
 * Both payloads arrive off a socket, i.e. from outside anything TypeScript
 * checked, so each one is validated at the boundary. A malformed frame is
 * dropped rather than written into a store: a `NaN` sentiment score would
 * propagate into the KPI average and quietly corrupt every later reading.
 */
import { FEEDBACK_CATEGORIES, FeedbackCategory, SENTIMENT_LABELS, SentimentLabel } from '../feedback/feedback.models';

/** Echo's `private()` prepends `private-`, so this is the name without it. */
export const COMPANY_CHANNEL_PREFIX = 'company.';

export const FEEDBACK_ANALYZED_EVENT = 'feedback.analyzed';
export const QUOTA_THRESHOLD_REACHED_EVENT = 'quota.threshold-reached';

export interface FeedbackAnalyzedEvent {
  readonly feedback_id: number;
  readonly sentiment_label: SentimentLabel;
  readonly sentiment_score: number;
  readonly category: FeedbackCategory;
  readonly model_version: string;
}

export interface QuotaThresholdReachedEvent {
  readonly used: number;
  readonly limit: number;
  /** Floored at zero by the server. */
  readonly remaining: number;
}

export function channelNameFor(companyId: number): string {
  return `${COMPANY_CHANNEL_PREFIX}${companyId}`;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function isFiniteNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value);
}

export function parseFeedbackAnalyzed(payload: unknown): FeedbackAnalyzedEvent | null {
  if (!isRecord(payload)) {
    return null;
  }
  const { feedback_id, sentiment_label, sentiment_score, category, model_version } = payload;

  if (
    !isFiniteNumber(feedback_id) ||
    !isFiniteNumber(sentiment_score) ||
    typeof model_version !== 'string' ||
    !SENTIMENT_LABELS.includes(sentiment_label as SentimentLabel) ||
    !FEEDBACK_CATEGORIES.includes(category as FeedbackCategory)
  ) {
    return null;
  }

  return {
    feedback_id,
    sentiment_label: sentiment_label as SentimentLabel,
    sentiment_score,
    category: category as FeedbackCategory,
    model_version
  };
}

export function parseQuotaThresholdReached(payload: unknown): QuotaThresholdReachedEvent | null {
  if (!isRecord(payload)) {
    return null;
  }
  const { used, limit, remaining } = payload;

  if (!isFiniteNumber(used) || !isFiniteNumber(limit) || !isFiniteNumber(remaining)) {
    return null;
  }

  return { used, limit, remaining };
}
