import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { EmptyStateComponent } from './empty-state.component';

@Component({
  imports: [EmptyStateComponent],
  template: `
    <app-empty-state icon="inbox" heading="No comments yet" description="Connect a channel to start reading.">
      <button type="button">Connect a channel</button>
    </app-empty-state>
  `
})
class HostComponent {}

describe('EmptyStateComponent', () => {
  it('renders the heading, description, glyph and projected actions', async () => {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = (fixture.nativeElement as HTMLElement).querySelector('[data-testid="empty-state"]') as HTMLElement;
    expect(root.querySelector('.es-title')?.textContent?.trim()).toBe('No comments yet');
    expect(root.querySelector('.es-desc')?.textContent?.trim()).toBe('Connect a channel to start reading.');
    expect(root.querySelector('.es-mark')?.getAttribute('aria-hidden')).toBe('true');
    expect(root.querySelector('.es-mark svg')).toBeTruthy();
    expect(root.querySelector('.es-actions button')?.textContent?.trim()).toBe('Connect a channel');
  });

  it('omits the description when none is given', async () => {
    await TestBed.configureTestingModule({ imports: [EmptyStateComponent] }).compileComponents();
    const fixture = TestBed.createComponent(EmptyStateComponent);
    fixture.componentRef.setInput('heading', 'Nothing here');
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('.es-desc')).toBeNull();
    expect(root.textContent).toContain('Nothing here');
  });
});
