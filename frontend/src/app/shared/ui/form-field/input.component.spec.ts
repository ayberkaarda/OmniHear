import { TestBed } from '@angular/core/testing';

import { InputComponent } from './input.component';

describe('InputComponent', () => {
  it('marks the field invalid and points aria-describedby at the error message', async () => {
    await TestBed.configureTestingModule({
      imports: [InputComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(InputComponent);
    fixture.componentRef.setInput('label', 'Email');
    fixture.componentRef.setInput('error', 'Email is required');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const input = root.querySelector('input') as HTMLInputElement;
    const errorEl = root.querySelector('#' + input.getAttribute('aria-describedby'));

    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(errorEl?.textContent?.trim()).toBe('Email is required');
  });

  it('associates the label with the input via for/id', async () => {
    await TestBed.configureTestingModule({
      imports: [InputComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(InputComponent);
    fixture.componentRef.setInput('label', 'Email');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const label = root.querySelector('label') as HTMLLabelElement;
    const input = root.querySelector('input') as HTMLInputElement;

    expect(label.getAttribute('for')).toBe(input.id);
    expect(input.id).toBeTruthy();
  });

  it('hides the helper text once an error is present', async () => {
    await TestBed.configureTestingModule({
      imports: [InputComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(InputComponent);
    fixture.componentRef.setInput('label', 'Email');
    fixture.componentRef.setInput('helper', 'We never share your email');
    fixture.componentRef.setInput('error', 'Email is required');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.textContent).not.toContain('We never share your email');
    expect(root.textContent).toContain('Email is required');
  });

  it('propagates typed values through ControlValueAccessor', async () => {
    await TestBed.configureTestingModule({
      imports: [InputComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(InputComponent);
    fixture.componentRef.setInput('label', 'Email');
    fixture.detectChanges();
    await fixture.whenStable();

    const instance = fixture.componentInstance;
    let latest: string | null = null;
    instance.registerOnChange((value) => (latest = value));

    const input = (fixture.nativeElement as HTMLElement).querySelector('input') as HTMLInputElement;
    input.value = 'hello@omnihear.ai';
    input.dispatchEvent(new Event('input'));
    await fixture.whenStable();

    expect(latest).toBe('hello@omnihear.ai');
  });

  it('emits blurred and touches the control on blur', async () => {
    await TestBed.configureTestingModule({
      imports: [InputComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(InputComponent);
    fixture.componentRef.setInput('label', 'Email');
    fixture.detectChanges();
    await fixture.whenStable();

    let touched = false;
    let blurred = false;
    fixture.componentInstance.registerOnTouched(() => (touched = true));
    fixture.componentInstance.blurred.subscribe(() => (blurred = true));

    const input = (fixture.nativeElement as HTMLElement).querySelector('input') as HTMLInputElement;
    input.dispatchEvent(new Event('blur'));
    await fixture.whenStable();

    expect(touched).toBe(true);
    expect(blurred).toBe(true);
  });
});
