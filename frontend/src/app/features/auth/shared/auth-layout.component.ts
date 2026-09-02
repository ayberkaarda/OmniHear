import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * Shared chrome for the five `/auth/*` screens: brand bar, centred card,
 * heading and lead paragraph. The pages differ only in their form, so the
 * landmark structure (one `<main>`, one `<h1>`) lives here and is guaranteed to
 * be identical everywhere.
 */
@Component({
    selector: 'app-auth-layout',
    imports: [RouterLink],
    templateUrl: './auth-layout.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class AuthLayoutComponent {
  readonly heading = input.required<string>();
  readonly lead = input<string | undefined>(undefined);
}
