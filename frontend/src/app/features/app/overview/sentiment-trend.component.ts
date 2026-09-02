import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';

import { TrendPoint } from '../../../core/overview/overview.models';
import { formatCount, formatDate, formatDayMonth, formatScore } from '../../../shared/format/format';

/** Drawing surface, in user units. `preserveAspectRatio="none"` stretches it to the container. */
const VIEW_WIDTH = 600;
const VIEW_HEIGHT = 160;
/** Keeps a point at exactly +1 or -1 from being clipped by the viewBox edge. */
const PADDING_Y = 8;
const MS_PER_DAY = 86_400_000;

export interface TrendMark {
  readonly key: string;
  readonly x: number;
  readonly y: number;
  readonly toneVar: string;
  readonly point: TrendPoint;
}

/**
 * Daily average sentiment, drawn as inline SVG.
 *
 * **No charting library, by measurement rather than preference.** The initial
 * bundle has 12.91 kB of brotli headroom (ADR-0008) and the smallest credible
 * charting dependency is several times that — `pusher-js` alone was measured at
 * 15.62 kB. Thirty points and one line do not justify spending the budget the
 * rest of the application still has to fit in.
 *
 * Two rules from `.claude/skills/omnihear-tokens` shape what is drawn:
 *
 * - **Colour is never the only channel** (section 6). A mark's vertical
 *   position relative to the zero baseline says the same thing its colour does,
 *   the score is written out in the table view, and "show as table" is one
 *   click away.
 * - **`-fill` tokens only, and only for filled shapes** (section 3). The marks
 *   are filled; every label around them uses `-text`.
 *
 * Days with no analysis are absent from `trend` rather than zero — a zero
 * average would draw as neutral, which is a different claim from "nothing was
 * analysed". So the x axis is a real time scale and a gap in the data shows up
 * as a gap between marks, not as a straight run of neutral days.
 */
@Component({
  selector: 'app-sentiment-trend',
  standalone: true,
  imports: [],
  templateUrl: './sentiment-trend.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class SentimentTrendComponent {
  readonly points = input.required<readonly TrendPoint[]>();
  readonly loading = input(false);

  protected readonly showTable = signal(false);

  protected readonly viewBox = `0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`;
  protected readonly zeroY = VIEW_HEIGHT / 2;
  protected readonly viewWidth = VIEW_WIDTH;

  protected readonly hasData = computed(() => this.points().length > 0);

  /** Chronological, and defensive about it: the series is drawn left to right. */
  protected readonly ordered = computed<readonly TrendPoint[]>(() =>
    [...this.points()].sort((a, b) => a.date.localeCompare(b.date))
  );

  protected readonly marks = computed<readonly TrendMark[]>(() => {
    const points = this.ordered();
    if (points.length === 0) {
      return [];
    }

    const times = points.map((point) => Date.parse(point.date));
    const first = times[0];
    const last = times[times.length - 1];
    const span = last - first;

    return points.map((point, index) => ({
      key: point.date,
      // A single observation, or several on the same day, sit in the middle
      // rather than dividing by a zero span.
      x: span === 0 ? VIEW_WIDTH / 2 : ((times[index] - first) / span) * VIEW_WIDTH,
      y: scoreToY(point.average_sentiment),
      toneVar: toneVarFor(point.average_sentiment),
      point
    }));
  });

  /** `null` for a single observation: one point is not a trend, and a line would imply one. */
  protected readonly linePath = computed<string | null>(() => {
    const marks = this.marks();
    if (marks.length < 2) {
      return null;
    }
    return marks.map((mark, index) => `${index === 0 ? 'M' : 'L'}${mark.x.toFixed(2)} ${mark.y.toFixed(2)}`).join(' ');
  });

  protected readonly firstDateLabel = computed(() => formatDayMonth(this.ordered()[0]?.date ?? null));
  protected readonly lastDateLabel = computed(() =>
    formatDayMonth(this.ordered()[this.ordered().length - 1]?.date ?? null)
  );

  /**
   * True when the window has days the series does not cover, so the caption can
   * say the line skips them instead of letting it read as continuous data.
   */
  protected readonly hasGaps = computed(() => {
    const points = this.ordered();
    if (points.length < 2) {
      return false;
    }
    const spanDays = Math.round((Date.parse(points[points.length - 1].date) - Date.parse(points[0].date)) / MS_PER_DAY);
    return spanDays + 1 > points.length;
  });

  protected readonly summary = computed(() => {
    const points = this.ordered();
    if (points.length === 0) {
      return '';
    }
    const total = points.reduce((sum, point) => sum + point.average_sentiment, 0);
    const average = formatScore(total / points.length);
    return $localize`:Accessible summary of the sentiment trend chart@@overview.trend.summary:Daily average sentiment across ${points.length}:days: days, averaging ${average}:average: on a scale from minus one to plus one.`;
  });

  protected readonly toggleLabel = computed(() =>
    this.showTable()
      ? $localize`:Toggle back from the data table to the chart@@overview.trend.showChart:Show as chart`
      : $localize`:Toggle from the chart to a data table@@overview.trend.showTable:Show as table`
  );

  protected score(point: TrendPoint): string {
    return formatScore(point.average_sentiment);
  }

  protected count(point: TrendPoint): string {
    return formatCount(point.count);
  }

  protected day(point: TrendPoint): string {
    return formatDate(point.date);
  }

  protected toggleTable(): void {
    this.showTable.update((value) => !value);
  }
}

function scoreToY(score: number): number {
  const clamped = Math.max(-1, Math.min(1, score));
  const usable = VIEW_HEIGHT - PADDING_Y * 2;
  return PADDING_Y + ((1 - clamped) / 2) * usable;
}

/**
 * A mark is neutral inside a narrow band around zero rather than at exactly
 * zero: an average of 0.004 is neutral in every sense that matters, and
 * colouring it positive would overstate what the number says.
 */
function toneVarFor(score: number): string {
  if (score > 0.05) {
    return 'var(--sentiment-positive-fill)';
  }
  if (score < -0.05) {
    return 'var(--sentiment-negative-fill)';
  }
  return 'var(--sentiment-neutral-fill)';
}
