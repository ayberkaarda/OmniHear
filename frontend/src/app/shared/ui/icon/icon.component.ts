import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

import { ICON_REGISTRY, IconDef, IconName } from './icon.types';

export type IconSize = 'sm' | 'md' | 'lg';

const SIZE_CLASSES: Record<IconSize, string> = {
  sm: 'w-3.5 h-3.5',
  md: 'w-4 h-4',
  lg: 'w-5 h-5'
};

/**
 * Decorative inline icon (stroke-based, `stroke="currentColor"`).
 * Brand v2: square caps and mitred joins, the same cut as the chisel-tip
 * stroke in the logo, at a 1.75 stroke so glyphs sit at the weight of
 * Bricolage Grotesque 500 to 600 text beside them. Colour is inherited, so on
 * light paper an icon that should read as the marker uses `--signal-ink`.
 * Always `aria-hidden="true"` — any accessible name must live on the
 * consuming element (a visible label, or an explicit `aria-label`).
 */
@Component({
    selector: 'app-icon',
    imports: [],
    templateUrl: './icon.component.html',
    changeDetection: ChangeDetectionStrategy.OnPush
})
export class IconComponent {
  readonly name = input.required<IconName>();
  readonly size = input<IconSize>('md');

  /** Stroke in 24-unit glyph space; small glyphs get a touch more so they do not thin out. */
  protected readonly strokeWidth = computed(() => (this.size() === 'sm' ? 2 : 1.75));

  protected readonly def = computed<IconDef>(() => ICON_REGISTRY[this.name()]);
  protected readonly svgClass = computed(() => SIZE_CLASSES[this.size()]);
}
