import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';

import { IconComponent } from '../icon/icon.component';
import { IconName } from '../icon/icon.types';

export type KpiFormat = 'number' | 'percent' | 'score';
export type DeltaPolarity = 'up-good' | 'down-good';
type DeltaTone = 'positive' | 'neutral' | 'negative';

/** Shortest spark bar, in percent of the strip, so the lowest point still shows. */
const SPARK_FLOOR = 15;

/** Brand v2 writes negatives with the real minus sign (docs/BRAND.md section 5). */
function withMinus(text: string): string {
  return text.replace(/^-/, '−');
}

/**
 * A key figure (docs/BRAND.md section 7): no card, a hairline on top, the title,
 * then the value in Martian Mono at poster size. The optional `spark` trend is
 * drawn as a small voiceprint, one bar per point with the newest in signal ink.
 * Clicking emits `selected`; while loading (or with a null value) it is a
 * disabled skeleton.
 */
@Component({
  selector: 'app-kpi-card',
  standalone: true,
  imports: [IconComponent],
  templateUrl: './kpi-card.component.html',
  styleUrl: './kpi-card.component.scss',
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
        return withMinus(new Intl.NumberFormat(undefined, { style: 'percent', maximumFractionDigits: 1 }).format(value));
      case 'score': {
        const sign = value > 0 ? '+' : '';
        return withMinus(`${sign}${value.toFixed(2)}`);
      }
      case 'number':
      default:
        return withMinus(new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value));
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
    return withMinus(`${sign}${new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(delta)}`);
  });

  /** Bar heights in percent, oldest to newest, scaled between the series min and max. */
  protected readonly sparkBars = computed<number[] | null>(() => {
    const data = this.spark();
    if (!data || data.length < 2) {
      return null;
    }
    const min = Math.min(...data);
    const range = Math.max(...data) - min || 1;
    return data.map((point) => Math.round(SPARK_FLOOR + ((point - min) / range) * (100 - SPARK_FLOOR)));
  });

  protected onActivate(): void {
    if (this.isSkeleton()) {
      return;
    }
    this.selected.emit();
  }
}
