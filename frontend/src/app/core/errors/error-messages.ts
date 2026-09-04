import { ApiError, NETWORK_ERROR_CODE } from './api-error';

/**
 * `code` -> localised, user-facing message.
 *
 * The server's own `message` is English developer text and is never shown; the
 * contract (section 6) requires the UI to translate `code` itself. A code that
 * is not in the catalogue falls back to the generic message and is logged, so a
 * newly deployed backend code degrades to "something went wrong" instead of
 * leaking a raw server string into the interface.
 */
export function errorMessageForCode(code: string): string {
  switch (code) {
    case 'VALIDATION_ERROR':
      return $localize`:API error message@@error.validationError:Some of the details you entered are not valid. Please review the form and try again.`;
    case 'INVALID_CREDENTIALS':
      return $localize`:API error message@@error.invalidCredentials:That email and password combination did not match an account.`;
    case 'UNAUTHENTICATED':
      return $localize`:API error message@@error.unauthenticated:Your session has ended. Please sign in again.`;
    case 'EMAIL_NOT_VERIFIED':
      return $localize`:API error message@@error.emailNotVerified:Confirm your email address before you continue.`;
    case 'FORBIDDEN':
      return $localize`:API error message@@error.forbidden:Your role does not allow this action.`;
    case 'NOT_FOUND':
      return $localize`:API error message@@error.notFound:We could not find what you were looking for.`;
    case 'QUOTA_EXCEEDED':
      return $localize`:API error message@@error.quotaExceeded:Your analysis quota is used up. Upgrade to keep analysing feedback.`;
    case 'TOO_MANY_REQUESTS':
      return $localize`:API error message@@error.tooManyRequests:Too many attempts. Please wait a moment and try again.`;
    case 'DISPOSABLE_EMAIL':
      return $localize`:API error message@@error.disposableEmail:Please sign up with your work email address.`;
    case 'TWO_FACTOR_CODE_INVALID':
      return $localize`:API error message@@error.twoFactorCodeInvalid:That code was not accepted. Check your authenticator app and try the current code.`;
    case 'TWO_FACTOR_ALREADY_ENABLED':
      return $localize`:API error message@@error.twoFactorAlreadyEnabled:Two-step verification is already switched on for this account.`;
    case 'TWO_FACTOR_NOT_ENABLED':
      // A 409, and almost always a second tab or a screen left open: the
      // account moved out of the state the request assumed. Nothing the user
      // typed was wrong, so the message says what is true rather than blaming.
      return $localize`:API error message@@error.twoFactorNotEnabled:Two-step verification is not switched on for this account, or its setup was never finished. Reload the page to see where things stand.`;
    case 'SERVER_ERROR':
      return $localize`:API error message@@error.serverError:Something went wrong on our side. Please try again shortly.`;
    case NETWORK_ERROR_CODE:
      return $localize`:API error message@@error.network:We could not reach OmniHear. Check your connection and try again.`;
    default:
      // Deliberately a console warning, not a thrown error: an unmapped code must
      // degrade the message, never break the screen the user is on.
      console.warn(`[OmniHear] Unmapped API error code: ${code}`);
      return $localize`:API error message@@error.unknown:Something went wrong. Please try again.`;
  }
}

export function errorMessageFor(error: ApiError): string {
  return errorMessageForCode(error.code);
}
