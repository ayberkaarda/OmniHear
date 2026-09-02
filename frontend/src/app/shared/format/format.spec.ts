import { EM_DASH, formatCount, formatDateTime, formatPercent, formatScore, truncate } from './format';

describe('format helpers', () => {
  it('renders an absent value rather than guessing at one', () => {
    expect(formatDateTime(null)).toBe(EM_DASH);
    expect(formatDateTime(undefined)).toBe(EM_DASH);
    expect(formatDateTime('not a date')).toBe(EM_DASH);
    expect(formatCount(null)).toBe(EM_DASH);
    expect(formatPercent(null)).toBe(EM_DASH);
    expect(formatScore(null)).toBe(EM_DASH);
    expect(formatScore(Number.NaN)).toBe(EM_DASH);
  });

  it('always writes the sign of a sentiment score, including at zero', () => {
    expect(formatScore(0.4213)).toBe('+0.42');
    expect(formatScore(-0.5497)).toBe('-0.55');
    // Zero is written unsigned: "+0.00" would suggest a positive reading.
    expect(formatScore(0)).toBe('0.00');
  });

  it('trims a long body on a word boundary and marks the cut', () => {
    const body = 'The application crashes every single time I try to sign in with my work account.';

    const short = truncate(body, 200);
    expect(short).toBe(body);

    const cut = truncate(body, 30);
    expect(cut.endsWith('…')).toBe(true);
    expect(cut.length).toBeLessThanOrEqual(31);
    expect(cut).not.toMatch(/\s…$/);
  });

  it('collapses the whitespace a multi-line review arrives with', () => {
    expect(truncate('  first line\n\n  second line  ', 100)).toBe('first line second line');
  });

  it('cuts mid-word only when there is no usable space to cut at', () => {
    expect(truncate('Supercalifragilisticexpialidocious', 10)).toBe('Supercalif…');
  });
});
