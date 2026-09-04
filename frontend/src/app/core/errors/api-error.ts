import { HttpErrorResponse } from '@angular/common/http';

/**
 * The stable `code` catalogue of `docs/contracts/http-api-v1.md` section 2,
 * plus two client-side codes the server can never send.
 */
export const API_ERROR_CODES = [
  'VALIDATION_ERROR',
  'INVALID_CREDENTIALS',
  'UNAUTHENTICATED',
  'EMAIL_NOT_VERIFIED',
  'FORBIDDEN',
  'NOT_FOUND',
  'QUOTA_EXCEEDED',
  'TOO_MANY_REQUESTS',
  'DISPOSABLE_EMAIL',
  'TWO_FACTOR_CODE_INVALID',
  'TWO_FACTOR_ALREADY_ENABLED',
  'TWO_FACTOR_NOT_ENABLED',
  'SERVER_ERROR'
] as const;

export type ApiErrorCode = (typeof API_ERROR_CODES)[number];

/** Raised when the request never reached the API (offline, DNS, CORS, abort). */
export const NETWORK_ERROR_CODE = 'NETWORK_ERROR';
/** Raised when the response body did not carry a usable `code`. */
export const UNKNOWN_ERROR_CODE = 'UNKNOWN_ERROR';

export type FieldErrors = Readonly<Record<string, readonly string[]>>;

/**
 * Normalised transport error. `code` is intentionally a plain `string`: the
 * server may add a code before the frontend knows about it, and the message
 * mapper has to survive that (it falls back, it never renders `message`).
 */
export interface ApiError {
  readonly code: string;
  readonly status: number;
  /** Developer-facing English text from the server. Never rendered to the user. */
  readonly message: string;
  readonly fieldErrors: FieldErrors | null;
  readonly retryAfterSeconds: number | null;
  readonly correlationId: string | null;
}

interface ApiErrorBody {
  code?: unknown;
  message?: unknown;
  errors?: unknown;
}

function parseFieldErrors(raw: unknown): FieldErrors | null {
  if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
    return null;
  }
  const result: Record<string, string[]> = {};
  for (const [field, messages] of Object.entries(raw as Record<string, unknown>)) {
    if (Array.isArray(messages)) {
      result[field] = messages.filter((entry): entry is string => typeof entry === 'string');
    } else if (typeof messages === 'string') {
      result[field] = [messages];
    }
  }
  return Object.keys(result).length > 0 ? result : null;
}

function parseRetryAfter(raw: string | null): number | null {
  if (raw === null) {
    return null;
  }
  const seconds = Number.parseInt(raw, 10);
  return Number.isFinite(seconds) ? seconds : null;
}

/** Turns Angular's `HttpErrorResponse` into the shape the app reasons about. */
export function toApiError(response: HttpErrorResponse): ApiError {
  const body = (response.error ?? null) as ApiErrorBody | null;
  const bodyCode = typeof body?.code === 'string' && body.code.length > 0 ? body.code : null;

  // status 0 means the browser never got a response — a body-less transport failure.
  const code = bodyCode ?? (response.status === 0 ? NETWORK_ERROR_CODE : UNKNOWN_ERROR_CODE);
  const message = typeof body?.message === 'string' ? body.message : response.message;

  return {
    code,
    status: response.status,
    message,
    fieldErrors: parseFieldErrors(body?.errors),
    retryAfterSeconds: parseRetryAfter(response.headers?.get('Retry-After') ?? null),
    correlationId: response.headers?.get('X-Correlation-Id') ?? null
  };
}

export function isApiError(value: unknown): value is ApiError {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as ApiError).code === 'string' &&
    typeof (value as ApiError).status === 'number'
  );
}
