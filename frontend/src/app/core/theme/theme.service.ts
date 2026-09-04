import { Injectable, computed, effect, signal } from '@angular/core';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

const STORAGE_KEY = 'omnihear.theme';
const DARK_CLASS = 'dark';
const DARK_MEDIA_QUERY = '(prefers-color-scheme: dark)';
/**
 * Applied to `documentElement` for the instant the `.dark` class is toggled.
 * `styles.scss` gives it a blanket `transition: none !important`, so every
 * element re-paints in its new palette immediately instead of animating
 * `background-color`/`color` independently. Without this, elements whose
 * colour-affecting properties transition at different rates (or not at all)
 * can render an unreadable combination for the duration of the transition —
 * e.g. a card background still mid-fade from light to dark under text that
 * already snapped to its dark-mode colour. Removed two animation frames
 * later, once the new palette has already painted, so removing it does not
 * itself trigger a visible transition. Safe under `prefers-reduced-motion`:
 * it only ever suppresses a transition, never re-enables one.
 */
const SUPPRESS_TRANSITIONS_CLASS = 'theme-changing';

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
        this.applyResolvedTheme(resolved === 'dark');
      }
      writeStoredPreference(this.preference());
    });
  }

  setPreference(preference: ThemePreference): void {
    this.preference.set(preference);
  }

  /**
   * Toggles `.dark` on `documentElement`, suppressing colour transitions for
   * the moment the class actually changes (see `SUPPRESS_TRANSITIONS_CLASS`).
   * A no-op toggle (resolved theme already matches — e.g. the FOUC-guard
   * inline script in index.html already applied the right class before this
   * service ran) never adds the suppression class, since nothing is about to
   * transition.
   */
  private applyResolvedTheme(isDark: boolean): void {
    const root = document.documentElement;
    const alreadyApplied = root.classList.contains(DARK_CLASS) === isDark;
    if (alreadyApplied) {
      return;
    }

    root.classList.add(SUPPRESS_TRANSITIONS_CLASS);
    root.classList.toggle(DARK_CLASS, isDark);

    const removeSuppression = (): void => root.classList.remove(SUPPRESS_TRANSITIONS_CLASS);

    if (typeof requestAnimationFrame === 'function') {
      // Two frames, not one: the first is where the browser recalculates
      // style and paints with transitions suppressed. Only after that paint
      // has actually happened is it safe to remove the suppression class —
      // removing it a frame too early can race the paint on slower devices.
      requestAnimationFrame(() => requestAnimationFrame(removeSuppression));
    } else {
      // SSR/test environments without rAF.
      setTimeout(removeSuppression, 0);
    }
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
