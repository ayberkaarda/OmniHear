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
 */

export type FormFieldSize = 'sm' | 'md' | 'lg';

export const SIZE_INPUT_CLASSES: Record<FormFieldSize, string> = {
  sm: 'h-8 text-xs px-2.5',
  md: 'h-9 text-sm px-3',
  lg: 'h-11 text-base px-3.5'
};

let uniqueFieldId = 0;

export function nextFieldId(prefix: string): string {
  return `${prefix}-${++uniqueFieldId}`;
}
