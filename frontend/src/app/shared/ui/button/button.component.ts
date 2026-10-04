import { ChangeDetectionStrategy, Component, computed, effect, input, output, ViewEncapsulation } from '@angular/core';

import {
  BUTTON_BASE_CLASSES,
  BUTTON_DISABLED_CLASSES,
  BUTTON_ICON_ONLY_SIZE_CLASSES,
  BUTTON_LOADING_CLASSES,
  BUTTON_SIZE_CLASSES,
  BUTTON_VARIANT_CLASSES,
  ButtonSize,
  ButtonVariant
} from './button.styles';

export type { ButtonSize, ButtonVariant } from './button.styles';
export type ButtonType = 'button' | 'submit';

/**
 * Brand v2 button (docs/BRAND.md section 7). Styles come from `button.scss`,
 * shared with `[appButtonStyle]` and attached without view encapsulation.
 *
 * While `loading`, the control is disabled and `aria-busy`; the projected label
 * stays in the accessibility tree (painted out with opacity only) so the busy
 * button keeps its name, and a three-bar voiceprint stands in for a spinner.
 */
@Component({
  selector: 'app-button',
  imports: [],
  templateUrl: './button.component.html',
  styleUrl: './button.scss',
  encapsulation: ViewEncapsulation.None,
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ButtonComponent {
  readonly variant = input<ButtonVariant>('primary');
  readonly size = input<ButtonSize>('md');
  readonly loading = input(false);
  readonly disabled = input(false);
  readonly iconOnly = input(false);
  readonly ariaLabel = input<string | undefined>(undefined);
  readonly type = input<ButtonType>('button');

  readonly pressed = output<MouseEvent>();

  protected readonly isDisabled = computed(() => this.disabled() || this.loading());

  protected readonly classes = computed(() => {
    const sizeClasses = this.iconOnly() ? BUTTON_ICON_ONLY_SIZE_CLASSES[this.size()] : BUTTON_SIZE_CLASSES[this.size()];
    const stateClasses = this.loading()
      ? BUTTON_LOADING_CLASSES
      : this.disabled()
        ? BUTTON_DISABLED_CLASSES[this.variant()]
        : '';
    return [BUTTON_BASE_CLASSES, BUTTON_VARIANT_CLASSES[this.variant()], sizeClasses, stateClasses].join(' ').trim();
  });

  constructor() {
    effect(() => {
      if (this.iconOnly() && !this.ariaLabel()) {
        console.warn('[app-button] iconOnly buttons must receive an ariaLabel for accessibility.');
      }
    });
  }

  protected onClick(event: MouseEvent): void {
    if (this.isDisabled()) {
      return;
    }
    this.pressed.emit(event);
  }
}
