import { AbstractControl } from '@angular/forms';

import { FieldErrors } from '../../../core/errors/api-error';

/** Minimum accepted by the API (contract 5: `min:12`). Kept in sync by hand. */
export const MIN_PASSWORD_LENGTH = 12;

/**
 * Client-side validation text.
 *
 * Deliberately localised here rather than taken from the server: these messages
 * fire before any request is made, so there is nothing to translate from.
 */
export function controlErrorMessage(control: AbstractControl | null | undefined): string | undefined {
  if (control === null || control === undefined || !control.touched || control.valid) {
    return undefined;
  }
  if (control.hasError('required')) {
    return $localize`:Form validation message@@form.error.required:This field is required.`;
  }
  if (control.hasError('email')) {
    return $localize`:Form validation message@@form.error.email:Enter a valid email address.`;
  }
  if (control.hasError('minlength')) {
    return $localize`:Form validation message@@form.error.minLength:Use at least 12 characters.`;
  }
  if (control.hasError('passwordMismatch')) {
    return $localize`:Form validation message@@form.error.passwordMismatch:The two passwords do not match.`;
  }
  return $localize`:Form validation message@@form.error.generic:Check this field.`;
}

/**
 * First server-side message for a field, when the API answered
 * `422 VALIDATION_ERROR`. These strings come from Laravel's `lang/{tr,en}` and
 * arrive in the caller's language (see the `Accept-Language` header set by
 * `authInterceptor`) — unlike the top-level `message`, which is developer text
 * and is never rendered.
 */
export function serverFieldError(fieldErrors: FieldErrors | null, field: string): string | undefined {
  const messages = fieldErrors?.[field];
  return messages !== undefined && messages.length > 0 ? messages[0] : undefined;
}
