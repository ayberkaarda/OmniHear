import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  input,
  output,
  viewChild
} from '@angular/core';

import { ButtonComponent } from '../button/button.component';
import { IconComponent } from '../icon/icon.component';
import { trapTabKey } from './focus-trap';

export type ModalSize = 'sm' | 'md' | 'lg';
export type ModalRole = 'dialog' | 'alertdialog';
export type ModalCloseReason = 'esc' | 'backdrop' | 'button';

const SIZE_CLASSES: Record<ModalSize, string> = {
  sm: 'w-[400px] max-w-[calc(100vw-2rem)]',
  md: 'w-[520px] max-w-[calc(100vw-2rem)]',
  lg: 'w-[720px] max-w-[calc(100vw-2rem)]'
};

let uniqueModalId = 0;

@Component({
    selector: 'app-modal',
    imports: [ButtonComponent, IconComponent],
    templateUrl: './modal.component.html',
    styleUrl: './modal.component.scss',

    changeDetection: ChangeDetectionStrategy.OnPush
})
export class ModalComponent {
  readonly open = input(false);
  readonly title = input.required<string>();
  readonly size = input<ModalSize>('md');
  readonly dismissible = input(true);
  readonly role = input<ModalRole>('dialog');

  readonly closed = output<ModalCloseReason>();

  private readonly dialogEl = viewChild<ElementRef<HTMLElement>>('dialogEl');
  private readonly titleEl = viewChild<ElementRef<HTMLElement>>('titleEl');

  protected readonly titleId = `app-modal-title-${++uniqueModalId}`;
  protected readonly sizeClasses = computed(() => SIZE_CLASSES[this.size()]);

  private previouslyFocused: HTMLElement | null = null;

  constructor() {
    effect(() => {
      if (this.open()) {
        this.previouslyFocused = (document.activeElement as HTMLElement | null) ?? null;
        queueMicrotask(() => this.titleEl()?.nativeElement.focus());
      } else if (this.previouslyFocused) {
        this.previouslyFocused.focus();
        this.previouslyFocused = null;
      }
    });
  }

  protected onBackdropClick(): void {
    if (this.dismissible()) {
      this.close('backdrop');
    }
  }

  protected onCloseButtonClick(): void {
    this.close('button');
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      if (this.dismissible()) {
        event.stopPropagation();
        this.close('esc');
      }
      return;
    }
    if (event.key === 'Tab') {
      this.trapFocus(event);
    }
  }

  private close(reason: ModalCloseReason): void {
    this.closed.emit(reason);
  }

  private trapFocus(event: KeyboardEvent): void {
    const container = this.dialogEl()?.nativeElement;
    if (container) {
      trapTabKey(container, event);
    }
  }
}
