import { TestBed } from '@angular/core/testing';

import { IconComponent } from './icon.component';

describe('IconComponent', () => {
  it('renders an aria-hidden svg with the requested glyph shapes', async () => {
    await TestBed.configureTestingModule({
      imports: [IconComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(IconComponent);
    fixture.componentRef.setInput('name', 'smile');
    fixture.detectChanges();
    await fixture.whenStable();

    const svg = (fixture.nativeElement as HTMLElement).querySelector('svg');
    expect(svg).toBeTruthy();
    expect(svg?.getAttribute('aria-hidden')).toBe('true');
    expect(svg?.getAttribute('stroke')).toBe('currentColor');
    expect(svg?.querySelectorAll('circle, path, line, polyline, rect').length).toBeGreaterThan(0);
  });

  it('swaps the rendered glyph when the icon name changes', async () => {
    await TestBed.configureTestingModule({
      imports: [IconComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(IconComponent);
    fixture.componentRef.setInput('name', 'x');
    fixture.detectChanges();
    await fixture.whenStable();

    const linesForX = (fixture.nativeElement as HTMLElement).querySelectorAll('line').length;
    expect(linesForX).toBe(2);

    fixture.componentRef.setInput('name', 'check');
    fixture.detectChanges();
    await fixture.whenStable();

    expect((fixture.nativeElement as HTMLElement).querySelectorAll('polyline').length).toBe(1);
  });
});
