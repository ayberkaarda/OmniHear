import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';

import { QuotaStore } from '../../core/quota/quota.store';
import { IconComponent } from '../../shared/ui/icon/icon.component';
import { IconName } from '../../shared/ui/icon/icon.types';

/**
 * Remaining-analysis meter, fed by the `X-Quota-Remaining` header (contract 1).
 *
 * Colour is never the only signal: the level carries its own icon and the exact
 * numbers are written out, so the warning state survives a monochrome or
 * colour-blind reading (`omnihear-tokens` rule 4).
 *
 * `compact` is the top-bar form: one mono label line and a hairline bar. The
 * "Remaining" line is still in the text, for screen readers, just not drawn.
 */
@Component({
  selector: 'app-quota-meter',
  imports: [IconComponent],
  templateUrl: './quota-meter.component.html',
  styleUrl: './quota-meter.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class QuotaMeterComponent {
  private readonly quota = inject(QuotaStore);

  readonly compact = input(false);

  protected readonly limit = this.quota.limit;
  protected readonly remaining = this.quota.remaining;
  protected readonly used = this.quota.used;
  protected readonly level = this.quota.level;

  protected readonly percentUsed = computed(() => {
    const ratio = this.quota.usedRatio();
    return ratio === null ? null : Math.round(ratio * 100);
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
