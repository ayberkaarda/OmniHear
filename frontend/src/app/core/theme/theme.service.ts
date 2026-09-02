import { Injectable, computed, effect, signal } from '@angular/core';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

const STORAGE_KEY = 'omnihear.theme';
const DARK_CLASS = 'dark';
const DARK_MEDIA_QUERY = '(prefers-color-scheme: dark)';

function isThemePreference(value: unknown): value is ThemePreference {
  return value === 'light' || value === 'dark' || value === 'system';
}

function readStoredPreference(): ThemePreference {
  try {
    const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
    return isThemePreference(stored) ? stored : 'system';
  } catch {
    // localStorage can throw (e.g. private/incognito mode with storage disabled).
    return 'system';
  }
}

function writeStoredPreference(preference: ThemePreference): void {
  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(STORAGE_KEY, preference);
    }
  } catch {
    // Ignore — nothing we can do if storage is unavailable/full/blocked.
  }
}

function systemPrefersDark(): boolean {
  if (typeof matchMedia !== 'function') {
    return false;
  }
  return matchMedia(DARK_MEDIA_QUERY).matches;
}

/**
 * Resolves and applies the active color theme (light/dark/system) by toggling
 * the `.dark` class on `document.documentElement` and persisting the user's
 * explicit preference to localStorage. Resilient to SSR/test environments
 * where `document`/`localStorage`/`matchMedia` may be unavailable.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly preference = signal<ThemePreference>(readStoredPreference());

  readonly resolved = computed<ResolvedTheme>(() => {
    const pref = this.preference();
    if (pref === 'system') {
      return this.systemPrefersDarkSignal() ? 'dark' : 'light';
    }
    return pref;
  });

  /** Tracks live `prefers-color-scheme` changes while preference === 'system'. */
  private readonly systemPrefersDarkSignal = signal<boolean>(systemPrefersDark());

  constructor() {
    this.watchSystemPreference();

    effect(() => {
      const resolved = this.resolved();
      if (typeof document !== 'undefined') {
        document.documentElement.classList.toggle(DARK_CLASS, resolved === 'dark');
      }
      writeStoredPreference(this.preference());
    });
  }

  setPreference(preference: ThemePreference): void {
    this.preference.set(preference);
  }

  private watchSystemPreference(): void {
    if (typeof matchMedia !== 'function') {
      return;
    }
    const media = matchMedia(DARK_MEDIA_QUERY);
    const listener = (event: MediaQueryListEvent): void => {
      this.systemPrefersDarkSignal.set(event.matches);
    };

    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', listener);
    } else if (typeof (media as MediaQueryList & { addListener?: (l: (e: MediaQueryListEvent) => void) => void }).addListener === 'function') {
      // Safari < 14 fallback.
      (media as MediaQueryList & { addListener: (l: (e: MediaQueryListEvent) => void) => void }).addListener(listener);
    }
  }
}
