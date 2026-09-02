import { ChangeDetectionStrategy, Component, inject } from '@angular/core';

import { ThemePreference, ThemeService } from '../../core/theme/theme.service';

/**
 * Light / dark / system switch. Rendered as a `role="group"` of toggle buttons
 * with `aria-pressed`, so the active choice is announced rather than implied by
 * the highlight colour alone.
 */
@Component({
  selector: 'app-theme-toggle',
  standalone: true,
  imports: [],
  templateUrl: './theme-toggle.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ThemeToggleComponent {
  private readonly theme = inject(ThemeService);

  protected readonly preference = this.theme.preference;

  protected readonly groupLabel = $localize`:Theme switch group label@@shell.theme.label:Colour theme`;
  protected readonly lightLabel = $localize`:Theme option@@shell.theme.light:Light`;
  protected readonly darkLabel = $localize`:Theme option@@shell.theme.dark:Dark`;
  protected readonly systemLabel = $localize`:Theme option@@shell.theme.system:System`;

  protected select(preference: ThemePreference): void {
    this.theme.setPreference(preference);
  }

  protected classesFor(preference: ThemePreference): string {
    const base =
      'rounded-sm px-2 py-1 text-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring-focus)]';
    return this.preference() === preference
      ? `${base} bg-[var(--bg-surface-selected)] text-[var(--text-primary)]`
      : `${base} text-[var(--text-muted)] hover:text-[var(--text-primary)]`;
  }
}
