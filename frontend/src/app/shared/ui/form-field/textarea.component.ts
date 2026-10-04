import { ChangeDetectionStrategy, Component, computed, forwardRef, input, output, signal } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { IconComponent } from '../icon/icon.component';
import {
  controlClasses,
  ERROR_CLASSES,
  FormFieldSize,
  HELPER_CLASSES,
  LABEL_CLASSES,
  nextFieldId,
  SIZE_INPUT_CLASSES
} from './form-field-base';

@Component({
  selector: 'app-textarea',
  standalone: true,
  imports: [IconComponent],
  templateUrl: './textarea.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => TextareaComponent),
      multi: true
    }
  ]
})
export class TextareaComponent implements ControlValueAccessor {
  readonly label = input.required<string>();
  readonly helper = input<string | undefined>(undefined);
  readonly error = input<string | undefined>(undefined);
  readonly prefixIcon = input<string | undefined>(undefined);
  readonly suffixIcon = input<string | undefined>(undefined);
  readonly size = input<FormFieldSize>('md');
  readonly required = input(false);
  readonly disabled = input(false);
  /** Not part of the mandated API — a sensible default for a multi-line field. */
  readonly rows = input(4);

  readonly blurred = output<void>();

  protected readonly labelClasses = LABEL_CLASSES;
  protected readonly helperClasses = HELPER_CLASSES;
  protected readonly errorClasses = ERROR_CLASSES;

  protected readonly fieldId = nextFieldId('app-textarea');
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

  protected readonly textareaClasses = computed(() => {
    // Text sizing only: the fixed input heights don't apply to a multi-row textarea.
    const textSize = SIZE_INPUT_CLASSES[this.size()].replace(/\bh-\d+\b/, '').trim();
    return [controlClasses(!!this.error()), 'min-h-[5rem] resize-y py-2 leading-6', textSize].join(' ');
  });

  protected onInput(event: Event): void {
    const target = event.target as HTMLTextAreaElement;
    this.value.set(target.value);
    this.onChange(target.value);
  }

  protected handleBlur(): void {
    this.onTouched();
    this.blurred.emit();
  }
}
