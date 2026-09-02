import { ChangeDetectionStrategy, Component, computed, forwardRef, input, output, signal } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';
import { FormFieldSize, nextFieldId, SIZE_INPUT_CLASSES } from './form-field-base';

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

@Component({
  selector: 'app-select',
  standalone: true,
  imports: [IconComponent],
  templateUrl: './select.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => SelectComponent),
      multi: true
    }
  ]
})
export class SelectComponent implements ControlValueAccessor {
  readonly label = input.required<string>();
  readonly helper = input<string | undefined>(undefined);
  readonly error = input<string | undefined>(undefined);
  readonly prefixIcon = input<string | undefined>(undefined);
  readonly suffixIcon = input<string | undefined>(undefined);
  readonly size = input<FormFieldSize>('md');
  readonly required = input(false);
  readonly disabled = input(false);
  /** Not part of the mandated shared API — a select needs its option list. */
  readonly options = input<SelectOption[]>([]);
  readonly placeholder = input<string | undefined>(undefined);

  readonly blurred = output<void>();

  protected readonly fieldId = nextFieldId('app-select');
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

  protected readonly selectClasses = computed(() => {
    const base =
      'w-full appearance-none rounded-md border bg-[var(--bg-surface)] text-[var(--text-primary)] ' +
      'transition-colors pr-9 ' +
      'focus:outline-none focus:ring-2 focus:ring-[var(--ring-focus)] ' +
      'disabled:opacity-50 disabled:cursor-not-allowed';
    const borderColor = this.error() ? 'border-[var(--sentiment-negative-border)]' : 'border-[var(--border)]';
    const padding = this.prefixIcon() ? 'pl-9' : '';
    return [base, borderColor, SIZE_INPUT_CLASSES[this.size()], padding].join(' ');
  });

  protected readonly prefixIconName = computed(() => this.prefixIcon() as IconName | undefined);

  protected onSelectChange(event: Event): void {
    const target = event.target as HTMLSelectElement;
    this.value.set(target.value);
    this.onChange(target.value);
  }

  protected handleBlur(): void {
    this.onTouched();
    this.blurred.emit();
  }
}
