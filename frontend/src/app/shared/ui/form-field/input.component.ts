import { ChangeDetectionStrategy, Component, computed, forwardRef, input, output, signal } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';
import { FormFieldSize, nextFieldId, SIZE_INPUT_CLASSES } from './form-field-base';

export type InputType = 'text' | 'email' | 'password' | 'search' | 'tel' | 'url' | 'number';

@Component({
    selector: 'app-input',
    imports: [IconComponent],
    templateUrl: './input.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush,
    providers: [
        {
            provide: NG_VALUE_ACCESSOR,
            useExisting: forwardRef(() => InputComponent),
            multi: true
        }
    ]
})
export class InputComponent implements ControlValueAccessor {
  readonly label = input.required<string>();
  readonly helper = input<string | undefined>(undefined);
  readonly error = input<string | undefined>(undefined);
  readonly prefixIcon = input<string | undefined>(undefined);
  readonly suffixIcon = input<string | undefined>(undefined);
  readonly size = input<FormFieldSize>('md');
  readonly required = input(false);
  readonly disabled = input(false);
  /** Not part of the mandated API — added so the field can back email/password/etc. inputs. Defaults to 'text'. */
  readonly type = input<InputType>('text');
  /**
   * Browser autofill hint. Added for the two-factor code field, where
   * `one-time-code` is what lets a phone offer the code from the SMS/keychain
   * instead of making the user copy it by hand.
   */
  readonly autocomplete = input<string | undefined>(undefined);
  /** On-screen keyboard hint — `numeric` for the six-digit code field. */
  readonly inputMode = input<string | undefined>(undefined);

  readonly blurred = output<void>();

  protected readonly fieldId = nextFieldId('app-input');
  protected readonly errorId = `${this.fieldId}-error`;
  protected readonly helperId = `${this.fieldId}-helper`;

  protected readonly value = signal<string | null>(null);
  private readonly disabledByForms = signal(false);

  private onChange: (value: string | null) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  writeValue(value: string | null): void {
    this.value.set(value);
  }

  registerOnChange(fn: (value: string | null) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabledByForms.set(isDisabled);
  }

  protected get isDisabled(): boolean {
    return this.disabled() || this.disabledByForms();
  }

  protected describedBy(): string | null {
    if (this.error()) {
      return this.errorId;
    }
    if (this.helper()) {
      return this.helperId;
    }
    return null;
  }

  protected readonly inputClasses = computed(() => {
    const base =
      'w-full rounded-md border bg-[var(--bg-surface)] text-[var(--text-primary)] ' +
      'placeholder:text-[var(--text-muted)] transition-colors ' +
      'focus:outline-none focus:ring-2 focus:ring-[var(--ring-focus)] ' +
      'disabled:opacity-50 disabled:cursor-not-allowed';
    const borderColor = this.error() ? 'border-[var(--sentiment-negative-border)]' : 'border-[var(--border)]';
    const padding = this.prefixIcon() ? 'pl-9' : this.suffixIcon() ? 'pr-9' : '';
    return [base, borderColor, SIZE_INPUT_CLASSES[this.size()], padding].join(' ');
  });

  protected readonly prefixIconName = computed(() => this.prefixIcon() as IconName | undefined);
  protected readonly suffixIconName = computed(() => this.suffixIcon() as IconName | undefined);

  protected onInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    this.value.set(target.value);
    this.onChange(target.value);
  }

  protected handleBlur(): void {
    this.onTouched();
    this.blurred.emit();
  }
}
