import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { ButtonComponent } from './button.component';

@Component({
  imports: [ButtonComponent],
  template: `<app-button [loading]="busy()">Sign in</app-button>`
})
class LabelledHostComponent {
  readonly busy = signal(false);
}

describe('ButtonComponent', () => {
  it('keeps its projected label as the accessible name while loading', async () => {
    await TestBed.configureTestingModule({ imports: [LabelledHostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(LabelledHostComponent);
    fixture.componentInstance.busy.set(true);
    fixture.detectChanges();
    await fixture.whenStable();

    const button = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    const label = button.querySelector('[data-testid="button-label"]') as HTMLElement;
    const spinner = button.querySelector('[data-testid="button-spinner"]') as HTMLElement;

    // visibility:hidden / display:none / aria-hidden would drop the label from
    // the accessibility tree and leave a nameless busy button.
    expect(label.classList.contains('invisible')).toBe(false);
    expect(label.classList.contains('hidden')).toBe(false);
    expect(label.closest('[aria-hidden="true"]')).toBeNull();
    expect(label.textContent?.trim()).toBe('Sign in');
    expect(button.getAttribute('aria-label')).toBeNull();
    expect(button.getAttribute('aria-busy')).toBe('true');
    // The spinner is decoration only and adds nothing to the name.
    expect(spinner.getAttribute('aria-hidden')).toBe('true');
  });

  it('sets aria-busy and blocks clicks while loading', async () => {
    await TestBed.configureTestingModule({
      imports: [ButtonComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(ButtonComponent);
    fixture.componentRef.setInput('loading', true);
    fixture.detectChanges();
    await fixture.whenStable();

    let emitted = false;
    fixture.componentInstance.pressed.subscribe(() => {
      emitted = true;
    });

    const button = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    expect(button.getAttribute('aria-busy')).toBe('true');
    expect(button.disabled).toBe(true);

    button.click();
    await fixture.whenStable();

    expect(emitted).toBe(false);
  });

  it('emits pressed on click when enabled', async () => {
    await TestBed.configureTestingModule({
      imports: [ButtonComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(ButtonComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    let emitted = false;
    fixture.componentInstance.pressed.subscribe(() => {
      emitted = true;
    });

    const button = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    button.click();
    await fixture.whenStable();

    expect(emitted).toBe(true);
  });

  it('gives iconOnly buttons an accessible name from ariaLabel', async () => {
    await TestBed.configureTestingModule({
      imports: [ButtonComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(ButtonComponent);
    fixture.componentRef.setInput('iconOnly', true);
    fixture.componentRef.setInput('ariaLabel', 'Close');
    fixture.detectChanges();
    await fixture.whenStable();

    const button = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    expect(button.getAttribute('aria-label')).toBe('Close');
  });

  it('warns in the console when iconOnly is set without an ariaLabel', async () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => undefined);

    await TestBed.configureTestingModule({
      imports: [ButtonComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(ButtonComponent);
    fixture.componentRef.setInput('iconOnly', true);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(warnSpy).toHaveBeenCalled();
    warnSpy.mockRestore();
  });

  it('keeps the label in the layout while loading and does not paint the disabled look', async () => {
    await TestBed.configureTestingModule({ imports: [ButtonComponent] }).compileComponents();
    const fixture = TestBed.createComponent(ButtonComponent);
    fixture.componentRef.setInput('loading', true);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const button = root.querySelector('button') as HTMLButtonElement;
    expect(root.querySelector('[data-testid="button-spinner"]')).toBeTruthy();
    expect(root.querySelector('button > span[data-testid="button-label"].opacity-0')).toBeTruthy();
    expect(button.classList.contains('ui-btn--busy')).toBe(true);
    expect(button.classList.contains('ui-btn--off')).toBe(false);
    expect(button.classList.contains('ui-btn--primary')).toBe(true);
  });

  it('applies the sunken disabled look when disabled and not loading', async () => {
    await TestBed.configureTestingModule({ imports: [ButtonComponent] }).compileComponents();
    const fixture = TestBed.createComponent(ButtonComponent);
    fixture.componentRef.setInput('disabled', true);
    fixture.detectChanges();
    await fixture.whenStable();

    const button = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.classList.contains('ui-btn--off')).toBe(true);
    expect(button.classList.contains('ui-btn--busy')).toBe(false);
  });

  it('maps variant and size to the shared ui-btn classes, square for iconOnly', async () => {
    await TestBed.configureTestingModule({ imports: [ButtonComponent] }).compileComponents();
    const fixture = TestBed.createComponent(ButtonComponent);
    fixture.componentRef.setInput('variant', 'secondary');
    fixture.componentRef.setInput('size', 'lg');
    fixture.componentRef.setInput('iconOnly', true);
    fixture.componentRef.setInput('ariaLabel', 'Close');
    fixture.detectChanges();
    await fixture.whenStable();

    const button = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    expect(Array.from(button.classList)).toEqual(
      expect.arrayContaining(['ui-btn', 'ui-btn--secondary', 'ui-btn--lg', 'ui-btn--square'])
    );
  });
});
