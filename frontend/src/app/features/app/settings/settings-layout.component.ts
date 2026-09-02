import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

/**
 * Wrapper for `/app/settings/**`. Owns the single `<h1>` and the secondary
 * navigation landmark so the child screens can start at `<h2>` and the heading
 * order stays correct however the user arrives.
 */
@Component({
  selector: 'app-settings-layout',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  templateUrl: './settings-layout.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class SettingsLayoutComponent {
  protected readonly navLabel = $localize`:Settings navigation landmark label@@app.settings.navLabel:Settings sections`;
}
