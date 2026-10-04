import { ChangeDetectionStrategy, Component, computed, effect, input, output } from '@angular/core';

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

const SPINNER_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'w-3.5 h-3.5',
  md: 'w-4 h-4',
  lg: 'w-[18px] h-[18px]'
};

@Component({
    selector: 'app-button',
    imports: [],
    templateUrl: './button.component.html',
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

  protected readonly spinnerClasses = computed(() => SPINNER_SIZE_CLASSES[this.size()]);

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
