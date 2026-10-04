/**
 * Where page-wide keyboard shortcuts (`/`, `Ctrl K`, J/K) may fire.
 *
 * A dialog owns the keyboard while it is open: a shortcut that navigates or
 * moves focus behind it would throw away a half-filled form or strand focus
 * on a page the user cannot see. `ModalComponent` reports its open state here;
 * any other modal surface (the app shell's account sheet) is caught by its
 * `aria-modal="true"`, which is only in the DOM while it is shown.
 */
let openModals = 0;

/** Called by `ModalComponent` when it opens; the returned function undoes it, once. */
export function registerOpenModal(): () => void {
  openModals += 1;
  let released = false;
  return () => {
    if (!released) {
      released = true;
      openModals -= 1;
    }
  };
}

/** True while any modal dialog is shown. */
export function isModalOpen(doc: Document = document): boolean {
  return openModals > 0 || doc.querySelector('[aria-modal="true"], dialog[open]') !== null;
}

/** A field that types text or picks a value: a bare key there is input, not a shortcut. */
export function isEditableTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false;
  }
  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * True when a page-wide shortcut must stand down for this keypress: a dialog
 * is open (focus inside one included), or focus is in a field.
 */
export function shortcutBlocked(event: KeyboardEvent): boolean {
  return isModalOpen() || isEditableTarget(event.target);
}
