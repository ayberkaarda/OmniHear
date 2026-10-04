/**
 * Keyboard containment shared by every modal surface: `ModalComponent` and
 * the app shell's phone account sheet. One implementation, so the two cannot
 * drift apart on what counts as focusable or how Tab wraps.
 */
export const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), ' +
  'select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Keeps a Tab / Shift+Tab keypress inside `container`, wrapping from the last
 * focusable element to the first and back. Call from the container's keydown
 * handler for Tab only; it prevents the default move only when it wraps.
 */
export function trapTabKey(container: HTMLElement, event: KeyboardEvent): void {
  const focusable = Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR));
  if (focusable.length === 0) {
    event.preventDefault();
    return;
  }
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  const active = document.activeElement;
  const outside = !container.contains(active);

  if (event.shiftKey && (active === first || active === container || outside)) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && (active === last || outside)) {
    event.preventDefault();
    first.focus();
  }
}
