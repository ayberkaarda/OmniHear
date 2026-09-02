import { TestBed } from '@angular/core/testing';

import { TextareaComponent } from './textarea.component';

describe('TextareaComponent', () => {
  it('marks the field invalid and points aria-describedby at the error message', async () => {
    await TestBed.configureTestingModule({
      imports: [TextareaComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(TextareaComponent);
    fixture.componentRef.setInput('label', 'Notes');
    fixture.componentRef.setInput('error', 'Notes are required');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const textarea = root.querySelector('textarea') as HTMLTextAreaElement;
    const errorEl = root.querySelector('#' + textarea.getAttribute('aria-describedby'));

    expect(textarea.getAttribute('aria-invalid')).toBe('true');
    expect(errorEl?.textContent?.trim()).toBe('Notes are required');
  });

  it('associates the label with the textarea via for/id', async () => {
    await TestBed.configureTestingModule({
      imports: [TextareaComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(TextareaComponent);
    fixture.componentRef.setInput('label', 'Notes');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const label = root.querySelector('label') as HTMLLabelElement;
    const textarea = root.querySelector('textarea') as HTMLTextAreaElement;

    expect(label.getAttribute('for')).toBe(textarea.id);
  });

  it('propagates typed values through ControlValueAccessor', async () => {
    await TestBed.configureTestingModule({
      imports: [TextareaComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(TextareaComponent);
    fixture.componentRef.setInput('label', 'Notes');
    fixture.detectChanges();
    await fixture.whenStable();

    let latest: string | null = null;
    fixture.componentInstance.registerOnChange((value) => (latest = value));

    const textarea = (fixture.nativeElement as HTMLElement).querySelector('textarea') as HTMLTextAreaElement;
    textarea.value = 'Customer is happy';
    textarea.dispatchEvent(new Event('input'));
    await fixture.whenStable();

    expect(latest).toBe('Customer is happy');
  });
});
