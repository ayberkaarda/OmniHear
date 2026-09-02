import { TestBed } from '@angular/core/testing';

import { ButtonComponent } from './button.component';

describe('ButtonComponent', () => {
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
});
