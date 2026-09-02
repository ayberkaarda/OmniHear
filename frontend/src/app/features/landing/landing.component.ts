import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ButtonStyleDirective } from '../../shared/ui/button/button-style.directive';
import { IconComponent } from '../../shared/ui/icon/icon.component';

/**
 * Public marketing page (spec section 4: hero, features, integrations,
 * pricing, FAQ, CTA).
 *
 * Static by design: no HTTP call, no store, so it renders for a signed-out
 * visitor and stays in its own lazy chunk. The FAQ uses native
 * `<details>/<summary>` rather than a scripted accordion — keyboard support and
 * screen-reader semantics come for free and cost no bundle.
 */
@Component({
  selector: 'app-landing',
  standalone: true,
  imports: [RouterLink, ButtonStyleDirective, IconComponent],
  templateUrl: './landing.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class LandingComponent {
  /** Free-plan analysis allowance, spec 7.2. Mirrors backend `config/quota.php`. */
  protected readonly freePlanQuota = 200;
}
