import { TestBed } from '@angular/core/testing';

import { BadgeComponent } from './badge.component';

describe('BadgeComponent', () => {
  it('always renders an icon and the text label for a sentiment badge, even with showIcon=false', async () => {
    await TestBed.configureTestingModule({
      imports: [BadgeComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(BadgeComponent);
    fixture.componentRef.setInput('kind', 'sentiment');
    fixture.componentRef.setInput('value', 'negative');
    fixture.componentRef.setInput('showIcon', false);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('svg')).toBeTruthy();
    expect(root.textContent).toContain('Negative');
  });

  it('always renders an icon and the text label for a category badge, even with showIcon=false', async () => {
    await TestBed.configureTestingModule({
      imports: [BadgeComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(BadgeComponent);
    fixture.componentRef.setInput('kind', 'category');
    fixture.componentRef.setInput('value', 'bug');
    fixture.componentRef.setInput('showIcon', false);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('svg')).toBeTruthy();
    expect(root.textContent).toContain('Bug');
  });

  it('hides the icon for a status badge when showIcon=false', async () => {
    await TestBed.configureTestingModule({
      imports: [BadgeComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(BadgeComponent);
    fixture.componentRef.setInput('kind', 'status');
    fixture.componentRef.setInput('value', 'paused');
    fixture.componentRef.setInput('showIcon', false);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('svg')).toBeFalsy();
    expect(root.textContent).toContain('Paused');
  });

  it('formats the sentiment score with a tabular-nums class', async () => {
    await TestBed.configureTestingModule({
      imports: [BadgeComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(BadgeComponent);
    fixture.componentRef.setInput('kind', 'sentiment');
    fixture.componentRef.setInput('value', 'positive');
    fixture.componentRef.setInput('score', 0.823);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    const scoreEl = root.querySelector('.tabular-nums');
    expect(scoreEl?.textContent?.trim()).toBe('+0.82');
  });

  it('renders the raw value for an unrecognized status value without crashing', async () => {
    await TestBed.configureTestingModule({
      imports: [BadgeComponent]
    }).compileComponents();

    const fixture = TestBed.createComponent(BadgeComponent);
    fixture.componentRef.setInput('kind', 'source');
    fixture.componentRef.setInput('value', 'Zendesk');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.textContent).toContain('Zendesk');
  });
});
