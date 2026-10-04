import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

export type LogoVariant = 'mark' | 'full';

/** v2 "Marker" mark on a 32 unit grid: a chisel-cut highlighter stroke with an ink ring on it. */
const MARK_STROKE_PATH = 'M5 6H31L27 26H1Z';
const RING = { cx: 13, cy: 16, r: 5.8, strokeWidth: 3.4 } as const;
/** Lowercase `omnihear` wordmark outlines (Bricolage Grotesque 800, width 75%), drawn from x = 0. */
const WORDMARK_PATH =
  'M6.68 25.4Q4.69 25.4 3.38 24.46Q2.07 23.52 1.43 21.73Q0.8 19.94 0.8 17.39Q0.8 14.69 1.49 12.97Q2.19 11.25 3.54 10.43Q4.89 9.6 6.82 9.6Q8.75 9.6 10.04 10.43Q11.34 11.25 11.99 12.93Q12.64 14.6 12.64 17.19Q12.64 20.03 11.93 21.85Q11.22 23.66 9.89 24.53Q8.55 25.4 6.68 25.4ZM6.7 22.16Q7.24 22.16 7.59 21.69Q7.93 21.22 8.1 20.21Q8.27 19.2 8.27 17.64Q8.27 16.05 8.1 15Q7.93 13.95 7.59 13.42Q7.24 12.9 6.68 12.9Q6.19 12.9 5.85 13.39Q5.51 13.89 5.36 14.94Q5.2 15.99 5.2 17.64Q5.2 20 5.57 21.08Q5.94 22.16 6.7 22.16Z M14.46 25V15.43V10H17.67L17.78 14.4H18.47Q18.72 12.64 19.13 11.58Q19.55 10.51 20.18 10.06Q20.82 9.6 21.73 9.6Q22.81 9.6 23.42 10.17Q24.03 10.74 24.25 11.8Q24.46 12.87 24.35 14.4H25.03Q25.37 12.61 25.81 11.56Q26.25 10.51 26.93 10.06Q27.61 9.6 28.61 9.6Q29.52 9.6 30.18 9.93Q30.85 10.26 31.31 10.95Q31.76 11.65 31.97 12.71Q32.19 13.78 32.19 15.28V25H27.78V16.19Q27.78 15.2 27.67 14.59Q27.56 13.98 27.32 13.66Q27.07 13.35 26.7 13.35Q26.25 13.35 25.97 13.78Q25.68 14.2 25.55 15.11Q25.43 16.02 25.43 17.44V25H21.25V16.28Q21.25 15.23 21.14 14.6Q21.02 13.98 20.78 13.66Q20.54 13.35 20.14 13.35Q19.69 13.35 19.39 13.76Q19.09 14.18 18.96 15.07Q18.84 15.97 18.84 17.44V25Z M34.46 25V15.6V10H37.7L37.81 14.4H38.47Q38.69 12.64 39.13 11.58Q39.57 10.51 40.28 10.06Q40.99 9.6 42.02 9.6Q43.98 9.6 44.9 11.01Q45.82 12.41 45.82 15.2V25H41.42V16.25Q41.42 14.77 41.15 14.05Q40.88 13.32 40.26 13.32Q39.77 13.32 39.45 13.79Q39.12 14.26 38.98 15.17Q38.84 16.08 38.84 17.56V25Z M48.07 25V10H52.41V25ZM50.26 8.47Q48.89 8.47 48.25 7.98Q47.61 7.5 47.61 6.56Q47.61 5.6 48.27 5.09Q48.92 4.57 50.26 4.57Q51.62 4.57 52.24 5.09Q52.87 5.6 52.87 6.51Q52.87 7.5 52.23 7.98Q51.59 8.47 50.26 8.47Z M54.83 25V15.94V5.11H58.98V8.21Q58.98 8.84 58.91 9.6Q58.84 10.37 58.72 11.19Q58.61 12.02 58.47 12.84Q58.32 13.66 58.15 14.4H58.84Q59.03 12.67 59.49 11.62Q59.94 10.57 60.7 10.09Q61.45 9.6 62.53 9.6Q64.46 9.6 65.33 11.04Q66.19 12.47 66.19 15.4V25H61.79V16.25Q61.79 14.66 61.53 13.99Q61.28 13.32 60.65 13.32Q60.03 13.32 59.72 13.93Q59.4 14.55 59.3 15.54Q59.2 16.53 59.2 17.73V25Z M74.03 25.4Q72.24 25.4 71.05 24.79Q69.86 24.18 69.16 23.12Q68.47 22.07 68.18 20.65Q67.9 19.23 67.9 17.64Q67.9 16.19 68.17 14.76Q68.44 13.32 69.06 12.16Q69.69 10.99 70.84 10.3Q71.99 9.6 73.72 9.6Q75.43 9.6 76.53 10.27Q77.64 10.94 78.24 12.1Q78.84 13.27 79.01 14.83Q79.18 16.39 79.01 18.12L71.31 18.35V16.31L75.62 16.16L75.14 16.99Q75.26 15.37 75.07 14.5Q74.89 13.64 74.53 13.3Q74.18 12.95 73.69 12.95Q73.12 12.95 72.81 13.41Q72.5 13.86 72.36 14.83Q72.22 15.8 72.22 17.33Q72.22 19.72 72.57 20.97Q72.93 22.22 73.89 22.22Q74.2 22.22 74.47 22.07Q74.74 21.93 74.94 21.63Q75.14 21.34 75.23 20.89Q75.31 20.45 75.28 19.89L79.18 20.14Q79.26 20.94 79.09 21.85Q78.92 22.76 78.37 23.57Q77.81 24.38 76.76 24.89Q75.71 25.4 74.03 25.4Z M83.95 25.37Q82.95 25.37 82.12 24.91Q81.28 24.46 80.75 23.58Q80.23 22.7 80.23 21.42Q80.23 20.31 80.62 19.55Q81.02 18.78 81.69 18.27Q82.36 17.76 83.15 17.41Q83.95 17.07 84.74 16.79Q85.51 16.51 86.07 16.24Q86.62 15.97 86.95 15.55Q87.27 15.14 87.27 14.46Q87.27 13.86 86.99 13.38Q86.7 12.9 85.97 12.9Q85.43 12.9 85.14 13.2Q84.86 13.49 84.76 14.03Q84.66 14.57 84.72 15.31L80.74 15.11Q80.6 13.98 80.84 12.95Q81.08 11.93 81.75 11.18Q82.41 10.43 83.54 10.01Q84.66 9.6 86.22 9.6Q88.24 9.6 89.4 10.3Q90.57 10.99 91.07 12.36Q91.56 13.72 91.56 15.71V19.94Q91.56 20.68 91.63 21.62Q91.7 22.56 91.82 23.45Q91.93 24.35 92.07 25H88.3Q88.04 24.06 87.91 23.32Q87.78 22.59 87.67 21.7H87.1Q86.9 22.93 86.51 23.75Q86.11 24.57 85.48 24.97Q84.86 25.37 83.95 25.37ZM85.71 21.82Q86.08 21.82 86.34 21.7Q86.59 21.59 86.78 21.39Q86.96 21.19 87.07 20.94Q87.19 20.68 87.24 20.48V16.96L88.24 17.27Q87.87 17.59 87.41 17.81Q86.96 18.04 86.51 18.24Q86.05 18.44 85.64 18.64Q85.23 18.84 84.9 19.08Q84.57 19.32 84.38 19.63Q84.18 19.94 84.18 20.37Q84.18 21.02 84.63 21.42Q85.09 21.82 85.71 21.82Z M93.92 25V16.85V10H97.24L97.47 15.65H98.12Q98.15 13.32 98.65 12.02Q99.15 10.71 100.01 10.16Q100.88 9.6 101.96 9.6Q102.16 9.6 102.4 9.63Q102.64 9.66 102.84 9.74L102.61 14.77Q102.5 14.72 102.24 14.69Q101.99 14.66 101.79 14.66Q101.02 14.66 100.27 15.14Q99.52 15.62 99.02 16.69Q98.52 17.76 98.52 19.4V25Z';
/** Mark width (32) plus the 10/32 gap: where the wordmark starts in the lockup. */
const WORDMARK_OFFSET = 42;

const VIEWBOX: Record<LogoVariant, { w: number; h: number }> = {
  mark: { w: 32, h: 32 },
  full: { w: 145.84, h: 32 }
};

/**
 * OmniHear logo (brand v2, docs/BRAND.md section 3), inlined so it follows the
 * app theme class (`.dark`) rather than the OS colour scheme.
 *
 * - The stroke is always the marker: `var(--signal)`.
 * - The ring is always ink, because it always sits on yellow: `var(--brand-on)`,
 *   which is ink in both themes.
 * - The wordmark follows the theme: `var(--text-primary)`.
 *
 * `size` is the rendered height in px; width follows the variant aspect ratio.
 */
@Component({
  selector: 'app-logo',
  standalone: true,
  imports: [],
  template: `
    <svg
      xmlns="http://www.w3.org/2000/svg"
      role="img"
      aria-label="OmniHear"
      focusable="false"
      class="block shrink-0"
      [attr.viewBox]="viewBox()"
      [attr.width]="width()"
      [attr.height]="size()"
      [attr.data-variant]="variant()"
    >
      <path data-part="stroke" [attr.d]="strokePath" style="fill: var(--signal, currentColor)" />
      <circle
        data-part="ring"
        [attr.cx]="ring.cx"
        [attr.cy]="ring.cy"
        [attr.r]="ring.r"
        [attr.stroke-width]="ring.strokeWidth"
        style="fill: none; stroke: var(--brand-on, currentColor)"
      />
      @if (variant() === 'full') {
        <path
          data-part="wordmark"
          [attr.d]="wordPath"
          [attr.transform]="wordTransform"
          style="fill: var(--text-primary, currentColor)"
        />
      }
    </svg>
  `,
  host: { class: 'inline-flex items-center leading-none' },
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class LogoComponent {
  readonly variant = input<LogoVariant>('full');
  readonly size = input<number>(28);

  protected readonly strokePath = MARK_STROKE_PATH;
  protected readonly ring = RING;
  protected readonly wordPath = WORDMARK_PATH;
  protected readonly wordTransform = `translate(${WORDMARK_OFFSET} 0)`;

  protected readonly viewBox = computed(() => {
    const box = VIEWBOX[this.variant()];
    return `0 0 ${box.w} ${box.h}`;
  });

  protected readonly width = computed(() => {
    const box = VIEWBOX[this.variant()];
    return Math.round((this.size() * box.w) / box.h);
  });
}
