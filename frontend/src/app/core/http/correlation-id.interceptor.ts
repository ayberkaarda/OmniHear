import { HttpInterceptorFn } from '@angular/common/http';

import { isApiRequest } from './api-url';

export const CORRELATION_ID_HEADER = 'X-Correlation-Id';

/**
 * Generates the id the backend echoes back and forwards to the AI service, so a
 * single user action can be followed across all three services (spec 3.6).
 * `crypto.randomUUID` is not available on every browser/test environment, hence
 * the fallback.
 */
export function generateCorrelationId(): string {
  const cryptoRef = typeof globalThis.crypto !== 'undefined' ? globalThis.crypto : undefined;
  if (cryptoRef !== undefined && typeof cryptoRef.randomUUID === 'function') {
    return cryptoRef.randomUUID();
  }
  return `omnihear-${Date.now().toString(16)}-${Math.random().toString(16).slice(2, 10)}`;
}

export const correlationIdInterceptor: HttpInterceptorFn = (request, next) => {
  if (!isApiRequest(request.url) || request.headers.has(CORRELATION_ID_HEADER)) {
    return next(request);
  }

  return next(request.clone({ headers: request.headers.set(CORRELATION_ID_HEADER, generateCorrelationId()) }));
};
