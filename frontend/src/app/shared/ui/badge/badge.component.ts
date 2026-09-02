import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';

export type BadgeKind = 'sentiment' | 'category' | 'source' | 'status';
export type BadgeSize = 'sm' | 'md';

const BASE_CLASSES = 'inline-flex items-center rounded-full border font-medium whitespace-nowrap';

const SIZE_CLASSES: Record<BadgeSize, string> = {
  sm: 'h-5 px-2 text-[11px] gap-1',
  md: 'h-6 px-2.5 text-xs gap-1.5'
};

const NEUTRAL_TONE = 'bg-[var(--bg-surface)] text-[var(--text-secondary)] border-[var(--border)]';

const SENTIMENT_TONE: Record<string, string> = {
  positive: 'bg-[var(--sentiment-positive-bg)] text-[var(--sentiment-positive-text)] border-[var(--sentiment-positive-border)]',
  neutral: 'bg-[var(--sentiment-neutral-bg)] text-[var(--sentiment-neutral-text)] border-[var(--sentiment-neutral-border)]',
  negative: 'bg-[var(--sentiment-negative-bg)] text-[var(--sentiment-negative-text)] border-[var(--sentiment-negative-border)]'
};

const SENTIMENT_ICON: Record<string, IconName> = {
  positive: 'smile',
  neutral: 'meh',
  negative: 'frown'
};

const CATEGORY_TONE: Record<string, string> = {
  complaint: 'bg-[var(--category-complaint-bg)] text-[var(--category-complaint-text)] border-[var(--category-complaint-border)]',
  praise: 'bg-[var(--category-praise-bg)] text-[var(--category-praise-text)] border-[var(--category-praise-border)]',
  bug: 'bg-[var(--category-bug-bg)] text-[var(--category-bug-text)] border-[var(--category-bug-border)]',
  feature_request:
    'bg-[var(--category-feature-request-bg)] text-[var(--category-feature-request-text)] border-[var(--category-feature-request-border)]'
};

const CATEGORY_ICON: Record<string, IconName> = {
  complaint: 'megaphone',
  praise: 'heart',
  bug: 'bug',
  feature_request: 'lightbulb'
};

// Status tokens are single flat vars (--status-success etc.), not a {text,bg,border,fill} group,
// so status badges are rendered as a transparent outline using that one color.
const STATUS_TONE: Record<string, string> = {
  active: 'text-[var(--status-success)] border-[var(--status-success)] bg-transparent',
  success: 'text-[var(--status-success)] border-[var(--status-success)] bg-transparent',
  warning: 'text-[var(--status-warning)] border-[var(--status-warning)] bg-transparent',
  error: 'text-[var(--status-error)] border-[var(--status-error)] bg-transparent',
  info: 'text-[var(--status-info)] border-[var(--status-info)] bg-transparent',
  paused: 'text-[var(--status-paused)] border-[var(--status-paused)] bg-transparent'
};

const STATUS_ICON: Record<string, IconName> = {
  active: 'check-circle',
  success: 'check-circle',
  error: 'x-circle',
  paused: 'pause-circle',
  warning: 'alert-triangle',
  info: 'info'
};

const SOURCE_TONE = 'bg-[var(--source-bg)] text-[var(--source-text)] border-[var(--source-border)]';

/**
 * Sentiment / category / source / status pill.
 *
 * Color is never the only signal: sentiment and category badges always
 * render an icon + text label, regardless of `showIcon` — see
 * `shouldShowIcon()`.
 */
@Component({
  selector: 'app-badge',
  standalone: true,
  imports: [IconComponent],
  templateUrl: './badge.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class BadgeComponent {
  readonly kind = input.required<BadgeKind>();
  readonly value = input.required<string>();
  readonly score = input<number | undefined>(undefined);
  readonly size = input<BadgeSize>('md');
  readonly showIcon = input(true);

  protected readonly classes = computed(() => {
    return [BASE_CLASSES, SIZE_CLASSES[this.size()], this.toneClasses()].join(' ');
  });

  protected readonly toneClasses = computed<string>(() => {
    switch (this.kind()) {
      case 'sentiment':
        return SENTIMENT_TONE[this.value()] ?? NEUTRAL_TONE;
      case 'category':
        return CATEGORY_TONE[this.value()] ?? NEUTRAL_TONE;
      case 'status':
        return STATUS_TONE[this.value()] ?? NEUTRAL_TONE;
      case 'source':
        return SOURCE_TONE;
      default:
        return NEUTRAL_TONE;
    }
  });

  protected readonly iconName = computed<IconName | null>(() => {
    switch (this.kind()) {
      case 'sentiment':
        return SENTIMENT_ICON[this.value()] ?? null;
      case 'category':
        return CATEGORY_ICON[this.value()] ?? null;
      case 'status':
        return STATUS_ICON[this.value()] ?? null;
      default:
        return null;
    }
  });

  /** Sentiment/category icons are mandatory (color is never the sole signal); others follow showIcon. */
  protected readonly shouldShowIcon = computed(() => {
    const kind = this.kind();
    if (kind === 'sentiment' || kind === 'category') {
      return true;
    }
    return this.showIcon() && this.iconName() !== null;
  });

  protected readonly formattedScore = computed<string | null>(() => {
    const score = this.score();
    if (this.kind() !== 'sentiment' || score === undefined || score === null) {
      return null;
    }
    const sign = score > 0 ? '+' : '';
    return `${sign}${score.toFixed(2)}`;
  });
}
