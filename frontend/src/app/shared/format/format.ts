/**
 * Number and date formatting for the `/app` screens.
 *
 * Built on `Intl`, never on `DatePipe`/`DecimalPipe`. That is a budget
 * decision as much as a style one: the `@angular/common` pipes drag the
 * framework's own formatting and locale-data machinery into the bundle, while
 * `Intl` is already in the browser and costs nothing. `kpi-card` set the
 * precedent with `Intl.NumberFormat`.
 *
 * The locale comes from `<html lang>`, which the localized build writes per
 * locale (`lang="en"` / `lang="tr"`, verified in the localized dist output).
 * Taking it from there rather than from the browser is what keeps a date in the
 * same language as the sentence around it: an English build read in a Turkish
 * browser would otherwise print "1 Eyl 2026" next to English copy. When the
 * attribute is empty — a bare test host — it falls back to the platform locale.
 */

function documentLocale(): string | undefined {
  if (typeof document === 'undefined') {
    return undefined;
  }
  const lang = document.documentElement.getAttribute('lang');
  return lang !== null && lang.trim().length > 0 ? lang : undefined;
}

const LOCALE = documentLocale();


const DATE_TIME_FORMAT = new Intl.DateTimeFormat(LOCALE, {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit'
});

const DATE_FORMAT = new Intl.DateTimeFormat(LOCALE, {
  year: 'numeric',
  month: 'short',
  day: 'numeric'
});

const DAY_MONTH_FORMAT = new Intl.DateTimeFormat(LOCALE, { month: 'short', day: 'numeric' });

const INTEGER_FORMAT = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 });

const PERCENT_FORMAT = new Intl.NumberFormat(LOCALE, { style: 'percent', maximumFractionDigits: 0 });

/** `null` in, em dash out: an absent timestamp is rendered, never guessed at. */
export function formatDateTime(iso: string | null | undefined): string {
  return formatWith(DATE_TIME_FORMAT, iso);
}

export function formatDate(iso: string | null | undefined): string {
  return formatWith(DATE_FORMAT, iso);
}

export function formatDayMonth(iso: string | null | undefined): string {
  return formatWith(DAY_MONTH_FORMAT, iso);
}

export function formatCount(value: number | null | undefined): string {
  return value === null || value === undefined ? EM_DASH : INTEGER_FORMAT.format(value);
}

export function formatPercent(ratio: number | null | undefined): string {
  return ratio === null || ratio === undefined ? EM_DASH : PERCENT_FORMAT.format(ratio);
}

/**
 * Sentiment scores are signed and small; the sign is part of the reading, so it
 * is always written out. Two decimals matches the API, which rounds to four and
 * whose extra digits are noise at this scale.
 */
export function formatScore(score: number | null | undefined): string {
  if (score === null || score === undefined || Number.isNaN(score)) {
    return EM_DASH;
  }
  const sign = score > 0 ? '+' : '';
  return `${sign}${score.toFixed(2)}`;
}

/** Trims a long comment body for a table cell without cutting a word in half. */
export function truncate(text: string, maxLength: number): string {
  const collapsed = text.replace(/\s+/g, ' ').trim();
  if (collapsed.length <= maxLength) {
    return collapsed;
  }
  const cut = collapsed.slice(0, maxLength);
  const lastSpace = cut.lastIndexOf(' ');
  return `${(lastSpace > maxLength * 0.6 ? cut.slice(0, lastSpace) : cut).trimEnd()}…`;
}

export const EM_DASH = '—';

function formatWith(formatter: Intl.DateTimeFormat, iso: string | null | undefined): string {
  if (!iso) {
    return EM_DASH;
  }
  const parsed = new Date(iso);
  return Number.isNaN(parsed.getTime()) ? EM_DASH : formatter.format(parsed);
}
