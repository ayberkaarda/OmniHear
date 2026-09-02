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

  it('renders every section the spec asks for', () => {
    for (const id of ['features', 'integrations', 'pricing', 'faq']) {
      expect(element.querySelector(`#${id}`)).toBeTruthy();
    }
    // Closing CTA + footer.
    expect(element.querySelector('footer')).toBeTruthy();
  });

  it('states the free-plan allowance from the spec instead of a made-up number', () => {
    const pricing = element.querySelector('#pricing') as HTMLElement;
    expect(pricing.textContent).toContain('200');
  });

  it('points both primary calls to action at the registration route', () => {
    const registerLinks = Array.from(element.querySelectorAll('a[href="/auth/register"]'));
    expect(registerLinks.length).toBeGreaterThanOrEqual(3);
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
});
