import { HttpErrorResponse, HttpHeaders } from '@angular/common/http';

import { isApiError, NETWORK_ERROR_CODE, toApiError, UNKNOWN_ERROR_CODE } from './api-error';

function errorResponse(init: {
  status: number;
  error?: unknown;
  headers?: Record<string, string>;
}): HttpErrorResponse {
  return new HttpErrorResponse({
    status: init.status,
    error: init.error ?? null,
    headers: new HttpHeaders(init.headers ?? {}),
    url: 'http://localhost:8000/api/v1/auth/me'
  });
}

describe('toApiError', () => {
  it('reads the contract envelope: code, message and field errors', () => {
    const error = toApiError(
      errorResponse({
        status: 422,
        error: {
          code: 'VALIDATION_ERROR',
          message: 'The given data was invalid.',
          errors: { email: ['The email has already been taken.'] }
        }
      })
    );

    expect(error.code).toBe('VALIDATION_ERROR');
    expect(error.status).toBe(422);
    expect(error.message).toBe('The given data was invalid.');
    expect(error.fieldErrors).toEqual({ email: ['The email has already been taken.'] });
  });

  it('picks up Retry-After and X-Correlation-Id from the response headers', () => {
    const error = toApiError(
      errorResponse({
        status: 429,
        error: { code: 'TOO_MANY_REQUESTS', message: 'Too Many Attempts.' },
        headers: { 'Retry-After': '30', 'X-Correlation-Id': 'corr-1' }
      })
    );

    expect(error.retryAfterSeconds).toBe(30);
    expect(error.correlationId).toBe('corr-1');
  });

  it('maps a transport failure (status 0) to NETWORK_ERROR', () => {
    const error = toApiError(errorResponse({ status: 0 }));

    expect(error.code).toBe(NETWORK_ERROR_CODE);
  });

  it('falls back to UNKNOWN_ERROR when the body carries no code', () => {
    const error = toApiError(errorResponse({ status: 500, error: '<html>gateway error</html>' }));

    expect(error.code).toBe(UNKNOWN_ERROR_CODE);
  });

  it('returns null field errors when `errors` is not an object of messages', () => {
    const error = toApiError(errorResponse({ status: 422, error: { code: 'VALIDATION_ERROR', errors: 'nope' } }));

    expect(error.fieldErrors).toBeNull();
  });
});

describe('isApiError', () => {
  it('accepts a normalised error and rejects anything else', () => {
    expect(isApiError(toApiError(errorResponse({ status: 404, error: { code: 'NOT_FOUND' } })))).toBe(true);
    expect(isApiError(new Error('boom'))).toBe(false);
    expect(isApiError(null)).toBe(false);
  });
});
