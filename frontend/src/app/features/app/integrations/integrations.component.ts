import { ChangeDetectionStrategy, Component } from '@angular/core';

import { EmptyStateComponent } from '../../../shared/ui/empty-state/empty-state.component';

/**
 * Placeholder screen. The heading, the landmark structure and the empty state
 * are real; the data is not fetched yet, and nothing on this page pretends
 * otherwise.
 */
@Component({
  selector: 'app-integrations',
  standalone: true,
  imports: [EmptyStateComponent],
  templateUrl: './integrations.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class IntegrationsComponent {
  protected readonly emptyHeading = $localize`:Empty state heading@@app.integrations.empty:No channel is connected yet`;
  protected readonly placeholderDescription = $localize`:Placeholder screen description@@app.placeholder.description:This screen is in place; it fills with real data once the matching API is connected.`;
}
