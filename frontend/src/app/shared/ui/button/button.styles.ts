/**
 * Class maps shared by `app-button` and `[appButtonStyle]`.
 *
 * Extracted so an anchor can look like a button without nesting a `<button>`
 * inside an `<a>`: that produces invalid HTML and two focus stops for one
 * control. Single source of truth: `app-button` imports these too.
 *
 * Brand rules (docs/BRAND.md): primary is ink, never a hue; keyboard focus is
 * drawn in the signal colour (2 px, 2 px offset on the surface behind it);
 * pressed controls move 1 px down and nothing scales; colour changes use
 * `duration-fast` + `ease-standard`.
 *
 * Every class here lands in the global (initial) stylesheet, so the set is
 * kept to utilities other components already generate wherever possible.
 */

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';
export type ButtonSize = 'sm' | 'md' | 'lg';

export const BUTTON_BASE_CLASSES =
  'relative inline-flex select-none items-center justify-center whitespace-nowrap rounded-control font-medium leading-none ' +
  'transition-[color,background-color,border-color,box-shadow,transform] duration-fast ease-standard ' +
  'enabled:active:translate-y-px ' +
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring-focus)] ' +
  'focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--bg-surface)] ' +
  'disabled:cursor-not-allowed';

export const BUTTON_VARIANT_CLASSES: Record<ButtonVariant, string> = {
  primary:
    'border border-transparent bg-[var(--brand)] text-[var(--brand-on)] ' +
    'hover:bg-[var(--brand-hover)] active:bg-[var(--brand-active)]',
  secondary:
    'border border-[var(--border-strong)] bg-[var(--bg-surface)] text-[var(--text-primary)] ' +
    'hover:bg-[var(--bg-surface-hover)] active:bg-[var(--bg-surface-sunken)]',
  ghost:
    'border border-transparent bg-transparent text-[var(--text-secondary)] ' +
    'hover:bg-[var(--bg-surface-hover)] hover:text-[var(--text-primary)] active:bg-[var(--bg-surface-sunken)]',
  destructive:
    'border border-transparent bg-[var(--sentiment-negative-fill)] text-[var(--text-inverse)] ' +
    'hover:opacity-90 active:opacity-80'
};

/**
 * Disabled look, applied by `app-button` only when the control is disabled and
 * not loading. A loading button is also `disabled` (no double submit) but keeps
 * its variant colours so the spinner reads as progress, not as unavailable.
 */
export const BUTTON_DISABLED_CLASSES: Record<ButtonVariant, string> = {
  primary: '!border-transparent !bg-[var(--bg-surface-sunken)] !text-[var(--text-disabled)]',
  secondary: '!border-[var(--border)] !bg-transparent !text-[var(--text-disabled)]',
  ghost: '!bg-transparent !text-[var(--text-disabled)]',
  destructive: '!border-transparent !bg-[var(--bg-surface-sunken)] !text-[var(--text-disabled)] !opacity-100'
};

export const BUTTON_LOADING_CLASSES = 'cursor-progress';

export const BUTTON_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-xs gap-1.5',
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
