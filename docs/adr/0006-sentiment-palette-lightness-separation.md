# ADR-0006 — Red/green sentiment palette with mandatory lightness separation

- **Status:** Accepted
- **Date:** 2026-09-02
- **Phase:** F1 (design-system layer, landed ahead of F7)
- **Related spec:** §4 "Erişilebilirlik: WCAG 2.1 AA" · design brief §4, §2, §11

## Context

Two palettes existed. An earlier internal proposal used an orange↔blue diverging
sentiment scale and banned red/green outright, on the argument that red and green
collapse under deuteranopia. The user then authored a design system in Claude Design
using **red/green** sentiment, a slate brand (`#3f4a63`), and a `.dark` class strategy —
and their own written brief specifies a green/red sentiment family, the class strategy,
and explicitly bans purple/neon "AI aesthetics". The earlier proposal contradicted the
user's own written specification.

The colour-blindness argument was then measured rather than asserted. A Viénot 1999
simulation, calibrated first against known cases (pure red/green must collapse, blue/yellow
must survive, blue must stay blue), gave:

| Pair | Normal | Deuteranopia |
|---|---|---|
| Design red/green fill `#dc4b4b` / `#22a55a` | 0.304 | **0.030** |
| Orange/blue fill `#EA580C` / `#2563EB` | 0.396 | 0.375 |

dE_ok ≈ 0.10 is the threshold for a noticeable difference, so 0.030 means
indistinguishable — the two simulate to `#8b8b43` and `#8e8e5d`.

The root cause is not the hues. It is that the design's red and green were chosen at
**near-identical perceptual lightness** so the badges would feel balanced. Pure red and
green still retain 0.225 under simulation precisely because their lightness differs.
A hue corridor rule does not fix this; both hues collapse to the same yellow.

## Decision

Keep the user's red/green sentiment palette. Add a hard rule: the sentiment `-fill`
triple is ordered by **lightness** — negative dark → neutral → positive light — with
machine-checked thresholds.

`tokens-check` enforces, in both themes:

- negative/positive `-fill`: deuteranopia dE ≥ 0.15, protanopia dE ≥ 0.12, OKLCH ΔL ≥ 0.15
- adjacent sentiment fills: deuteranopia dE ≥ 0.12
- every `-fill` ≥ 3.0 against its surface
- seven semantic fill hues ≥ 30° apart

Measured after the change: negative/positive deuteranopia **0.205 light / 0.235 dark**
(from 0.030), protanopia 0.331 / 0.343, ΔL 0.21 / 0.24, lowest fill contrast 3.30 / 3.57.

## Alternatives considered

- **Orange↔blue.** Rejected: contradicts the user's brief twice over, and discards the
  green=good convention that CX tooling users already carry.
- **Red/green at equal lightness, relying on icons alone.** Rejected for charts, where
  colour is the only channel. Accepted for badges (see below).
- **Lighten the badge backgrounds toward pastel.** Rejected: measured chip-against-white
  separation falls to 1.09, making the chip invisible. Pastel was achieved by lowering
  chroma at constant lightness instead.

## Consequences

**Positive.** The user's palette survives intact. Sentiment fills are distinguishable
under both common dichromacies. Chart fills carry meaning without relying on hue alone.

**Accepted trade-off — stated plainly.** Badge `-text`/`-bg`/`-border` values keep a
single pastel formula and are **not** lightness-separated; the negative/positive text
pair sits around dE 0.025 under deuteranopia. This is deliberate: badges always render
an icon, a label and (for sentiment) a numeric score, so colour is a redundant channel
there. It is a trade-off, not a clean result.

**Known warning.** `category-praise` / `category-bug` fills sit at deuteranopia
0.084–0.092, below the 0.10 category threshold. `tokens-check` reports this as a warning
rather than a failure because the mitigation lives in code: pattern fills (decal) are
mandatory on category charts.

**Process consequence.** `tokens-check` runs calibration assertions *before* any other
check and aborts everything if they fail. This exists because an uncalibrated simulation
produced wrong numbers during this phase and nearly drove the opposite decision. Those
assertions are the only thing that would catch the same class of error again.
