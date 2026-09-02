import { ChangeDetectionStrategy, Component, computed, effect, input, output } from '@angular/core';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';
export type ButtonSize = 'sm' | 'md' | 'lg';
export type ButtonType = 'button' | 'submit';

const BASE_CLASSES =
  'inline-flex items-center justify-center font-medium rounded-md transition-colors ' +
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring-focus)] focus-visible:ring-offset-2 ' +
  'disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none';

const VARIANT_CLASSES: Record<ButtonVariant, string> = {
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

const SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'h-8 px-2.5 text-xs gap-1.5',
  md: 'h-9 px-3.5 text-sm gap-2',
  lg: 'h-11 px-5 text-base gap-2.5'
};

const ICON_ONLY_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'h-8 w-8 p-0',
  md: 'h-9 w-9 p-0',
  lg: 'h-11 w-11 p-0'
};

const SPINNER_SIZE_CLASSES: Record<ButtonSize, string> = {
  sm: 'w-3.5 h-3.5',
  md: 'w-4 h-4',
  lg: 'w-5 h-5'
};

@Component({
  selector: 'app-button',
  standalone: true,
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
    const sizeClasses = this.iconOnly() ? ICON_ONLY_SIZE_CLASSES[this.size()] : SIZE_CLASSES[this.size()];
    return [BASE_CLASSES, VARIANT_CLASSES[this.variant()], sizeClasses].join(' ');
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
