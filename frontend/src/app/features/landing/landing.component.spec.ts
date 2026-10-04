import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { LandingComponent } from './landing.component';

describe('LandingComponent', () => {
  let fixture: ComponentFixture<LandingComponent>;
  let element: HTMLElement;

  beforeEach(async () => {
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [LandingComponent],
      providers: [provideRouter([])]
    }).compileComponents();

    fixture = TestBed.createComponent(LandingComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  it('has exactly one h1 and no heading level is skipped', () => {
    const headings = Array.from(element.querySelectorAll('h1, h2, h3')).map((h) =>
      Number.parseInt(h.tagName.substring(1), 10)
    );

    expect(headings.filter((level) => level === 1)).toHaveLength(1);
    expect(headings[0]).toBe(1);
    for (let i = 1; i < headings.length; i++) {
      expect(headings[i] - headings[i - 1]).toBeLessThanOrEqual(1);
    }
  });

  it('carries the skip link as the first focusable element', () => {
    const first = element.querySelector('a') as HTMLAnchorElement;
    expect(first.getAttribute('href')).toBe('#main-content');
    expect(element.querySelector('#main-content')).toBeTruthy();
  });

  it('renders every section the header links to', () => {
    const targets = Array.from(element.querySelectorAll('header nav a')).map((a) => a.getAttribute('href'));
    expect(targets).toEqual(['#sources', '#analysis', '#pricing', '#faq']);
    for (const href of targets) {
      expect(element.querySelector(href as string)).toBeTruthy();
    }
    expect(element.querySelector('footer')).toBeTruthy();
  });

  it('keeps the earlier section fragments working inside their sections', () => {
    const aliases: Record<string, string> = { integrations: 'sources', features: 'analysis' };
    for (const [old, current] of Object.entries(aliases)) {
      const anchor = element.querySelector(`#${old}`) as HTMLElement;
      expect(anchor).toBeTruthy();
      expect(anchor.closest('section')?.id).toBe(current);
      expect(anchor.classList.contains('lp-alias')).toBe(true);
      expect(anchor.getAttribute('aria-hidden')).toBe('true');
      expect(anchor.textContent).toBe('');
    }
  });

  it('scrolls to the fragment of a cold load once the sections have rendered', async () => {
    window.history.replaceState(null, '', '#integrations');
    const scrolled: string[] = [];
    const original = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this.id);
    };
    try {
      const cold = TestBed.createComponent(LandingComponent);
      cold.detectChanges();
      await cold.whenStable();
      expect(scrolled).toEqual(['integrations']);
    } finally {
      Element.prototype.scrollIntoView = original;
      window.history.replaceState(null, '', window.location.pathname);
    }
  });

  it('uses the brand logo component in the header and footer', () => {
    expect(element.querySelector('header app-logo')).toBeTruthy();
    expect(element.querySelector('footer app-logo')).toBeTruthy();
  });

  it('names all six sources', () => {
    const sources = element.querySelector('#sources') as HTMLElement;
    for (const name of ['App Store', 'Google Play', 'Zendesk', 'Trustpilot', 'E-mail', 'Mastodon']) {
      expect(sources.textContent).toContain(name);
    }
  });

  it('marks the hero feed as sample data and puts the marker on exactly one phrase in it', () => {
    const feed = element.querySelector('figure[aria-label]') as HTMLElement;
    expect(feed.textContent).toContain('Sample data');
    expect(feed.querySelectorAll('li')).toHaveLength(5);
    const marked = feed.querySelectorAll('.marker');
    expect(marked).toHaveLength(1);
    expect(marked[0].textContent).toBe('crashes every time');
  });

  it('sets the second hero line on the marker', () => {
    const marker = element.querySelector('h1 .marker') as HTMLElement;
    expect(marker.textContent?.trim()).toBe('One inbox.');
  });

  it('draws a voiceprint whose bars use only the sentiment colours', () => {
    const bars = Array.from(element.querySelectorAll<HTMLElement>('[role="img"] i'));
    expect(bars.length).toBeGreaterThan(60);
    for (const bar of bars) {
      expect(bar.style.background).toMatch(/^var\(--sentiment-(negative|neutral|positive)-fill\)$/);
    }
  });

  it('shows the full reading when no observer drives the steps', () => {
    const analysis = element.querySelector('#analysis') as HTMLElement;
    expect(analysis.querySelectorAll('[data-read-step]')).toHaveLength(5);
    expect(analysis.querySelectorAll('dl .on')).toHaveLength(4);
    expect(analysis.querySelectorAll('blockquote .on')).toHaveLength(3);
    expect(analysis.textContent).toContain('−0.82');
  });

  it('states the free-plan allowance from the spec instead of a made-up number', () => {
    const pricing = element.querySelector('#pricing') as HTMLElement;
    expect(pricing.textContent).toContain('200');
  });

  it('uses one label for the sign-up action and points every instance at registration', () => {
    const registerLinks = Array.from(element.querySelectorAll<HTMLAnchorElement>('a[href="/auth/register"]'));
    expect(registerLinks.length).toBeGreaterThanOrEqual(3);
    const labels = new Set(registerLinks.map((a) => a.textContent?.trim()));
    expect(labels.size).toBe(1);
    expect(element.querySelector('a[href="/auth/login"]')).toBeTruthy();
  });

  it('uses native disclosure elements for the FAQ so it works without JavaScript', () => {
    const faq = element.querySelector('#faq') as HTMLElement;
    const details = faq.querySelectorAll('details');
    expect(details.length).toBe(5);
    for (const item of Array.from(details)) {
      expect(item.querySelector('summary')).toBeTruthy();
    }
  });

  it('labels both navigation landmarks', () => {
    const navs = Array.from(element.querySelectorAll('nav'));
    expect(navs.length).toBeGreaterThanOrEqual(2);
    for (const nav of navs) {
      expect(nav.getAttribute('aria-label')).toBeTruthy();
    }
  });

  it('keeps em and en dashes out of the copy', () => {
    expect(element.textContent).not.toMatch(/[–—]/);
  });
});
