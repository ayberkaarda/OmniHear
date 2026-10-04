import { ChangeDetectionStrategy, Component, computed, input, ViewEncapsulation } from '@angular/core';

import { ButtonSize, buttonClasses, ButtonVariant } from './button.styles';

/**
 * Gives an anchor (or any element that is already interactive on its own) the
 * visual treatment of `app-button`, without wrapping a real `<button>` inside
 * it.
 *
 * Declared as an attribute component (its template only projects the host's
 * own content) rather than a bare directive, so it can carry `button.scss`:
 * the button rules then load with the first button on a page instead of living
 * in the global stylesheet. Use it on native elements (`a`, `button`) only.
 */
@Component({
  // An attribute selector on purpose: it styles native anchors and buttons in place.
  // eslint-disable-next-line @angular-eslint/component-selector
  selector: '[appButtonStyle]',
  standalone: true,
  template: '<ng-content />',
  styleUrl: './button.scss',
  encapsulation: ViewEncapsulation.None,
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '[class]': 'classes()'
  }
})
export class ButtonStyleDirective {
  readonly variant = input<ButtonVariant>('primary');
  readonly size = input<ButtonSize>('md');

  protected readonly classes = computed(() => buttonClasses(this.variant(), this.size()));
}
