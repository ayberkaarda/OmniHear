import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { ModalComponent, ModalCloseReason } from './modal.component';

@Component({
  selector: 'app-modal-host',
  standalone: true,
  imports: [ModalComponent],
  template: `
    <button #trigger type="button" (click)="isOpen.set(true)">Open</button>
    <app-modal [open]="isOpen()" title="Delete feedback" [dismissible]="dismissible()" (closed)="onClosed($event)">
      <p modalBody>Are you sure?</p>
      <button modalFooter type="button">Confirm</button>
    </app-modal>
  `
})
class HostComponent {
  readonly isOpen = signal(false);
  readonly dismissible = signal(true);
  readonly lastReason = signal<ModalCloseReason | null>(null);

  onClosed(reason: ModalCloseReason): void {
    this.lastReason.set(reason);
    this.isOpen.set(false);
  }
}

describe('ModalComponent', () => {
  it('focuses the title when it opens', async () => {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    fixture.componentInstance.isOpen.set(true);
    fixture.detectChanges();
    await fixture.whenStable();
    // Allow the queued microtask focus call to run.
    await Promise.resolve();

    const title = (fixture.nativeElement as HTMLElement).querySelector('h2');
    expect(document.activeElement).toBe(title);
  });

  it('emits closed("esc") on Escape and returns focus to the trigger', async () => {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    const trigger = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    trigger.focus();
    fixture.componentInstance.isOpen.set(true);
    fixture.detectChanges();
    await fixture.whenStable();
    await Promise.resolve();

    const overlay = (fixture.nativeElement as HTMLElement).querySelector('[role="dialog"]') as HTMLElement;
    overlay.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    await fixture.whenStable();

    expect(fixture.componentInstance.lastReason()).toBe('esc');
    expect(document.activeElement).toBe(trigger);
  });

  it('does not close on Escape or backdrop click when dismissible is false', async () => {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.componentInstance.dismissible.set(false);
    fixture.componentInstance.isOpen.set(true);
    fixture.detectChanges();
    await fixture.whenStable();
    await Promise.resolve();

    const root = fixture.nativeElement as HTMLElement;
    const overlay = root.querySelector('[role="dialog"]') as HTMLElement;
    overlay.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    const backdrop = root.querySelector('div[aria-hidden="true"].fixed.inset-0') as HTMLElement;
    backdrop.click();

    fixture.detectChanges();
    await fixture.whenStable();

    expect(fixture.componentInstance.lastReason()).toBeNull();
    expect(fixture.componentInstance.isOpen()).toBe(true);
  });
});
