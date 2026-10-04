/**
 * Shared, non-component helpers for the app-input / app-textarea / app-select
 * trio.
 *
 * NOTE: an earlier version of this file exposed an abstract `FormFieldBase`
 * class that declared the shared `input()`/`output()` members once for the
 * three components to extend. That does not work at runtime in this Angular
 * version: signal-based inputs/outputs declared on an undecorated base class
 * are not picked up by the compiled component's input metadata (verified via
 * `ComponentRef.setInput` throwing NG0303 in the component specs). Angular's
 * signal-input inheritance only reliably applies to members declared on the
 * decorated class itself, so each field component now declares its own
 * inputs/outputs directly and only imports the plain (non-signal) pieces
 * below.
 *
 * Brand v2 (docs/BRAND.md section 7): fields are ruled wells on the ground,
 * not boxes. A sunken fill, square corners and a 1.5 px `border-strong` rule
 * along the bottom (3:1 against the ground); focus thickens the rule in
 * `ring-focus`, error turns it `status-error`, disabled breaks it into a dashed
 * hairline. The rules live in `form-field.scss`, shared by the three
 * components, so none of this grows the global stylesheet.
 */

export type FormFieldSize = 'sm' | 'md' | 'lg';

export interface ControlClassOptions {
  size: FormFieldSize;
  hasError: boolean;
  hasPrefix?: boolean;
  hasSuffix?: boolean;
}

export function controlClasses({ size, hasError, hasPrefix, hasSuffix }: ControlClassOptions): string {
  return [
    'ff-control',
    `ff-control--${size}`,
    hasError ? 'ff-control--error' : '',
    hasPrefix ? 'ff-control--prefix' : '',
    hasSuffix ? 'ff-control--suffix' : ''
  ]
    .filter(Boolean)
    .join(' ');
}

let uniqueFieldId = 0;

export function nextFieldId(prefix: string): string {
  return `${prefix}-${++uniqueFieldId}`;
}
