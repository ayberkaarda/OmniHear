import { signal } from '@angular/core';
import { AbstractControl, FormGroup, ValidationErrors, ValidatorFn } from '@angular/forms';

import { FieldErrors } from '../../../core/errors/api-error';
import { controlErrorMessage, serverFieldError } from './form-errors';

/**
 * Shared plumbing for the `/auth/*` forms.
 *
 * Only plain (non-signal) members live here: this codebase already established
 * that signal `input()`/`output()` declared on an undecorated base class are not
 * picked up by the compiled component (see `shared/ui/form-field/form-field-base.ts`).
 *
 * `formTick` exists because `ReactiveFormsModule` is observable-based, not
 * signal-based. Reading it inside `errorFor()` — which the template calls —
 * registers it as a dependency of the view, so bumping it on blur or on a value
 * change re-renders the field errors under zoneless change detection.
 */
export abstract class AuthFormBase {
  protected readonly submitting = signal(false);
  protected readonly serverErrors = signal<FieldErrors | null>(null);
  protected readonly formTick = signal(0);

  protected abstract readonly form: FormGroup;

  protected onFieldBlur(): void {
    this.formTick.update((value) => value + 1);
  }

  /** Server-side message wins: it knows things the client validators cannot. */
  protected errorFor(field: string): string | undefined {
    this.formTick();
    return serverFieldError(this.serverErrors(), field) ?? controlErrorMessage(this.form.get(field));
  }

  protected markAllTouched(): void {
    this.form.markAllAsTouched();
    this.onFieldBlur();
  }
}

/** Cross-field check for the "password" / "password_confirmation" pair. */
export function passwordsMatchValidator(passwordKey: string, confirmationKey: string): ValidatorFn {
  return (group: AbstractControl): ValidationErrors | null => {
    const password = group.get(passwordKey)?.value;
    const confirmation = group.get(confirmationKey)?.value;
    const confirmationControl = group.get(confirmationKey);

    if (confirmationControl === null || confirmation === '' || confirmation === null) {
      return null;
    }

    if (password !== confirmation) {
      confirmationControl.setErrors({ ...(confirmationControl.errors ?? {}), passwordMismatch: true });
      return { passwordMismatch: true };
    }

    if (confirmationControl.hasError('passwordMismatch')) {
      const remaining: ValidationErrors = { ...(confirmationControl.errors ?? {}) };
      delete remaining['passwordMismatch'];
      confirmationControl.setErrors(Object.keys(remaining).length > 0 ? remaining : null);
    }
    return null;
  };
}
