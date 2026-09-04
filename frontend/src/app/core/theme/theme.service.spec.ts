import { TestBed } from '@angular/core/testing';

import { ThemeService } from './theme.service';

interface FakeMediaQueryList {
  matches: boolean;
  media: string;
  addEventListener: jest.Mock;
  removeEventListener: jest.Mock;
  dispatch: (matches: boolean) => void;
}

function installFakeMatchMedia(initialMatches: boolean): FakeMediaQueryList {
  let listener: ((event: MediaQueryListEvent) => void) | null = null;
  const fake: FakeMediaQueryList = {
    matches: initialMatches,
    media: '(prefers-color-scheme: dark)',
    addEventListener: jest.fn((_type: string, cb: (event: MediaQueryListEvent) => void) => {
      listener = cb;
    }),
    removeEventListener: jest.fn(),
    dispatch: (matches: boolean) => {
      fake.matches = matches;
      listener?.({ matches } as MediaQueryListEvent);
    }
  };

  (window as unknown as { matchMedia: (query: string) => MediaQueryList }).matchMedia = jest.fn(
    () => fake as unknown as MediaQueryList
  );

  return fake;
}

describe('ThemeService', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove('dark', 'theme-changing');
  });

  it('defaults to "system" preference when nothing is stored', () => {
    installFakeMatchMedia(false);
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    expect(service.preference()).toBe('system');
  });

  it('reads a previously stored preference from localStorage', () => {
    installFakeMatchMedia(false);
    localStorage.setItem('omnihear.theme', 'dark');
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    expect(service.preference()).toBe('dark');
    expect(service.resolved()).toBe('dark');
  });

  it('ignores an invalid stored value and falls back to "system"', () => {
    installFakeMatchMedia(false);
    localStorage.setItem('omnihear.theme', 'not-a-real-theme');
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    expect(service.preference()).toBe('system');
  });

  it('resolves "system" using the current matchMedia result', () => {
    installFakeMatchMedia(true);
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    expect(service.preference()).toBe('system');
    expect(service.resolved()).toBe('dark');
  });

  it('updates resolved() when the system preference changes live', () => {
    const media = installFakeMatchMedia(false);
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    expect(service.resolved()).toBe('light');

    media.dispatch(true);

    expect(service.resolved()).toBe('dark');
  });

  it('applies the .dark class to <html> when resolved theme is dark', () => {
    installFakeMatchMedia(false);
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    service.setPreference('dark');
    TestBed.flushEffects();

    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('removes the .dark class when resolved theme is light', () => {
    installFakeMatchMedia(false);
    document.documentElement.classList.add('dark');
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    service.setPreference('light');
    TestBed.flushEffects();

    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });

  it('persists an explicit preference change to localStorage', () => {
    installFakeMatchMedia(false);
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    service.setPreference('dark');
    TestBed.flushEffects();

    expect(localStorage.getItem('omnihear.theme')).toBe('dark');
  });

  it('suppresses transitions for the moment the .dark class actually toggles, then removes the marker', async () => {
    installFakeMatchMedia(false);
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);

    service.setPreference('dark');
    TestBed.flushEffects();

    // Synchronously, right after the toggle: class flipped, transitions
    // suppressed. This is the window that used to render a light-background
    // KPI card under already-dark text.
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(document.documentElement.classList.contains('theme-changing')).toBe(true);

    // Two animation frames later, the marker is gone and .dark is untouched.
    await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));

    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(document.documentElement.classList.contains('theme-changing')).toBe(false);
  });

  it('does not mark a no-op preference change (already-resolved theme) as transitioning', () => {
    installFakeMatchMedia(false);
    document.documentElement.classList.add('dark');
    localStorage.setItem('omnihear.theme', 'dark');
    TestBed.configureTestingModule({});
    const service = TestBed.inject(ThemeService);
    TestBed.flushEffects();

    // Constructor ran with the resolved theme already matching the class the
    // FOUC-guard script would have applied — nothing should be suppressed.
    expect(document.documentElement.classList.contains('theme-changing')).toBe(false);

    // Re-selecting the same preference the service already resolved to is
    // also a no-op: no class flip, so nothing to suppress.
    service.setPreference('dark');
    TestBed.flushEffects();
    expect(document.documentElement.classList.contains('theme-changing')).toBe(false);
  });

  it('does not throw when localStorage access throws (private-mode fallback)', () => {
    installFakeMatchMedia(false);
    const original = Object.getOwnPropertyDescriptor(window, 'localStorage');
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      get() {
        throw new Error('localStorage disabled');
      }
    });

    try {
      expect(() => {
        TestBed.configureTestingModule({});
        const service = TestBed.inject(ThemeService);
        service.setPreference('dark');
        TestBed.flushEffects();
      }).not.toThrow();
    } finally {
      if (original) {
        Object.defineProperty(window, 'localStorage', original);
      }
    }
  });
});
