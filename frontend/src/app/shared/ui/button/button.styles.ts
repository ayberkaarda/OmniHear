/**
 * Class maps shared by `app-button` and `[appButtonStyle]`.
 *
 * Extracted so an anchor can look like a button without nesting a `<button>`
 * inside an `<a>`: that produces invalid HTML and two focus stops for one
 * control. Single source of truth: `app-button` imports these too.
 *
 * Brand v2 (docs/BRAND.md section 7): primary is the marker, a yellow fill
 * with ink text in both themes; secondary is a 1.5 px ink outline; ghost is
 * text only; destructive is the negative fill. Corners are radius-md (2 px),
 * never a pill. Keyboard focus is a 2 px ring-focus ring with a 2 px offset;
 * pressed controls move 1 px down and nothing scales.
 *
 * The rules live in `button.scss`, attached without view encapsulation by both
 * `app-button` and `[appButtonStyle]`, so they load with the first button on a
 * page instead of growing the global (initial) stylesheet.
 */

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';
export type ButtonSize = 'sm' | 'md' | 'lg';

export const BUTTON_BASE_CLASSES = 'ui-btn';

export const BUTTON_VARIANT_CLASSES: Record<ButtonVariant, string> = {
  primary: 'ui-btn--primary',
  secondary: 'ui-btn--secondary',
  ghost: 'ui-btn--ghost',
  destructive: 'ui-btn--destructive'
};

/**
 * Disabled look, applied by `app-button` only when the control is disabled and
 * not loading. A loading button is also `disabled` (no double submit) but keeps
 * its variant colours so the progress mark reads as work, not as unavailable.
 */
export const BUTTON_DISABLED_CLASSES: Record<ButtonVariant, string> = {
  primary: 'ui-btn--off',
  secondary: 'ui-btn--off',
  ghost: 'ui-btn--off',
  destructive: 'ui-btn--off'
};

export const BUTTON_LOADING_CLASSES = 'ui-btn--busy';

export const BUTTON_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'ui-btn--sm',
  md: 'ui-btn--md',
  lg: 'ui-btn--lg'
};

export const BUTTON_ICON_ONLY_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'ui-btn--sm ui-btn--square',
  md: 'ui-btn--md ui-btn--square',
  lg: 'ui-btn--lg ui-btn--square'
};

export function buttonClasses(variant: ButtonVariant, size: ButtonSize): string {
  return [BUTTON_BASE_CLASSES, BUTTON_VARIANT_CLASSES[variant], BUTTON_SIZE_CLASSES[size]].join(' ');
}
