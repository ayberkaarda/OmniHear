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

/**
 * Control chrome shared by all three fields (docs/BRAND.md):
 * - resting edge is `border-strong` (3:1 against the surface, WCAG 1.4.11);
 * - keyboard and pointer focus draw a 2 px halo in the signal colour;
 * - error swaps the edge and the focus halo to the negative fill;
 * - disabled sinks into the page instead of fading, so text stays legible.
 */
const CONTROL_BASE_CLASSES =
  'w-full rounded-control border bg-[var(--bg-surface)] text-[var(--text-primary)] ' +
  'placeholder:text-[var(--text-muted)] ' +
  'transition-[color,background-color,border-color,box-shadow,transform] duration-fast ease-standard ' +
  'focus:outline-none focus-visible:outline-none ' +
  'disabled:cursor-not-allowed disabled:border-[var(--border)] disabled:bg-[var(--bg-surface-sunken)] disabled:text-[var(--text-disabled)]';

const CONTROL_TONE_CLASSES = {
  rest: 'border-[var(--border-strong)] hover:enabled:border-[var(--text-muted)] focus:ring-2 focus:ring-[var(--ring-focus)]',
  error: 'border-[var(--sentiment-negative-fill)] focus:ring-2 focus:ring-[var(--sentiment-negative-fill)]'
} as const;

export function controlClasses(hasError: boolean): string {
  return `${CONTROL_BASE_CLASSES} ${hasError ? CONTROL_TONE_CLASSES.error : CONTROL_TONE_CLASSES.rest}`;
}

export const LABEL_CLASSES = 'text-sm font-medium leading-5 text-[var(--text-primary)]';
export const HELPER_CLASSES = 'text-xs leading-4 text-[var(--text-muted)]';
export const ERROR_CLASSES = 'flex items-start gap-1 text-xs font-medium leading-4 text-[var(--status-error)]';

let uniqueFieldId = 0;

export function nextFieldId(prefix: string): string {
  return `${prefix}-${++uniqueFieldId}`;
}
