import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

import { EmptyStateComponent } from '../../../shared/ui/empty-state/empty-state.component';

/**
 * Placeholder for `/app/inbox/:id`. The route parameter is bound through
 * `withComponentInputBinding()` and shown as-is, so the deep link is provably
 * wired even though the record itself is not fetched yet.
 */
@Component({
  selector: 'app-inbox-detail',
  standalone: true,
  imports: [RouterLink, EmptyStateComponent],
  templateUrl: './inbox-detail.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class InboxDetailComponent {
  readonly id = input<string | undefined>(undefined);

  protected readonly emptyHeading = $localize`:Empty state heading@@app.inboxDetail.empty:This comment is not loaded yet`;
  protected readonly placeholderDescription = $localize`:Placeholder screen description@@app.placeholder.description:This screen is in place; it fills with real data once the matching API is connected.`;
}
