import { ComponentFixture, TestBed } from '@angular/core/testing';

import { TrendPoint } from '../../../core/overview/overview.models';
import { SentimentTrendComponent } from './sentiment-trend.component';

const THREE_CONSECUTIVE_DAYS: TrendPoint[] = [
  { date: '2026-09-01', average_sentiment: -0.6, count: 4 },
  { date: '2026-09-02', average_sentiment: 0.0, count: 2 },
  { date: '2026-09-03', average_sentiment: 0.8, count: 5 }
];

describe('SentimentTrendComponent', () => {
  let fixture: ComponentFixture<SentimentTrendComponent>;

  function render(points: TrendPoint[], loading = false): HTMLElement {
    fixture = TestBed.createComponent(SentimentTrendComponent);
    fixture.componentRef.setInput('points', points);
    fixture.componentRef.setInput('loading', loading);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [SentimentTrendComponent] });
  });

  it('says so instead of drawing an empty axis when there is nothing to plot', () => {
    const element = render([]);

    expect(element.querySelector('[data-testid="trend-empty"]')).toBeTruthy();
    expect(element.querySelector('svg')).toBeNull();
  });

  it('shows a skeleton while the aggregate is in flight', () => {
    const element = render([], true);

    expect(element.querySelector('[data-testid="trend-skeleton"]')).toBeTruthy();
    expect(element.querySelector('[data-testid="trend-empty"]')).toBeNull();
  });

  it('draws one mark per observation and a line through them', () => {
    const element = render(THREE_CONSECUTIVE_DAYS);

    const svg = element.querySelector('svg');
    expect(svg).toBeTruthy();
    expect(svg?.getAttribute('role')).toBe('img');
    expect(svg?.getAttribute('aria-label')).toContain('3');

    // One zero baseline plus one tick per point.
    expect(svg?.querySelectorAll('line')).toHaveLength(1 + THREE_CONSECUTIVE_DAYS.length);
    expect(svg?.querySelector('path')?.getAttribute('d')).toMatch(/^M0\.00 .+ L600\.00 /);
  });

  it('places a negative day below the zero baseline and a positive day above it', () => {
    const element = render(THREE_CONSECUTIVE_DAYS);
    const ticks = Array.from(element.querySelectorAll('svg line')).slice(1);
    const y = ticks.map((tick) => Number(tick.getAttribute('y1')));

    // Position, not colour, is what carries the sign — the colour-blind
    // fallback the tokens skill requires of every chart.
    expect(y[0]).toBeGreaterThan(y[1]);
    expect(y[1]).toBeGreaterThan(y[2]);
  });

  it('draws no line for a single observation, because one point is not a trend', () => {
    const element = render([{ date: '2026-09-01', average_sentiment: 0.3, count: 1 }]);

    expect(element.querySelectorAll('svg line')).toHaveLength(2); // baseline + one tick
    expect(element.querySelector('svg path')).toBeNull();
  });

  it('notes the gap when the window skips days with no analysis', () => {
    const withGap = render([
      { date: '2026-09-01', average_sentiment: 0.1, count: 1 },
      { date: '2026-09-05', average_sentiment: 0.2, count: 1 }
    ]);
    expect(withGap.textContent).toContain('Days with no analysed comment');

    const contiguous = render(THREE_CONSECUTIVE_DAYS);
    expect(contiguous.textContent).not.toContain('Days with no analysed comment');
  });

  it('offers the same data as a table, in the order it was drawn', () => {
    const element = render(THREE_CONSECUTIVE_DAYS);

    const toggle = element.querySelector('button');
    expect(toggle?.getAttribute('aria-pressed')).toBe('false');

    toggle?.dispatchEvent(new MouseEvent('click'));
    fixture.detectChanges();

    const table = element.querySelector('[data-testid="trend-table"]');
    expect(table).toBeTruthy();
    expect(table?.querySelectorAll('tbody tr')).toHaveLength(3);
    expect(table?.querySelectorAll('thead th[scope="col"]')).toHaveLength(3);
    expect(table?.textContent).toContain('-0.60');
    expect(table?.textContent).toContain('+0.80');
    expect(element.querySelector('svg')).toBeNull();
  });

  it('sorts an out-of-order series before drawing it', () => {
    const element = render([
      { date: '2026-09-03', average_sentiment: 0.8, count: 5 },
      { date: '2026-09-01', average_sentiment: -0.6, count: 4 }
    ]);

    element.querySelector('button')?.dispatchEvent(new MouseEvent('click'));
    fixture.detectChanges();

    const rows = Array.from(element.querySelectorAll('tbody tr'));
    expect(rows[0].textContent).toContain('-0.60');
    expect(rows[1].textContent).toContain('+0.80');
  });
});
