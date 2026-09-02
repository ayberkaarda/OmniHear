import { TestBed } from '@angular/core/testing';

import { SelectComponent } from './select.component';

describe('SelectComponent', () => {
  it('marks the field invalid and points aria-describedby at the error message', async () => {
    await TestBed.configureTestingModule({
      imports: [SelectComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(SelectComponent);
    fixture.componentRef.setInput('label', 'Source');
    fixture.componentRef.setInput('error', 'Source is required');
    fixture.componentRef.setInput('options', [{ value: 'zendesk', label: 'Zendesk' }]);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const select = root.querySelector('select') as HTMLSelectElement;
    const errorEl = root.querySelector('#' + select.getAttribute('aria-describedby'));

    expect(select.getAttribute('aria-invalid')).toBe('true');
    expect(errorEl?.textContent?.trim()).toBe('Source is required');
  });

  it('associates the label with the select via for/id and renders options', async () => {
    await TestBed.configureTestingModule({
      imports: [SelectComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(SelectComponent);
    fixture.componentRef.setInput('label', 'Source');
    fixture.componentRef.setInput('options', [
      { value: 'zendesk', label: 'Zendesk' },
      { value: 'trustpilot', label: 'Trustpilot' }
    ]);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const label = root.querySelector('label') as HTMLLabelElement;
    const select = root.querySelector('select') as HTMLSelectElement;

    expect(label.getAttribute('for')).toBe(select.id);
    expect(select.querySelectorAll('option').length).toBe(2);
  });

  it('propagates the chosen value through ControlValueAccessor', async () => {
    await TestBed.configureTestingModule({
      imports: [SelectComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(SelectComponent);
    fixture.componentRef.setInput('label', 'Source');
    fixture.componentRef.setInput('options', [
      { value: 'zendesk', label: 'Zendesk' },
      { value: 'trustpilot', label: 'Trustpilot' }
    ]);
    fixture.detectChanges();
    await fixture.whenStable();

    let latest: string | null = null;
    fixture.componentInstance.registerOnChange((value) => (latest = value));

    const select = (fixture.nativeElement as HTMLElement).querySelector('select') as HTMLSelectElement;
    select.value = 'trustpilot';
    select.dispatchEvent(new Event('change'));
    await fixture.whenStable();

    expect(latest).toBe('trustpilot');
  });
});
