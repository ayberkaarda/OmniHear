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

/* ----------------------------------------------------- two-factor (W10) --- */

/** Six digits — RFC 6238's default, and what every authenticator app shows. */
export const TOTP_CODE_PATTERN = /^[0-9]{6}$/;

/** `xxxx-xxxx`, the shape the API hands out at enrolment. */
export const RECOVERY_CODE_PATTERN = /^[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}$/;

/**
 * Client-side text for a two-factor field.
 *
 * Separate from `controlErrorMessage` because a mistyped code is the one
 * validation failure a user is most likely to hit while locked out, and
 * "Check this field." — what a bare `pattern` error would produce there — says
 * nothing about what is wrong. Shared by the login step and the settings
 * section so both name the same rule the same way.
 */
export function twoFactorControlMessage(
  control: AbstractControl | null | undefined,
  kind: 'code' | 'recovery_code'
): string | undefined {
  if (control === null || control === undefined || !control.touched || control.valid) {
    return undefined;
  }
  if (control.hasError('required')) {
    return $localize`:Form validation message@@form.error.required:This field is required.`;
  }
  return kind === 'code'
    ? $localize`:Two-factor validation message@@auth.twoFactor.error.codeFormat:Enter the six digits shown in your authenticator app.`
    : $localize`:Two-factor validation message@@auth.twoFactor.error.recoveryFormat:A recovery code looks like xxxx-xxxx.`;
}
