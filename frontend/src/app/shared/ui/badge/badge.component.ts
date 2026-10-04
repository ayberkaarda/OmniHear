import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';

export type BadgeKind = 'sentiment' | 'category' | 'source' | 'status';
export type BadgeSize = 'sm' | 'md';

const STATUS_ICON: Record<string, IconName> = {
  active: 'check-circle',
  success: 'check-circle',
  error: 'x-circle',
  paused: 'pause-circle',
  warning: 'alert-triangle',
  info: 'info'
};

/**
 * Sentiment / category / source / status marker (docs/BRAND.md sections 6
 * and 7). No pills and no chips:
 *
 * - sentiment and category: a small square in the data fill plus the word in
 *   the matching text tone. Colour is never the only signal: the word is
 *   always there, and a sentiment score, when given, sits beside it in mono.
 * - status: the word in the status colour, with its glyph unless `showIcon`
 *   is false.
 * - source: a mono, uppercase label in a hairline box, like a stamp.
 *
 * Tones are resolved in `badge.component.scss` from `data-kind` and
 * `data-value`, so nothing here grows the global stylesheet.
 */
@Component({
  selector: 'app-badge',
  standalone: true,
  imports: [IconComponent],
  templateUrl: './badge.component.html',
  styleUrl: './badge.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class BadgeComponent {
  readonly kind = input.required<BadgeKind>();
  readonly value = input.required<string>();
  readonly score = input<number | undefined>(undefined);
  readonly size = input<BadgeSize>('md');
  readonly showIcon = input(true);

  /** Sentiment and category carry the square marker; status and source do not. */
  protected readonly hasSquare = computed(() => this.kind() === 'sentiment' || this.kind() === 'category');

  protected readonly iconName = computed<IconName | null>(() =>
    this.kind() === 'status' ? (STATUS_ICON[this.value()] ?? null) : null
  );

  protected readonly shouldShowIcon = computed(() => this.showIcon() && this.iconName() !== null);

  /** Signed, two decimals, with the real minus sign (docs/BRAND.md section 5). */
  protected readonly formattedScore = computed<string | null>(() => {
    const score = this.score();
    if (this.kind() !== 'sentiment' || score === undefined || score === null || Number.isNaN(score)) {
      return null;
    }
    const sign = score > 0 ? '+' : score < 0 ? '−' : '';
    return `${sign}${Math.abs(score).toFixed(2)}`;
  });
}
