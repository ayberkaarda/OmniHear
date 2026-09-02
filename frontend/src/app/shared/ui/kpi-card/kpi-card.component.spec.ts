import { TestBed } from '@angular/core/testing';

import { KpiCardComponent } from './kpi-card.component';

describe('KpiCardComponent', () => {
  it('shows a skeleton and disables selection when value is null', async () => {
    await TestBed.configureTestingModule({
      imports: [KpiCardComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(KpiCardComponent);
    fixture.componentRef.setInput('title', 'Feedback analyzed');
    fixture.componentRef.setInput('value', null);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('[data-testid="kpi-skeleton"]')).toBeTruthy();

    let emitted = false;
    fixture.componentInstance.selected.subscribe(() => (emitted = true));
    (root.querySelector('button') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(emitted).toBe(false);
  });

  it('picks the sentiment-positive tone when an increase is good news', async () => {
    await TestBed.configureTestingModule({
      imports: [KpiCardComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(KpiCardComponent);
    fixture.componentRef.setInput('title', 'Feedback analyzed');
    fixture.componentRef.setInput('value', 128);
    fixture.componentRef.setInput('delta', 12);
    fixture.componentRef.setInput('deltaPolarity', 'up-good');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const deltaContainer = Array.from(root.querySelectorAll('span')).find((el) =>
      el.className.includes('sentiment-positive-text')
    );
    expect(deltaContainer).toBeTruthy();
  });

  it('picks the sentiment-negative tone when an increase is bad news (down-good polarity)', async () => {
    await TestBed.configureTestingModule({
      imports: [KpiCardComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(KpiCardComponent);
    fixture.componentRef.setInput('title', 'Open complaints');
    fixture.componentRef.setInput('value', 42);
    fixture.componentRef.setInput('delta', 5);
    fixture.componentRef.setInput('deltaPolarity', 'down-good');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const deltaContainer = Array.from(root.querySelectorAll('span')).find((el) =>
      el.className.includes('sentiment-negative-text')
    );
    expect(deltaContainer).toBeTruthy();
  });

  it('emits selected on click once loaded', async () => {
    await TestBed.configureTestingModule({
      imports: [KpiCardComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(KpiCardComponent);
    fixture.componentRef.setInput('title', 'Feedback analyzed');
    fixture.componentRef.setInput('value', 128);
    fixture.detectChanges();
    await fixture.whenStable();

    let emitted = false;
    fixture.componentInstance.selected.subscribe(() => (emitted = true));
    ((fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(emitted).toBe(true);
  });
});
