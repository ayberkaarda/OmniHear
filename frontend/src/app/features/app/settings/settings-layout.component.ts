import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

const LINK_BASE =
  'relative flex h-10 shrink-0 items-center whitespace-nowrap px-3 text-sm transition-colors duration-fast ease-standard ' +
  'hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring-focus)]';
const LINK_ACTIVE = 'font-medium text-[var(--text-primary)]';
const LINK_IDLE = 'text-[var(--text-secondary)]';

/**
 * Wrapper for `/app/settings/**`. Owns the single `<h1>` and the secondary
 * navigation landmark so the child screens can start at `<h2>` and the heading
 * order stays correct however the user arrives.
 */
@Component({
  selector: 'app-settings-layout',
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  templateUrl: './settings-layout.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class SettingsLayoutComponent {
  protected readonly navLabel = $localize`:Settings navigation landmark label@@app.settings.navLabel:Settings sections`;

  protected linkClasses(active: boolean): string {
    return `${LINK_BASE} ${active ? LINK_ACTIVE : LINK_IDLE}`;
  }
}
