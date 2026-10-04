/**
 * Splits a customer's text into plain and marked runs, so a template can put
 * the brand's marker behind the words the analyser keyed on without ever
 * building HTML from a string (no `innerHTML`, nothing to sanitize).
 *
 * Matching is case-insensitive and literal. Overlapping hits keep the first
 * one found in reading order. The runs always join back to the exact input.
 *
 * Case folding never runs over the whole string: some characters change
 * length when folded (`İ` lowercases to `i` plus a combining dot, `ß` folds to
 * `ss`), and an index found in a folded copy would then point at the wrong
 * characters of the original. Each character is folded on its own and every
 * folded unit remembers which original character it came from, so a hit is
 * mapped back to whole original characters. Folding uses the locale-free
 * `toLowerCase` / `toUpperCase`, never the browser's locale, so `I`, `ı`, `i`
 * and `İ` all meet at `i` whatever language the page or the text is in.
 */
export interface TextRun {
  readonly text: string;
  readonly marked: boolean;
}

interface Folded {
  /** The folded text. */
  readonly text: string;
  /** For each UTF-16 unit of `text`: start of the original character it came from. */
  readonly from: readonly number[];
  /** For each UTF-16 unit of `text`: end of the original character it came from. */
  readonly to: readonly number[];
}

/** One character, case-folded without a locale. */
function foldChar(char: string): string {
  // `İ` would fold to `i` + U+0307; the dot carries no case and would stop
  // `iyi` from matching `İyi`.
  // Lower first so `ẞ` reaches `ß`, whose upper case is `SS`.
  return char === 'İ' ? 'i' : char.toLowerCase().toUpperCase().toLowerCase();
}

function fold(text: string): Folded {
  let folded = '';
  const from: number[] = [];
  const to: number[] = [];
  let index = 0;
  // `for...of` walks code points, so a surrogate pair stays one character.
  for (const char of text) {
    const end = index + char.length;
    const piece = foldChar(char);
    folded += piece;
    // One entry per UTF-16 unit of the piece, not per code point.
    from.push(...new Array<number>(piece.length).fill(index));
    to.push(...new Array<number>(piece.length).fill(end));
    index = end;
  }
  return { text: folded, from, to };
}

export function markRuns(text: string, keywords: readonly string[], limit = Number.POSITIVE_INFINITY): readonly TextRun[] {
  const haystack = fold(text);
  const hits: { start: number; end: number }[] = [];

  for (const keyword of keywords) {
    const needle = fold(keyword.trim()).text;
    if (needle.length < 2) {
      continue;
    }
    let from = 0;
    while (from <= haystack.text.length) {
      const start = haystack.text.indexOf(needle, from);
      if (start === -1) {
        break;
      }
      const last = start + needle.length - 1;
      // Back to the original: whole characters, from the first one touched
      // to the end of the last one touched.
      hits.push({ start: haystack.from[start], end: haystack.to[last] });
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
