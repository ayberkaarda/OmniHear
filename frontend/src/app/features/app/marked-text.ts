/**
 * Splits a customer's text into plain and marked runs, so a template can put
 * the brand's marker behind the words the analyser keyed on without ever
 * building HTML from a string (no `innerHTML`, nothing to sanitize).
 *
 * Matching is case-insensitive and literal. Overlapping hits keep the first
 * one found in reading order. The runs always join back to the exact input.
 */
export interface TextRun {
  readonly text: string;
  readonly marked: boolean;
}

export function markRuns(text: string, keywords: readonly string[], limit = Number.POSITIVE_INFINITY): readonly TextRun[] {
  const lower = text.toLocaleLowerCase();
  const hits: { start: number; end: number }[] = [];

  for (const keyword of keywords) {
    const needle = keyword.trim().toLocaleLowerCase();
    if (needle.length < 2) {
      continue;
    }
    let from = 0;
    while (from <= lower.length) {
      const start = lower.indexOf(needle, from);
      if (start === -1) {
        break;
      }
      hits.push({ start, end: start + needle.length });
      from = start + needle.length;
    }
  }

  hits.sort((a, b) => a.start - b.start || b.end - a.end);

  const runs: TextRun[] = [];
  let cursor = 0;
  let used = 0;
  for (const hit of hits) {
    if (used >= limit) {
      break;
    }
    if (hit.start < cursor) {
      continue;
    }
    if (hit.start > cursor) {
      runs.push({ text: text.slice(cursor, hit.start), marked: false });
    }
    runs.push({ text: text.slice(hit.start, hit.end), marked: true });
    cursor = hit.end;
    used += 1;
  }
  if (cursor < text.length) {
    runs.push({ text: text.slice(cursor), marked: false });
  }
  return runs;
}
