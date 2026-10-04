import { TestBed } from '@angular/core/testing';

import { VoiceprintComponent } from './voiceprint.component';

async function render(inputs: Record<string, unknown>): Promise<HTMLElement> {
  await TestBed.configureTestingModule({ imports: [VoiceprintComponent] }).compileComponents();
  const fixture = TestBed.createComponent(VoiceprintComponent);
  for (const [key, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(key, value);
  }
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture.nativeElement as HTMLElement;
}

describe('VoiceprintComponent', () => {
  it('draws one bar per comment, height from |score|, tone from sentiment', async () => {
    const el = await render({ values: [-0.82, 0.02, 0.6, null] });
    const bars = Array.from(el.querySelectorAll('i'));
    expect(bars.map((b) => b.className)).toEqual(['vp-negative', 'vp-neutral', 'vp-positive', 'vp-pending']);
    expect(bars[0].style.height).toBe('82%');
    // Neutral and unread comments keep a minimum mark.
    expect(bars[1].style.height).toBe('8%');
    expect(bars[3].style.height).toBe('8%');
  });

  it('is decoration without a label and an image with one', async () => {
    const hidden = await render({ values: [0.4] });
    expect(hidden.querySelector('[data-testid="voiceprint"]')?.getAttribute('aria-hidden')).toBe('true');
    TestBed.resetTestingModule();

    const named = await render({ values: [0.4], label: '247 comments, mostly negative' });
    const strip = named.querySelector('[data-testid="voiceprint"]');
    expect(strip?.getAttribute('role')).toBe('img');
    expect(strip?.getAttribute('aria-label')).toBe('247 comments, mostly negative');
    expect(strip?.getAttribute('aria-hidden')).toBeNull();
  });

  it('draws a flat line when nothing has been heard yet', async () => {
    const el = await render({ values: [], height: 24 });
    expect(el.querySelectorAll('i').length).toBe(0);
    expect(el.querySelector('.vp-flat')).toBeTruthy();
    expect((el.querySelector('[data-testid="voiceprint"]') as HTMLElement).style.height).toBe('24px');
  });
});
