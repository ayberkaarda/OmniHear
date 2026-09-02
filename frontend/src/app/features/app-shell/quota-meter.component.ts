import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';

import { QuotaStore } from '../../core/quota/quota.store';
import { IconComponent } from '../../shared/ui/icon/icon.component';
import { IconName } from '../../shared/ui/icon/icon.types';

/**
 * Remaining-analysis meter, fed by the `X-Quota-Remaining` header (contract 1).
 *
 * Colour is never the only signal: the level carries its own icon and the exact
 * numbers are written out, so the warning state survives a monochrome or
 * colour-blind reading (`omnihear-tokens` rule 4).
 */
@Component({
    selector: 'app-quota-meter',
    imports: [IconComponent],
    templateUrl: './quota-meter.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class QuotaMeterComponent {
  private readonly quota = inject(QuotaStore);

  protected readonly limit = this.quota.limit;
  protected readonly remaining = this.quota.remaining;
  protected readonly used = this.quota.used;
  protected readonly level = this.quota.level;

  protected readonly percentUsed = computed(() => {
    const ratio = this.quota.usedRatio();
    return ratio === null ? null : Math.round(ratio * 100);
  });

  protected readonly toneClasses = computed(() => {
    switch (this.level()) {
      case 'exceeded':
        return 'bg-[var(--quota-exceeded-bg)] text-[var(--quota-exceeded-text)]';
      case 'warning':
        return 'bg-[var(--quota-warning-bg)] text-[var(--quota-warning-text)]';
      default:
        return 'bg-[var(--quota-ok-bg)] text-[var(--quota-ok-text)]';
    }
  });

  protected readonly barClasses = computed(() => {
    switch (this.level()) {
      case 'exceeded':
        return 'bg-[var(--sentiment-negative-fill)]';
      case 'warning':
        return 'bg-[var(--category-complaint-fill)]';
      default:
        return 'bg-[var(--sentiment-neutral-fill)]';
    }
  });

  protected readonly levelIcon = computed<IconName>(() => {
    switch (this.level()) {
      case 'exceeded':
        return 'lock';
      case 'warning':
        return 'alert-triangle';
      default:
        return 'info';
    }
  });

  protected readonly meterLabel = $localize`:Quota meter accessible label@@shell.quota.label:Analysis quota usage`;
}
