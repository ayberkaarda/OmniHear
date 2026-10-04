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
    expect(root.querySelector('.kpi-delta')?.getAttribute('data-tone')).toBe('positive');
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
    expect(root.querySelector('.kpi-delta')?.getAttribute('data-tone')).toBe('negative');
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

  it('sets the figure in mono with a real minus sign and draws the spark as bars', async () => {
    await TestBed.configureTestingModule({
      imports: [KpiCardComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(KpiCardComponent);
    fixture.componentRef.setInput('title', 'Average sentiment');
    fixture.componentRef.setInput('value', -0.42);
    fixture.componentRef.setInput('format', 'score');
    fixture.componentRef.setInput('delta', -3);
    fixture.componentRef.setInput('spark', [1, 3, 2]);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('.kpi-value')?.textContent?.trim()).toBe('−0.42');
    expect(root.querySelector('.kpi-delta')?.textContent?.trim()).toBe('−3');
    const bars = Array.from(root.querySelectorAll('.kpi-spark i')) as HTMLElement[];
    expect(bars.map((bar) => bar.style.height)).toEqual(['15%', '100%', '58%']);
    expect(bars[2].classList.contains('is-now')).toBe(true);
  });
});
