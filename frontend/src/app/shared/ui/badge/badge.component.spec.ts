import { TestBed } from '@angular/core/testing';

import { BadgeComponent } from './badge.component';

async function render(inputs: Record<string, unknown>): Promise<HTMLElement> {
  await TestBed.configureTestingModule({ imports: [BadgeComponent] }).compileComponents();
  const fixture = TestBed.createComponent(BadgeComponent);
  for (const [key, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(key, value);
  }
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture.nativeElement as HTMLElement;
}

describe('BadgeComponent', () => {
  it('renders a square marker and the text label for a sentiment badge', async () => {
    const root = await render({ kind: 'sentiment', value: 'negative', showIcon: false });
    expect(root.querySelector('[data-part="square"]')).toBeTruthy();
    expect(root.querySelector('svg')).toBeFalsy();
    expect(root.textContent).toContain('Negative');
    const badge = root.querySelector('[data-kind="sentiment"]');
    expect(badge?.getAttribute('data-value')).toBe('negative');
  });

  it('renders a square marker and the text label for a category badge', async () => {
    const root = await render({ kind: 'category', value: 'bug' });
    expect(root.querySelector('[data-part="square"]')).toBeTruthy();
    expect(root.textContent).toContain('Bug');
  });

  it('shows the status glyph by default and hides it when showIcon=false', async () => {
    const withIcon = await render({ kind: 'status', value: 'paused' });
    expect(withIcon.querySelector('svg')).toBeTruthy();
    expect(withIcon.querySelector('[data-part="square"]')).toBeFalsy();
    TestBed.resetTestingModule();

    const root = await render({ kind: 'status', value: 'paused', showIcon: false });
    expect(root.querySelector('svg')).toBeFalsy();
    expect(root.textContent).toContain('Paused');
  });

  it('formats the sentiment score in tabular figures with a real minus sign', async () => {
    const positive = await render({ kind: 'sentiment', value: 'positive', score: 0.823 });
    expect(positive.querySelector('.tabular-nums')?.textContent?.trim()).toBe('+0.82');
    TestBed.resetTestingModule();

    const negative = await render({ kind: 'sentiment', value: 'negative', score: -0.47 });
    expect(negative.querySelector('.tabular-nums')?.textContent?.trim()).toBe('−0.47');
  });

  it('renders the raw value as a stamp for a source badge', async () => {
    const root = await render({ kind: 'source', value: 'Zendesk' });
    expect(root.textContent).toContain('Zendesk');
    expect(root.querySelector('[data-part="square"]')).toBeFalsy();
  });
});
