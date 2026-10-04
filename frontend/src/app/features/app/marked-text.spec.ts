import { markRuns, TextRun } from './marked-text';

function marked(runs: readonly TextRun[]): string[] {
  return runs.filter((run) => run.marked).map((run) => run.text);
}

function joined(runs: readonly TextRun[]): string {
  return runs.map((run) => run.text).join('');
}

describe('markRuns', () => {
  it('marks a keyword case-insensitively and joins back to the exact input', () => {
    const text = 'The app CRASHES on sign in.';
    const runs = markRuns(text, ['crash']);
    expect(marked(runs)).toEqual(['CRASH']);
    expect(joined(runs)).toBe(text);
  });

  /**
   * `İ` lowercases to two units (`i` + U+0307). Folding the whole string shifted
   * every later index by one, and `rash!` was marked instead of `crash`.
   */
  it('keeps the original indices after a capital dotted İ', () => {
    const text = 'İyi crash!';
    const runs = markRuns(text, ['crash']);
    expect(runs).toEqual([
      { text: 'İyi ', marked: false },
      { text: 'crash', marked: true },
      { text: '!', marked: false }
    ]);
  });

  it('matches the Turkish i forms whatever the browser locale', () => {
    expect(marked(markRuns('İYİ bir uygulama', ['iyi']))).toEqual(['İYİ']);
    expect(marked(markRuns('ILIK karşılama', ['ılık']))).toEqual(['ILIK']);
    expect(marked(markRuns('Çok İyi', ['İYİ']))).toEqual(['İyi']);
  });

  it('maps a hit through characters that fold to a different length', () => {
    // ß folds to "ss": the needle "strasse" covers one original ß.
    const street = 'Die Straße ist kaputt';
    const runs = markRuns(street, ['STRASSE']);
    expect(marked(runs)).toEqual(['Straße']);
    expect(joined(runs)).toBe(street);

    // Text after the ß keeps its own indices.
    expect(marked(markRuns('Straße kaputt', ['kaputt']))).toEqual(['kaputt']);

    // A capital sharp s reaches the same fold.
    expect(marked(markRuns('STRAẞE', ['straße']))).toEqual(['STRAẞE']);
  });

  it('folds title-case digraphs like ǅ', () => {
    const runs = markRuns('ǅep and more', ['ǆep']);
    expect(marked(runs)).toEqual(['ǅep']);
  });

  it('never splits a surrogate pair or an expanding character', () => {
    const text = '😀 ß crash';
    const runs = markRuns(text, ['s crash']);
    // "s" lands inside the fold of ß, so the whole ß is marked.
    expect(marked(runs)).toEqual(['ß crash']);
    expect(joined(runs)).toBe(text);
  });

  it('keeps the first of overlapping hits and honours the limit', () => {
    const runs = markRuns('crash crash', ['crash'], 1);
    expect(marked(runs)).toEqual(['crash']);
    expect(joined(runs)).toBe('crash crash');
  });
});
