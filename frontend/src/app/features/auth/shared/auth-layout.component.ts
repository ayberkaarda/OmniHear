import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { LogoComponent } from '../../../shared/ui/logo/logo.component';

/**
 * Shared chrome for `/auth/*` screens: masthead, poster heading, open form,
 * heading and lead paragraph. The pages differ only in their form, so the
 * landmark structure (one `<main>`, one `<h1>`) lives here and is guaranteed to
 * be identical everywhere.
 */
@Component({
    selector: 'app-auth-layout',
    imports: [RouterLink, LogoComponent],
    templateUrl: './auth-layout.component.html',
    styleUrl: './auth-layout.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class AuthLayoutComponent {
  readonly heading = input.required<string>();
  readonly lead = input<string | undefined>(undefined);
}
