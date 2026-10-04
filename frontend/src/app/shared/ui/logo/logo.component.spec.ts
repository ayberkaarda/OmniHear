import { TestBed } from '@angular/core/testing';

import { LogoComponent } from './logo.component';

async function render(inputs: Record<string, unknown> = {}): Promise<HTMLElement> {
  await TestBed.configureTestingModule({ imports: [LogoComponent] }).compileComponents();
  const fixture = TestBed.createComponent(LogoComponent);
  for (const [key, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(key, value);
  }
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture.nativeElement as HTMLElement;
}

describe('LogoComponent', () => {
  it('renders the full lockup by default with an accessible name', async () => {
    const el = await render();
    const svg = el.querySelector('svg');
    expect(svg?.getAttribute('role')).toBe('img');
    expect(svg?.getAttribute('aria-label')).toBe('OmniHear');
    expect(svg?.getAttribute('viewBox')).toBe('0 0 145.84 32');
    expect(svg?.querySelector('[data-part="stroke"]')?.getAttribute('d')).toBe('M5 6H31L27 26H1Z');
    expect(svg?.querySelector('[data-part="ring"]')).not.toBeNull();
    expect(svg?.querySelector('[data-part="wordmark"]')?.getAttribute('transform')).toBe('translate(42 0)');
  });

  it('renders only the mark for variant="mark" and scales width with size', async () => {
    const el = await render({ variant: 'mark', size: 40 });
    const svg = el.querySelector('svg');
    expect(svg?.getAttribute('viewBox')).toBe('0 0 32 32');
    expect(svg?.getAttribute('width')).toBe('40');
    expect(svg?.getAttribute('height')).toBe('40');
    expect(svg?.querySelector('[data-part="wordmark"]')).toBeNull();
    expect(svg?.querySelectorAll('path, circle').length).toBe(2);
  });

  it('paints from theme tokens, never from hard-coded colours', async () => {
    const el = await render({ size: 24 });
    const stroke = el.querySelector('[data-part="stroke"]')?.getAttribute('style') ?? '';
    const ring = el.querySelector('[data-part="ring"]')?.getAttribute('style') ?? '';
    const word = el.querySelector('[data-part="wordmark"]')?.getAttribute('style') ?? '';
    expect(stroke).toContain('var(--signal');
    expect(ring).toContain('var(--brand-on');
    expect(word).toContain('var(--text-primary');
    expect(el.querySelector('svg')?.getAttribute('width')).toBe('109');
    expect(el.innerHTML).not.toMatch(/#[0-9a-f]{6}/i);
  });
});
