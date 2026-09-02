/**
 * Class maps shared by `app-button` and `[appButtonStyle]`.
 *
 * Extracted so an anchor can look like a button without nesting a `<button>`
 * inside an `<a>` — that produces invalid HTML and two focus stops for one
 * control. Single source of truth: `app-button` imports these too.
 */

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';
export type ButtonSize = 'sm' | 'md' | 'lg';

export const BUTTON_BASE_CLASSES =
  'inline-flex items-center justify-center font-medium rounded-md transition-colors ' +
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring-focus)] focus-visible:ring-offset-2 ' +
  'disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none';

export const BUTTON_VARIANT_CLASSES: Record<ButtonVariant, string> = {
  primary:
    'bg-[var(--brand)] text-[var(--brand-on)] border border-transparent ' +
    'hover:bg-[var(--brand-hover)] active:bg-[var(--brand-active)]',
  secondary:
    'bg-[var(--bg-surface)] text-[var(--text-primary)] border border-[var(--border)] ' +
    'hover:bg-[var(--bg-surface-hover)] active:bg-[var(--bg-surface-selected)]',
  ghost:
    'bg-transparent text-[var(--text-primary)] border border-transparent ' +
    'hover:bg-[var(--bg-surface-hover)] active:bg-[var(--bg-surface-selected)]',
  destructive:
    'bg-[var(--sentiment-negative-fill)] text-[var(--text-inverse)] border border-[var(--sentiment-negative-border)] ' +
    'hover:opacity-90 active:opacity-80'
};

export const BUTTON_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'h-8 px-2.5 text-xs gap-1.5',
  md: 'h-9 px-3.5 text-sm gap-2',
  lg: 'h-11 px-5 text-base gap-2.5'
};

export const BUTTON_ICON_ONLY_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'h-8 w-8 p-0',
  md: 'h-9 w-9 p-0',
  lg: 'h-11 w-11 p-0'
};

export function buttonClasses(variant: ButtonVariant, size: ButtonSize): string {
  return [BUTTON_BASE_CLASSES, BUTTON_VARIANT_CLASSES[variant], BUTTON_SIZE_CLASSES[size]].join(' ');
}
