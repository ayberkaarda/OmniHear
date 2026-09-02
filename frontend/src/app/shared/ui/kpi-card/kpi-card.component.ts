import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';

export type KpiFormat = 'number' | 'percent' | 'score';
export type DeltaPolarity = 'up-good' | 'down-good';
type DeltaTone = 'positive' | 'neutral' | 'negative';

const DELTA_TONE_CLASSES: Record<DeltaTone, string> = {
  positive: 'text-[var(--sentiment-positive-text)]',
  neutral: 'text-[var(--text-muted)]',
  negative: 'text-[var(--sentiment-negative-text)]'
};

const SPARK_WIDTH = 100;
const SPARK_HEIGHT = 28;

@Component({
  selector: 'app-kpi-card',
  standalone: true,
  imports: [IconComponent],
  templateUrl: './kpi-card.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class KpiCardComponent {
  readonly title = input.required<string>();
  readonly value = input.required<number | null>();
  readonly format = input<KpiFormat>('number');
  readonly delta = input<number | undefined>(undefined);
  readonly deltaPolarity = input<DeltaPolarity>('up-good');
  readonly deltaLabel = input<string | undefined>(undefined);
  readonly spark = input<number[] | undefined>(undefined);
  readonly loading = input(false);

  readonly selected = output<void>();

  protected readonly isSkeleton = computed(() => this.loading() || this.value() === null);

  protected readonly displayValue = computed<string | null>(() => {
    const value = this.value();
    if (value === null || value === undefined) {
      return null;
    }
    switch (this.format()) {
      case 'percent':
        return new Intl.NumberFormat(undefined, { style: 'percent', maximumFractionDigits: 1 }).format(value);
      case 'score': {
        const sign = value > 0 ? '+' : '';
        return `${sign}${value.toFixed(2)}`;
      }
      case 'number':
      default:
        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value);
    }
  });

  protected readonly deltaTone = computed<DeltaTone>(() => {
    const delta = this.delta();
    if (delta === undefined || delta === null || delta === 0) {
      return 'neutral';
    }
    const isIncrease = delta > 0;
    const isGood = this.deltaPolarity() === 'up-good' ? isIncrease : !isIncrease;
    return isGood ? 'positive' : 'negative';
  });

  protected readonly deltaToneClass = computed(() => DELTA_TONE_CLASSES[this.deltaTone()]);

  protected readonly deltaIcon = computed<IconName | null>(() => {
    const delta = this.delta();
    if (delta === undefined || delta === null || delta === 0) {
      return null;
    }
    return delta > 0 ? 'arrow-up' : 'arrow-down';
  });

  protected readonly formattedDelta = computed<string | null>(() => {
    const delta = this.delta();
    if (delta === undefined || delta === null) {
      return null;
    }
    const sign = delta > 0 ? '+' : '';
    return `${sign}${new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(delta)}`;
  });

  protected readonly sparkPoints = computed<string | null>(() => {
    const data = this.spark();
    if (!data || data.length < 2) {
      return null;
    }
    const min = Math.min(...data);
    const max = Math.max(...data);
    const range = max - min || 1;
    const step = SPARK_WIDTH / (data.length - 1);
    return data
      .map((point, index) => {
        const x = index * step;
        const y = SPARK_HEIGHT - ((point - min) / range) * SPARK_HEIGHT;
        return `${x.toFixed(2)},${y.toFixed(2)}`;
      })
      .join(' ');
  });

  protected readonly sparkViewBox = `0 0 ${SPARK_WIDTH} ${SPARK_HEIGHT}`;

  protected onActivate(): void {
    if (this.isSkeleton()) {
      return;
    }
    this.selected.emit();
  }
}
