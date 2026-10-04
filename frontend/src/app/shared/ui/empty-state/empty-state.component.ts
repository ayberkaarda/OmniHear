import { ChangeDetectionStrategy, Component, input } from '@angular/core';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';

/**
 * Honest placeholder for a surface that has nothing to show yet.
 *
 * Used by the `/app/**` screens whose data lands in a later phase: an empty
 * state that says so is preferable to mock rows that read as real data.
 *
 * Brand v2: no card. A ruled band, the glyph in a square, the heading in the
 * condensed display cut over a flat line (nothing heard yet), and any
 * projected actions below. Projected buttons use `app-button`.
 */
@Component({
    selector: 'app-empty-state',
    imports: [IconComponent],
    templateUrl: './empty-state.component.html',
    styleUrl: './empty-state.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class EmptyStateComponent {
  readonly icon = input<IconName>('info');
  readonly heading = input.required<string>();
  readonly description = input<string | undefined>(undefined);
}
