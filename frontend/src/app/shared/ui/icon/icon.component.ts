import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { ICON_REGISTRY, IconDef, IconName } from './icon.types';

export type IconSize = 'sm' | 'md';

const SIZE_CLASSES: Record<IconSize, string> = {
  sm: 'w-3.5 h-3.5',
  md: 'w-4 h-4'
};

/**
 * Decorative inline icon (Lucide-style, stroke-based, `stroke="currentColor"`).
 * Always `aria-hidden="true"` — any accessible name must live on the
 * consuming element (a visible label, or an explicit `aria-label`).
 */
@Component({
  selector: 'app-icon',
  standalone: true,
  imports: [],
  templateUrl: './icon.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class IconComponent {
  readonly name = input.required<IconName>();
  readonly size = input<IconSize>('md');

  protected readonly def = computed<IconDef>(() => ICON_REGISTRY[this.name()]);
  protected readonly svgClass = computed(() => SIZE_CLASSES[this.size()]);
}
