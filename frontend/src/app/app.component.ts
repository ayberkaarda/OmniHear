import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';

import { ThemeService } from './core/theme/theme.service';
import { ToastHostComponent } from './core/toast/toast-host.component';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, ToastHostComponent],
  templateUrl: './app.component.html',
  styleUrl: './app.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AppComponent {
  // Instantiated for its side effect: resolves light/dark/system and keeps the
  // `.dark` class on <html> in sync with the stored preference (ADR-0006 section 7).
  private readonly theme = inject(ThemeService);
}
