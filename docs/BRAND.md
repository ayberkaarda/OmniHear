# OmniHear brand, v2: "Marker"

The rules every screen, asset and email follows. Values live in
`frontend/src/styles/tokens.json` (the only source); `tokens.css` is generated
from it with `npm run tokens:build` and verified with `npm run tokens:check`.

---

## 0. Why v2 left v1 behind

1. v1 was a quiet grey page with a cobalt lamp; v2 is a dark-first night desk with one loud highlighter yellow.
2. v1 set everything in one calm newsroom grotesk; v2 runs a condensed, heavy display cut against a mono for every number.
3. v1 was a sidebar plus white table card; v2 has no sidebar and no cards: a command bar, big text tabs and full-bleed rows.
4. v1 hid the customer's words inside table cells; v2 makes the quote the largest thing on screen and marks what matters in it.
5. v1's sliced disc said "rows"; v2's mark says "this one": a word circled and highlighted, the two oldest editing marks there are.

---

## 1. Positioning

**What OmniHear is.** One inbox for everything customers say about a product:
App Store and Google Play reviews, Zendesk tickets, Trustpilot, e-mail and
Mastodon, each one read, scored for sentiment and sorted into a category.

**Who it is for.** Product, support and CX leads in B2B and consumer software
companies. Accountable for numbers, short on time, tired of dashboards that
look busy and say little.

**The promise.** *Every voice. One inbox.* (TR: *Her ses. Tek kutu.*)

**The concept: the marker.** An editor reads the whole transcript at night
with a highlighter in hand and marks the three words that matter. That is the
product: OmniHear reads every comment, and the interface is the transcript
with the marker already on it. Customers' words are the hero of every screen;
the yellow stroke says *this one, now*. Everything else stays dark and quiet so
the stroke can be loud.

**What the brand is not.** Not an "AI" brand: no sparkles, gradients, glowing
orbs, robots, and "AI" is never a headline. Not an admin template: no left
sidebar of icons, no grid of white cards, no pill badges in five pastels.

---

## 2. Voice and tone

| Do | Don't |
|---|---|
| Plain verbs: *read, sort, mark, reply, export* | *Unleash, supercharge, seamless, revolutionize* |
| Lead with the number that needs a person: "12 need a reply." | "Lots of new insights!" |
| Calm about failure: "Zendesk stopped answering at 09:12. We retry every 5 minutes." | "Something went wrong!!" |
| Customers' words exactly as written, Turkish or English | Paraphrasing a review to make it softer |
| Turkish and English written natively | Literal translation |

- Sentence case everywhere. Uppercase only in the mono label style (section 5).
- No exclamation marks, no emoji, no em dashes in UI copy.
- Numbers are real or marked as sample data.

---

## 3. Logo

### The mark: circled and highlighted

A chisel-cut marker stroke (a parallelogram, the shape a highlighter tip
leaves) with a heavy ring on it. The ring is the **O** of OmniHear and the
editor's circle around the one word that matters; the stroke is the
highlighter. "Omni" is the ring (the whole), "hear" is the act of marking.

Construction on a 32 unit grid (`public/logomark.svg`):

- Stroke: polygon (5,6) (31,6) (27,26) (1,26). Both ends slant by 4 units over
  20, the angle of a chisel tip. Fill `--signal`.
- Ring: centre (13,16), radius 5.8, stroke 3.4, always ink `#16131f`. Outer
  edge sits 2.6 units inside the stroke top and bottom.
- The ring is ink in both themes because it always sits on yellow. The mark
  needs no dark variant.

### Wordmark

`omnihear`, lowercase, Bricolage Grotesque at weight 800, width 75%, tracking
-1%, converted to outlines. x-height equals 15/32 of the mark height and the
baseline sits at y = 25 of the mark grid, so the wordmark's x-height band
centres on the ring. Gap between mark and wordmark: 10/32 of the mark height.
Lowercase is deliberate: the name is said, not shouted. In running text the
product is still written **OmniHear**.

### Files (all in `frontend/public/`)

| File | Use |
|---|---|
| `logo.svg` | Mark plus wordmark. Default in headers. |
| `logomark.svg` | Mark alone: avatars, collapsed states, loading. Theme independent. |
| `wordmark.svg` | Wordmark alone. |
| `favicon.svg`, `favicon.ico` | Night tile `#0e0d14` with the mark. |
| `apple-touch-icon.png` (180), `icon-512.png` | Home screen and manifest. |
| `og-image.png` (1200 x 630) | Link previews: night ground, poster headline, voiceprint. |

`logo.svg` and `wordmark.svg` colour the wordmark with `prefers-color-scheme`
(ink on light, paper on dark). When the app theme differs from the system
theme, inline the SVG and fill the wordmark with `var(--text-primary)`.

### Rules

- Clear space: the ring's outer radius (7.5/32 of mark height) on every side.
- Minimum size: mark 16 px, lockup 88 px wide.
- The stroke is always marker yellow; the ring is always ink. Single-colour
  reproduction: stroke solid, ring knocked out.
- On light paper the yellow stroke is low contrast by design (it is a
  highlighter); the ink ring carries the silhouette. Never add an outline.
- Do not straighten the slant, round the corners, add glow or gradients, or
  move the ring off the stroke.

---

## 4. Colour

### Concept: night ink, paper, one marker

- **Night ink** (violet-black, OKLCH hue about 290): the dark canvas and all
  text in light mode. Dark is the primary theme; the product is read in long
  sessions and the yellow is strongest on it.
- **Lilac paper**: the light canvas. Cool, slightly violet, never white and
  never cream.
- **Marker** (highlighter yellow, hue about 120, high chroma): the one gesture.
  Primary button, marked words, selected row, active view, keywords, the logo.
  It is a **fill**, with ink text on it, in both themes. It is text only on the
  night ground.

Data colours (section 6) never swap jobs with the marker: a sentiment is never
yellow, a primary button is never green or red.

### Core palette

| Token | Light | Dark | Role |
|---|---|---|---|
| `bg-canvas` | `#ecebf3` | `#0e0d14` | Page ground |
| `bg-surface` | `#f7f6fb` | `#15131d` | Panes, inputs |
| `bg-surface-raised` | `#fdfcff` | `#1d1a28` | Menus, popovers, tokens in the command bar |
| `bg-surface-sunken` | `#e2e0ec` | `#09080e` | Command bar, wells |
| `bg-surface-hover` | `#e7e5f0` | `#211e2d` | Row hover |
| `bg-surface-selected` | `#f1f8c4` | `#262a12` | Selected row (marker tint) |
| `border` | `#d6d3e2` | `#2a2638` | Hairlines between rows and panes |
| `border-strong` | `#827d97` | `#6c6681` | Input and checkbox edges (3:1) |
| `text-primary` | `#16131f` | `#f1effa` | Body, headings, customer quotes |
| `text-secondary` | `#3f3a52` | `#c4bfd6` | Read rows, supporting text |
| `text-muted` | `#5f5974` | `#9a94ae` | Labels, timestamps |
| `text-disabled` | `#a29db3` | `#57526a` | Disabled only |
| `brand` | `#d4f03c` | `#d9f542` | Primary button fill (the marker) |
| `brand-hover` / `-active` | `#c6e32c` / `#b7d41f` | `#e6ff63` / `#c7e333` | Button states |
| `brand-on` | `#16131f` | `#16131f` | Text on the marker, both themes |
| `brand-text` | `#4a5700` | `#d9f542` | Links, active labels (olive ink on paper, marker on night) |
| `brand-soft` | `#eef8b8` | `#2c3112` | Tinted backgrounds behind `brand-text` |
| `signal` | `#d4f03c` | `#d9f542` | The marker as a fill |
| `signal-ink` | `#4a5700` | `#d9f542` | The marker as text, icon or 1 px line |
| `signal-soft` | `#eef8b8` | `#2c3112` | Marker tint |
| `ring-focus` | `#16131f` | `#d9f542` | Focus ring, 2 px, 2 px offset |
| `highlight` | `#e2f57a` | `#4c5a0f` | Text selection, search match, new-row flash |

### Contrast (WCAG 2.x, sRGB relative luminance, computed from tokens.json)

| Pair | Light | Dark | Needed |
|---|---|---|---|
| `text-primary` on `bg-surface` | 17.03 | 16.16 | 4.5 |
| `text-secondary` on `bg-surface` | 10.08 | 10.30 | 4.5 |
| `text-muted` on `bg-surface` | 6.17 | 6.32 | 4.5 |
| `text-muted` on `bg-canvas` | 5.60 | 6.65 | 4.5 |
| `text-muted` on `bg-surface-sunken` | 5.08 | 6.86 | 4.5 |
| `text-primary` on `bg-surface-selected` | 16.53 | 12.98 | 4.5 |
| `brand-on` on `brand` (primary button) | 14.24 | 14.90 | 4.5 |
| `brand-on` on `brand-active` | 10.85 | 12.61 | 4.5 |
| `brand-text` / `signal-ink` on `bg-surface` | 7.36 | 14.95 | 4.5 |
| `brand-text` on `bg-canvas` | 6.68 | 15.73 | 4.5 |
| `text-primary` on `highlight` (selection) | 15.32 | 6.65 | 4.5 |
| `text-inverse` on `sentiment-negative-fill` (danger button) | 7.23 | 5.13 | 4.5 |
| `source-text` on `source-bg` | 8.70 | 9.14 | 4.5 |
| `ring-focus` on `bg-surface` | 17.03 | 14.95 | 3.0 |
| `border-strong` on `bg-surface` | 3.66 | 3.37 | 3.0 |
| semantic `-text` on `-bg` (all seven) | 7.03 to 7.81 | 10.05 to 10.52 | 4.5 |
| semantic `-fill` on `bg-surface` (all seven) | 3.16 to 9.30 | 3.71 to 11.51 | 3.0 |
| `signal` on `bg-surface` | 1.20 | 14.95 | none: fill only on light |

The last row is the one rule to remember: on light paper the marker is never
text, never an icon, never a thin line. Use `signal-ink` for those.

### Status

| Token | Light | Dark |
|---|---|---|
| `status-success` | `#05773b` | `#6ad18a` |
| `status-warning` | `#92600d` | `#f1af57` |
| `status-error` | `#b02b27` | `#f6857a` |
| `status-info` | `#4a5700` | `#d9f542` |
| `status-paused` | `#6a6580` | `#9a94ae` |

---

## 5. Typography

| Role | Face | Why |
|---|---|---|
| Display and UI | **Bricolage Grotesque** (variable, weight 200 to 800, width 75 to 100%) | One family, two voices. At width 75% and weight 750 to 800 it is a tight, poster-weight condensed display with ink-trap character; at width 100% and 400 to 600 it is a warm, readable text face. Distinct from Inter, Geist and the v1 newsroom grotesk. Full Turkish coverage. |
| Data | **Martian Mono** (variable, weight 100 to 800, width 75 to 112.5%) | Every number: sentiment scores, counts, timestamps, source labels, keys. Its width axis lets a score set huge and narrow (width 75%) or as a small, wide label (87.5%). |

Both are SIL OFL 1.1, self-hosted from `src/styles/fonts/` with the licences
beside the files; no font is loaded from a third-party host. Latin and
latin-ext subsets only. A metric-matched Arial fallback
(`Bricolage Grotesque Fallback`, size-adjust 111.3%) keeps body text from
shifting on swap.

### Scale: extreme contrast

| Token | Value | Face / setting | Use |
|---|---|---|---|
| `font-size-display` / `line-height-display` | clamp(64px, 9vw, 136px) / 0.9 | Bricolage, width 75%, 800, `tracking-display` -4.5% | Landing hero, the one statement per screen ("12 need a reply.") |
| `font-size-5xl` | 56 / 56 | Bricolage condensed 800 | Section posters |
| `font-size-4xl` to `2xl` | 36 to 24 | Bricolage condensed 700 to 750 | View tabs, pane titles |
| `font-size-xl`, `lg` | 20, 18 | Bricolage 400 to 500, width 100% | Customer quotes in rows (18 to 19 px) |
| `font-size-base` | 16 / 24 | Bricolage 400 | Body |
| `font-size-sm`, `xs` | 14, 12 | Bricolage 500 to 650 | Names, dense meta |
| Label | 10.5 px | Martian Mono, uppercase, width 87.5%, `tracking-label` 8% | Source, timestamps, legends. Never above every heading. |

- `h1` and `h2` are condensed by default (`styles.scss`); everything else is
  full width.
- A reading-pane quote sets at 44 to 56 px, weight 560, width 100%: the
  customer's sentence is the biggest thing in the product.
- Scores and counts are always mono with `tabular-nums slashed-zero`, and use
  the real minus sign.
- Body measure: 70 characters maximum.

---

## 6. Data colour: sentiment and category

Kept from v1 on purpose: the seven hues were tuned and verified for
deuteranopia and protanopia by `tokens:check`, and people who already read
them should not have to relearn. What changed is how they are drawn (section
7) and the neutral tones, which moved onto the violet ground.

| Group | Hue | Fill light / dark |
|---|---|---|
| `sentiment-negative` | red 27 | `#a21a1b` / `#d95b52` |
| `sentiment-neutral` | cool grey 248 | `#606a74` / `#95a0ab` |
| `sentiment-positive` | green 152 | `#259f56` / `#73e396` |
| `category-complaint` | amber 72 | `#bc7d19` / `#fbb961` |
| `category-praise` | teal 200 | `#068f94` / `#24c1c9` |
| `category-bug` | magenta 352 | `#922961` / `#c76292` |
| `category-feature-request` | violet 302 | `#59278d` / `#865bbc` |

- Colour is never the only carrier: the score number and its word sit beside
  every sentiment edge; every category square has its label.
- The marker yellow (hue about 120) sits in the 72 to 152 gap, away from every
  data hue, so a marked word is never mistaken for a sentiment.

---

## 7. Layout and components

**Command bar, not sidebar.** The app has one 60 px top bar: lockup, a
command line (`/` to focus, filters as tokens: `sentiment negative`,
`since 7 days`), quota meter, workspace, avatar. Navigation is a row of large
condensed text tabs (Inbox, Needs reply, Bugs, Praise, Channels, Overview); the
active one is marked with the marker.

**Statement header.** Each list opens with one poster-size sentence that says
what needs a person ("12 need a reply.") and the **voiceprint**: one bar per
comment, height = |score|, colour = sentiment, oldest to newest. It is the
product's signature graphic: everything customers said, as a sound wave.

**Full-bleed rows, no cards.** Rows run edge to edge, separated by one
hairline. Each row: a 6 px sentiment edge on the left, the score in mono, the
name and source label, then the quote at 18 to 19 px, category as a square plus
word, time in mono on the right. Unread rows use primary text, read rows
secondary text. The marker may land on the phrase that drove the score.

**Reading pane.** List on the left (440 px), the comment on the right as a
large quote with keywords marked, then one ruled row of three readings
(sentiment as an 84 px mono number on a -1 to +1 scale, category, keywords),
then "N people said the same".

**Shape.** Almost square: `radius-sm` 0, `radius-md` 2 (buttons, inputs,
keys), `radius-lg` 3 (panes, menus), `radius-xl` 4 (modals, sheets),
`radius-full` only for toggles. No pills. Category and sentiment are squares,
not chips.

**Lines over boxes.** No card containers, no cards in cards, no shadows on
anything that does not float. `shadow-md` is for menus and modals only.

**Buttons.** Primary: marker fill, ink text, 2 px corners. Secondary: 1.5 px
ink outline. Tertiary: text only, or text with a 2 px marker underline.

**Texture.** A fixed, pointer-events-none grain at 5% overlay opacity on
marketing pages only; never in the app.

**Spacing.** 4 px base (`space-unit`, reference only). Rows breathe more than
v1: 18 to 22 px vertical, 24 px page gutter.

---

## 8. Motion: the sweep

Motion is feedback, never decoration. The brand's one motion is the **sweep**:
the marker draws left to right behind a phrase (`.marker-sweep`, background
size 0 to 100%, `duration-slow`, `ease-emphasized`).

| Token | Value | Use |
|---|---|---|
| `duration-fast` | 90 ms | Hover, press |
| `duration-base` | 160 ms | Menus, tab change, row expand |
| `duration-slow` | 420 ms | The sweep, modals, pane transitions |
| `ease-standard` | `cubic-bezier(0.25, 0, 0, 1)` | Default |
| `ease-emphasized` | `cubic-bezier(0.7, 0, 0.15, 1)` | The sweep: slow start, fast stroke, soft stop, like a hand |

- A new comment over the realtime channel sweeps `highlight` across its row
  once. Selecting a row sweeps the marker into the active tab. Nothing loops.
- Animate `transform`, `opacity` and `background-size` only.
- Pressed controls move 1 px down; nothing scales.
- `prefers-reduced-motion: reduce` makes every sweep instant (global rule in
  `styles.scss`).

---

## 9. Light and dark

Dark is designed first and is the default for the marketing site and
screenshots; the app follows the system setting. Light is lilac paper with
night-ink text, not an inversion: the marker stays a fill, and every place
dark mode uses yellow as text or a thin line switches to `signal-ink` (olive).
Surfaces in dark step up in lightness as they come forward (sunken, canvas,
surface, raised).

The `.dark` class on `<html>` is set before first paint (`index.html`) and owned
by `ThemeService`; `index.html` theme-color metas are pinned to `bg-canvas` and
checked by `tokens:check`.

---

## 10. Token changes from v1

No token was removed or renamed; every v1 name still exists, so components
build unchanged. These names keep their slot but changed meaning, which is
where existing components will look different until they are redesigned:

| Token | v1 meaning | v2 meaning |
|---|---|---|
| `brand`, `brand-hover`, `brand-active` | Ink (light) / paper (dark) button | Marker yellow button in both themes |
| `brand-on` | Paper / ink | Ink in both themes |
| `brand-text`, `brand-soft` | Cobalt link and tint | Olive ink on paper, marker on night; marker tint |
| `signal`, `signal-soft` | Cobalt lamp, usable as text | Marker yellow, a fill only on light (use `signal-ink` for text) |
| `ring-focus` | Cobalt | Ink (light) / marker (dark) |
| `highlight`, `bg-surface-selected` | Cobalt tint | Marker tint |
| `status-info` | Cobalt | Olive / marker |
| `radius-sm/md/lg/xl` | 4 / 8 / 12 / 16 | 0 / 2 / 3 / 4 (Tailwind `rounded-control/card/sheet` follow) |
| `font-sans`, `font-mono` | Schibsted Grotesk, Spline Sans Mono | Bricolage Grotesque, Martian Mono |
| `font-size-5xl` | 48 / 52 | 56 / 56 |
| `tracking-tight`, `tracking-label` | -2%, 6% | -3%, 8% |
| `duration-*`, `ease-*` | 120 / 200 / 320 ms | 90 / 160 / 420 ms, sweep curve |

Added:

| Token | Value | Tailwind |
|---|---|---|
| `signal-ink` | `#4a5700` / `#d9f542` | `text-signal-ink`, `bg-signal-ink` |
| `font-display` | Bricolage Grotesque stack | `font-display` |
| `font-size-display`, `line-height-display` | clamp(64px, 9vw, 136px) / 0.9 | `text-poster` |
| `tracking-display` | -0.045em | `tracking-poster` |
| `font-stretch-condensed` | 75% | used by `h1`, `h2` in `styles.scss` |

Also new in `styles.scss`: the `.marker` and `.marker-sweep` component classes.
