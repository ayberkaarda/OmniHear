import { ChangeDetectionStrategy, Component, computed, forwardRef, input, output, signal } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { FormFieldSize, nextFieldId, SIZE_INPUT_CLASSES } from './form-field-base';

@Component({
  selector: 'app-textarea',
  standalone: true,
  imports: [],
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
    const base =
      'w-full rounded-md border bg-[var(--bg-surface)] text-[var(--text-primary)] ' +
      'placeholder:text-[var(--text-muted)] transition-colors py-2 ' +
      'focus:outline-none focus:ring-2 focus:ring-[var(--ring-focus)] ' +
      'disabled:opacity-50 disabled:cursor-not-allowed';
    const borderColor = this.error() ? 'border-[var(--sentiment-negative-border)]' : 'border-[var(--border)]';
    // Text sizing only — the fixed input heights don't apply to a multi-row textarea.
    const textSize = SIZE_INPUT_CLASSES[this.size()].replace(/\bh-\d+\b/, '').trim();
    return [base, borderColor, textSize].join(' ');
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
