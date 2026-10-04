import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

export type VoiceprintTone = 'positive' | 'neutral' | 'negative' | 'pending';

interface VoiceprintBar {
  readonly id: number;
  readonly tone: VoiceprintTone;
  /** Bar height as a percentage of the strip. */
  readonly height: number;
}

/** Shortest bar, so a neutral or unread comment still leaves a visible mark. */
const MIN_HEIGHT = 8;

/**
 * The voiceprint (docs/BRAND.md section 7): one bar per comment, oldest to
 * newest, height = |score|, colour = sentiment. Everything customers said, as
 * a sound wave.
 *
 * - `values`: sentiment scores from -1 to +1; `null` is a comment not read yet.
 * - `neutralBand`: scores within plus or minus this value count as neutral
 *   (0.05 by default, the threshold the overview trend uses).
 * - `label`: when given, the strip is an image with that accessible name;
 *   without it the strip is decoration and hidden from assistive tech. Colour
 *   is never the only carrier: the page states the counts in text.
 * - `height`: strip height in px.
 *
 * With no values it draws a single flat line: nothing heard yet.
 */
@Component({
  selector: 'app-voiceprint',
  standalone: true,
  template: `
    <div
      class="vp"
      [style.height.px]="height()"
      [attr.role]="label() ? 'img' : null"
      [attr.aria-label]="label() ?? null"
      [attr.aria-hidden]="label() ? null : 'true'"
      data-testid="voiceprint"
    >
      @for (bar of bars(); track bar.id) {
        <i [class]="'vp-' + bar.tone" [style.height.%]="bar.height"></i>
      } @empty {
        <span class="vp-flat"></span>
      }
    </div>
  `,
  styles: `
    :host {
      display: block;
    }

    .vp {
      display: flex;
      align-items: center;
      gap: 2px;
      overflow: hidden;
    }

    i {
      flex: 1 1 0;
      min-width: 2px;
      background: var(--tone);
    }

    .vp-positive {
      --tone: var(--sentiment-positive-fill);
    }

    .vp-neutral {
      --tone: var(--sentiment-neutral-fill);
    }

    .vp-negative {
      --tone: var(--sentiment-negative-fill);
    }

    .vp-pending {
      --tone: var(--border-strong);
    }

    .vp-flat {
      flex: 1;
      height: 1px;
      background: var(--border-strong);
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class VoiceprintComponent {
  readonly values = input<readonly (number | null)[]>([]);
  readonly height = input(64);
  readonly label = input<string | undefined>(undefined);
  readonly neutralBand = input(0.05);

  protected readonly bars = computed<VoiceprintBar[]>(() => {
    const band = this.neutralBand();
    return this.values().map((score, id) => {
      if (score === null || Number.isNaN(score)) {
        return { id, tone: 'pending', height: MIN_HEIGHT };
      }
      const clamped = Math.max(-1, Math.min(1, score));
      const tone: VoiceprintTone = clamped > band ? 'positive' : clamped < -band ? 'negative' : 'neutral';
      return { id, tone, height: Math.max(MIN_HEIGHT, Math.round(Math.abs(clamped) * 100)) };
    });
  });
}
