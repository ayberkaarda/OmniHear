import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

export type LogoVariant = 'mark' | 'full';

/** Sliced-disc mark: four ink slices, one signal slice that runs past the disc edge. */
const MARK_INK_PATH =
  'M13.00 3.00 A13.00 13.00 0 0 0 4.02 6.60 L21.98 6.60 A13.00 13.00 0 0 0 13.00 3.00 Z M2.31 8.60 A13.00 13.00 0 0 0 0.57 12.20 L25.43 12.20 A13.00 13.00 0 0 0 23.69 8.60 Z M0.57 19.80 A13.00 13.00 0 0 0 2.31 23.40 L23.69 23.40 A13.00 13.00 0 0 0 25.43 19.80 Z M4.02 25.40 A13.00 13.00 0 0 0 13.00 29.00 L13.00 29.00 A13.00 13.00 0 0 0 21.98 25.40 Z';
const MARK_SIGNAL_PATH = 'M5.13 14.20 A13.00 13.00 0 0 0 5.13 17.80 L30.87 17.80 A13.00 13.00 0 0 0 30.87 14.20 Z';
/** Wordmark outlines (Schibsted Grotesk), positioned to the right of the mark at x >= 42. */
const WORDMARK_PATH =
  'M51.28 24.81Q48.67 24.81 46.77 23.74Q44.87 22.67 43.84 20.69Q42.81 18.72 42.81 16.0Q42.81 13.27 43.84 11.3Q44.87 9.33 46.77 8.26Q48.67 7.19 51.29 7.19Q53.9 7.19 55.8 8.26Q57.7 9.33 58.73 11.3Q59.76 13.27 59.76 16.0Q59.76 18.72 58.73 20.69Q57.7 22.67 55.8 23.74Q53.9 24.81 51.28 24.81ZM51.28 21.73Q52.74 21.73 53.78 21.04Q54.82 20.36 55.38 19.08Q55.93 17.79 55.93 16.0Q55.93 14.19 55.38 12.92Q54.82 11.64 53.78 10.96Q52.74 10.27 51.28 10.27Q49.83 10.27 48.79 10.96Q47.75 11.64 47.19 12.92Q46.64 14.19 46.64 16.0Q46.64 17.79 47.19 19.08Q47.75 20.36 48.79 21.04Q49.83 21.73 51.28 21.73Z M61.75 24.5V11.75H65.15V13.8Q65.72 12.69 66.7 12.07Q67.69 11.44 69.01 11.44Q70.33 11.44 71.25 12.1Q72.18 12.75 72.62 14.02Q73.33 12.69 74.41 12.07Q75.49 11.44 76.72 11.44Q78.66 11.44 79.69 12.81Q80.72 14.17 80.72 16.72V24.5H77.2V16.68Q77.2 15.96 77.06 15.38Q76.91 14.8 76.54 14.45Q76.18 14.1 75.49 14.1Q74.68 14.1 74.12 14.63Q73.57 15.16 73.28 16.09Q72.99 17.02 72.99 18.22V24.5H69.47V16.68Q69.47 15.96 69.33 15.37Q69.19 14.78 68.82 14.44Q68.44 14.1 67.77 14.1Q66.97 14.1 66.41 14.62Q65.85 15.15 65.56 16.08Q65.27 17 65.27 18.23V24.5Z M83.34 24.5V11.75H86.74V13.98Q87.31 12.85 88.3 12.16Q89.36 11.44 90.86 11.44Q92.35 11.44 93.3 12.12Q94.25 12.79 94.71 13.98Q95.17 15.17 95.17 16.73V24.5H91.66V16.96Q91.66 15.6 91.21 14.85Q90.76 14.1 89.65 14.1Q88.34 14.1 87.6 15.22Q86.86 16.34 86.86 18.66V24.5Z M97.79 24.5V11.75H101.31V24.5ZM99.55 10.23Q98.5 10.23 97.87 9.59Q97.23 8.95 97.23 7.9Q97.23 6.84 97.87 6.21Q98.5 5.58 99.55 5.58Q100.62 5.58 101.25 6.21Q101.88 6.84 101.88 7.9Q101.88 8.95 101.25 9.59Q100.62 10.23 99.55 10.23Z M104.18 24.5V7.5H107.83V14.43H115.39V7.5H119.03V24.5H115.39V17.51H107.83V24.5Z M127.7 24.81Q125.82 24.81 124.33 23.99Q122.84 23.18 121.97 21.67Q121.1 20.17 121.1 18.11Q121.1 16.07 121.96 14.57Q122.81 13.07 124.29 12.26Q125.77 11.44 127.68 11.44Q128.73 11.44 129.81 11.76Q130.89 12.08 131.8 12.89Q132.7 13.7 133.26 15.14Q133.81 16.59 133.81 18.83H124.79Q124.91 20.23 125.49 21.11Q126.2 22.21 127.68 22.21Q128.63 22.21 129.32 21.71Q130.01 21.22 130.38 20.2L133.57 21.02Q133.12 22.34 132.21 23.18Q131.3 24.02 130.12 24.41Q128.95 24.81 127.7 24.81ZM124.87 16.83H130.24Q130.12 15.98 129.84 15.4Q129.46 14.62 128.88 14.26Q128.3 13.91 127.58 13.91Q126.65 13.91 126.02 14.44Q125.4 14.97 125.09 15.92Q124.95 16.34 124.87 16.83Z M139.45 24.81Q138.23 24.81 137.2 24.38Q136.18 23.95 135.56 23.1Q134.94 22.25 134.94 20.97Q134.94 19.6 135.73 18.64Q136.52 17.69 138.03 17.19Q139.54 16.68 141.68 16.68H143.26V16.27Q143.26 15.01 142.8 14.39Q142.34 13.77 141.28 13.77Q140.26 13.77 139.72 14.31Q139.19 14.84 139.07 15.83L135.48 15.22Q135.68 14.16 136.39 13.3Q137.11 12.45 138.37 11.94Q139.63 11.43 141.48 11.43Q143.16 11.43 144.33 11.97Q145.5 12.51 146.11 13.71Q146.72 14.91 146.72 16.93V21.27Q146.72 21.67 146.83 21.8Q146.94 21.93 147.31 21.93H147.89V24.5H146.21Q144.83 24.5 144.12 23.8Q143.66 23.37 143.5 22.67Q143.21 23.18 142.75 23.63Q142.22 24.15 141.42 24.48Q140.61 24.81 139.45 24.81ZM140.46 22.4Q141.29 22.4 141.93 22.02Q142.56 21.64 142.93 20.88Q143.29 20.12 143.29 18.99V18.72H141.81Q140.54 18.72 139.84 19.05Q139.14 19.38 138.86 19.85Q138.57 20.33 138.57 20.78Q138.57 21.14 138.74 21.51Q138.91 21.89 139.32 22.14Q139.73 22.4 140.46 22.4Z M149.76 24.5V11.75H153.16V13.93Q153.74 12.74 154.72 12.13Q155.82 11.44 157.12 11.44Q157.49 11.44 157.86 11.5Q158.24 11.56 158.62 11.7L158.35 14.7Q157.58 14.49 156.87 14.49Q156.29 14.49 155.66 14.69Q155.03 14.89 154.49 15.39Q153.96 15.89 153.62 16.81Q153.28 17.72 153.28 19.14V24.5Z';

const VIEWBOX: Record<LogoVariant, { w: number; h: number }> = {
  mark: { w: 32, h: 32 },
  full: { w: 160, h: 32 }
};

/**
 * OmniHear logo, inlined so it follows the app theme class (`.dark`) rather than
 * the OS colour scheme: ink paths use `var(--text-primary)` (falling back to
 * `currentColor`), the single signal slice uses `var(--signal)`.
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
      <path [attr.d]="inkPath" style="fill: var(--text-primary, currentColor)" />
      <path [attr.d]="signalPath" style="fill: var(--signal, currentColor)" />
      @if (variant() === 'full') {
        <path [attr.d]="wordPath" style="fill: var(--text-primary, currentColor)" />
      }
    </svg>
  `,
  host: { class: 'inline-flex items-center leading-none' },
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class LogoComponent {
  readonly variant = input<LogoVariant>('full');
  readonly size = input<number>(28);

  protected readonly inkPath = MARK_INK_PATH;
  protected readonly signalPath = MARK_SIGNAL_PATH;
  protected readonly wordPath = WORDMARK_PATH;

  protected readonly viewBox = computed(() => {
    const box = VIEWBOX[this.variant()];
    return `0 0 ${box.w} ${box.h}`;
  });

  protected readonly width = computed(() => {
    const box = VIEWBOX[this.variant()];
    return Math.round((this.size() * box.w) / box.h);
  });
}
