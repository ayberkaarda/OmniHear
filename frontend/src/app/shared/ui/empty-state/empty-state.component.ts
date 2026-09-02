import { ChangeDetectionStrategy, Component, input } from '@angular/core';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';

/**
 * Honest placeholder for a surface that has nothing to show yet.
 *
 * Used by the `/app/**` screens whose data lands in a later phase: an empty
 * state that says so is preferable to mock rows that read as real data.
 */
@Component({
    selector: 'app-empty-state',
    imports: [IconComponent],
    templateUrl: './empty-state.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class EmptyStateComponent {
  readonly icon = input<IconName>('info');
  readonly heading = input.required<string>();
  readonly description = input<string | undefined>(undefined);
}
