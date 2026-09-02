import { HttpErrorResponse } from '@angular/common/http';

import { FieldErrors, isApiError, toApiError, UNKNOWN_ERROR_CODE } from './api-error';

/**
 * The stable `code` for whatever a failed request threw.
 *
 * In the running application `errorInterceptor` has already replaced the
 * `HttpErrorResponse` with an `ApiError`, so the first branch is the normal
 * path. The second exists because a store must not depend on an interceptor
 * being installed: without it, the same store reports `UNKNOWN_ERROR` in
 * isolation and `NOT_FOUND` in the app, and only one of those is testable.
 */
export function errorCodeOf(error: unknown): string {
  if (isApiError(error)) {
    return error.code;
  }
  if (error instanceof HttpErrorResponse) {
    return toApiError(error).code;
  }
  return UNKNOWN_ERROR_CODE;
}

/**
 * Per-field validation detail of a `422`, for a form to render next to its
 * inputs. Same two-branch reason as `errorCodeOf`: the interceptor normally
 * supplies the `ApiError`, and the raw branch keeps a store meaningful on its
 * own.
 */
export function fieldErrorsOf(error: unknown): FieldErrors | null {
  if (isApiError(error)) {
    return error.fieldErrors;
  }
  if (error instanceof HttpErrorResponse) {
    return toApiError(error).fieldErrors;
  }
  return null;
}
