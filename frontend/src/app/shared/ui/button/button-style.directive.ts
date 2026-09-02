import { computed, Directive, input } from '@angular/core';

import { ButtonSize, buttonClasses, ButtonVariant } from './button.styles';

/**
 * Gives an anchor (or any element that is already interactive on its own) the
 * visual treatment of `app-button`, without wrapping a real `<button>` inside
 * it.
 */
@Directive({
  selector: '[appButtonStyle]',
  standalone: true,
  host: {
    '[class]': 'classes()'
  }
})
export class ButtonStyleDirective {
  readonly variant = input<ButtonVariant>('primary');
  readonly size = input<ButtonSize>('md');

  protected readonly classes = computed(() => buttonClasses(this.variant(), this.size()));
}
