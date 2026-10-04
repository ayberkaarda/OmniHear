# OmniHear brand

The rules every screen, asset and email follows. Values live in
`frontend/src/styles/tokens.json` (the only source); `tokens.css` is generated
from it with `npm run tokens:build` and verified with `npm run tokens:check`.

---

## 1. Positioning

**What OmniHear is.** One inbox for everything customers say about a product:
App Store and Google Play reviews, Zendesk tickets, Trustpilot, e-mail and
Mastodon, each one read, scored for sentiment and sorted into a category.

**Who it is for.** Product, support and CX leads in B2B and consumer software
companies. They are busy, they are accountable for numbers, and they distrust
dashboards that look exciting and say little.

**The promise.** *Every voice. One inbox.* (TR: *Her ses. Tek kutu.*) Nothing a
customer says is lost in a channel nobody watches, and the one message that
matters today is pulled out of the pile for you.

**The idea behind the identity: the switchboard.** A switchboard operator hears
every line at once and patches through the one call that needs a person. That is
the product. The visual world follows it: ink on paper, quiet rows, one lamp lit.

**What the brand is not.** Not an "AI" brand. Analysis is a feature, not the
personality, so there are no sparkles, gradients, glowing orbs or robot
imagery, and the word "AI" is never a headline.

---

## 2. Voice and tone

| Do | Don't |
|---|---|
| Plain verbs: *read, sort, flag, reply, export* | *Unleash, supercharge, seamless, revolutionize* |
| Name the source and the number: "14 new Play Store reviews, 3 negative" | "Lots of new insights!" |
| Calm about failure: "Zendesk stopped answering at 09:12. We retry every 5 minutes." | Alarm or blame: "Something went wrong!!" |
| Customers' words shown as they wrote them | Paraphrasing a review to make it softer |
| Turkish and English written natively, not translated word for word | Literal translation, English idioms in Turkish copy |

- Sentence case everywhere (buttons, headings, menu items). Never all caps
  except the label style in section 5.
- No exclamation marks in product UI. No emoji in product UI.
- No em dashes in UI copy. Use a full stop, a comma or a colon.
- Numbers are real or clearly marked as examples. No invented precision.

---

## 3. Logo

### The mark: the sliced disc

A disc cut into five horizontal slices: the rows of an inbox. The middle slice
is pulled out to the right in the signal colour: the one message that needs
you, lifted out of everything you heard. The disc is "omni" (the whole), the
rows are the inbox, the pulled slice is the act of hearing one voice.

Construction on a 32 unit grid (`public/logomark.svg`):

- Disc radius 13, centre (13, 16).
- Five slices, 3.6 high, 2.0 apart, from y = 3 to y = 29.
- The third slice moves +5 on x and takes `--signal`. Its ends keep the
  curvature of the disc it came from.
- Visual centre of the whole mark is x = 15.5 (disc plus pulled slice).

### Files (all in `frontend/public/`)

| File | Use |
|---|---|
| `logo.svg` | Mark plus wordmark, horizontal lockup. Default in headers. |
| `logomark.svg` | Mark alone: avatars, collapsed sidebar, loading state. |
| `wordmark.svg` | Wordmark alone, where the mark is already on screen. |
| `favicon.svg`, `favicon.ico` | Browser tab. Dark tile so it reads on light and dark tab bars. |
| `apple-touch-icon.png` (180), `icon-512.png` | Home screen and manifest icons. |
| `og-image.png` (1200 x 630) | Link previews. |

`logo.svg`, `logomark.svg` and `wordmark.svg` follow `prefers-color-scheme`
on their own (ink on light, paper on dark). When the app's manual theme
differs from the system theme, inline the SVG and colour it with
`var(--text-primary)` and `var(--signal)` instead of using `<img>`.

The wordmark is Schibsted Grotesk Bold, tracking -2%, converted to outlines so
it renders the same without the font loaded. Cap height equals 17/32 of the
mark height; the gap between mark and wordmark is 10/32 of the mark height.

### Rules

- Clear space: the height of one slice-plus-gap (5.6/32 of mark height) on
  every side, minimum.
- Minimum size: mark 16 px, lockup 96 px wide.
- The pulled slice is always the signal colour, or the same colour as the rest
  in single-colour reproduction (print, embossing). It is never another hue.
- Do not rotate, outline, add shadows, gradients or glow, rearrange the
  slices, or pull a different slice.
- The product name is written **OmniHear**: one word, capital O and H.

---

## 4. Colour

### Concept: ink, paper, one lamp

Three roles, nothing more:

- **Ink** (graphite with a faint lichen cast, OKLCH hue 150): text and the
  primary button. Corporate, quiet, prints well.
- **Paper**: surfaces. Cool off-white in light mode, deep graphite in dark.
  Never pure `#fff` or `#000`.
- **Signal** (cobalt, OKLCH hue 266): the lit lamp. Focus ring, links,
  selection, active navigation, the pulled slice. It means *this one*. It is
  never used for decoration, large fills or gradients.

The rest of the palette belongs to the data (section 6). Brand colour and data
colour never swap jobs: a primary button is ink, never green; a sentiment chip
is never cobalt.

### Core palette

| Token | Light | Dark | Role |
|---|---|---|---|
| `bg-canvas` | `#f1f3f1` | `#101210` | Page background |
| `bg-surface` | `#fcfdfc` | `#161917` | Cards, panels, tables |
| `bg-surface-raised` | `#fefffe` | `#1d201d` | Menus, modals |
| `bg-surface-sunken` | `#e8ebe9` | `#0b0d0b` | Wells, code, empty states |
| `bg-surface-hover` | `#edf0ee` | `#242824` | Row and control hover |
| `bg-surface-selected` | `#e6edfc` | `#222b3f` | Selected row, active nav (signal tint) |
| `border` | `#d8dcd9` | `#2e312e` | Dividers, card edges |
| `border-strong` | `#868b87` | `#626863` | Input and checkbox edges (3:1) |
| `text-primary` | `#141714` | `#eaeeeb` | Body, headings |
| `text-secondary` | `#414742` | `#c1c6c1` | Supporting text |
| `text-muted` | `#5f6560` | `#9da39e` | Meta, timestamps, captions |
| `text-disabled` | `#9ba09c` | `#606561` | Disabled only (exempt from contrast) |
| `brand` | `#141714` | `#eaeeeb` | Primary button fill (ink / paper) |
| `brand-hover` / `-active` | `#2a2f2b` / `#3a3f3a` | `#fafcfb` / `#cdd2ce` | Button states |
| `brand-on` | `#fcfdfc` | `#101210` | Text on primary button |
| `brand-text` | `#2347c5` | `#a0bdfe` | Links, active labels |
| `brand-soft` | `#e0eafe` | `#222f4f` | Badge and avatar tint behind `brand-text` |
| `signal` | `#2a52d8` | `#7aa1fe` | The lamp: pulled slice, indicators |
| `signal-soft` | `#e0eafe` | `#222f4f` | Signal tint |
| `ring-focus` | `#2a52d8` | `#7aa1fe` | Focus ring, 2 px, 2 px offset |
| `highlight` | `#d9e5fe` | `#223156` | Text selection, search match, row flash |

### Status

| Token | Light | Dark |
|---|---|---|
| `status-success` | `#05773b` | `#6ad18a` |
| `status-warning` | `#92600d` | `#f1af57` |
| `status-error` | `#b02b27` | `#f6857a` |
| `status-info` | `#2a52d8` | `#84a8fd` |
| `status-paused` | `#686d69` | `#9aa09b` |

All five pass 4.5:1 on `bg-surface` in both themes, so they may be used as
text, not only as icons.

---

## 5. Typography

| Role | Face | Why |
|---|---|---|
| UI and display | **Schibsted Grotesk** (variable 400 to 900) | Drawn for a newsroom: built to set dense information calmly, with a firm, slightly condensed rhythm. Distinct from the Inter / system-sans look without being decorative. Full Turkish coverage. |
| Data | **Spline Sans Mono** (variable 300 to 700) | Source ids, timestamps, counts, ticket numbers. Friendly, narrow enough for tables, clear 0/O and 1/l. |

Both are SIL OFL 1.1, self-hosted from `src/styles/fonts/` (licences beside
the files). No font is ever loaded from a third-party host. A metric-matched
Arial fallback (`Schibsted Grotesk Fallback`) keeps the swap from shifting
layout.

### Scale

| Token | Size / line | Weight | Use |
|---|---|---|---|
| `font-size-5xl` | 48 / 52 | 700, tracking -2% | Marketing display only |
| `font-size-4xl` | 36 / 40 | 700, tracking -2% | Page hero |
| `font-size-3xl` | 30 / 36 | 650 | Page title |
| `font-size-2xl` | 24 / 32 | 600 | Section title |
| `font-size-xl` | 20 / 28 | 600 | Card title |
| `font-size-lg` | 18 / 28 | 500 | Lead paragraph |
| `font-size-base` | 16 / 24 | 400 | Body |
| `font-size-sm` | 14 / 20 | 400 / 500 | Dense UI: tables, inbox rows, forms |
| `font-size-xs` | 12 / 16 | 500 | Meta, chips; label style below |

- **Label style:** 12 px, weight 600, uppercase, tracking `--tracking-label`
  (6%). At most one per screen region; never above every heading.
- Weights in use: 400, 500, 600, 700. Hierarchy comes from weight and colour
  before size.
- Numbers in tables and counters: `tabular-nums`. Mono for identifiers, not for
  prose.
- Body measure: 65 characters maximum.

---

## 6. Data colour: sentiment and category

The seven semantic hues are evenly separated around the colour wheel (minimum
gap 30 degrees in OKLCH) and tested for deuteranopia and protanopia by
`tokens:check`. Each group has four tokens: `-text`, `-bg`, `-border`, `-fill`.

| Group | Hue | Fill light / dark | Text on bg (L / D) |
|---|---|---|---|
| `sentiment-negative` | red 27 | `#a21a1b` / `#d95b52` | 7.81 / 10.10 |
| `sentiment-neutral` | cool grey 250 | `#606a74` / `#95a0ab` | 7.40 / 10.14 |
| `sentiment-positive` | green 152 | `#259f56` / `#73e396` | 7.12 / 10.25 |
| `category-complaint` | amber 72 | `#bc7d19` / `#fbb961` | 7.47 / 10.09 |
| `category-praise` | teal 200 | `#068f94` / `#24c1c9` | 7.28 / 10.30 |
| `category-bug` | magenta 352 | `#922961` / `#c76292` | 7.81 / 10.05 |
| `category-feature-request` | violet 302 | `#59278d` / `#865bbc` | 7.74 / 10.08 |

Rules:

- Colour is never the only carrier: every chip carries its word, every chart
  series a label or legend.
- Negative and positive also differ in lightness (OKLCH dL at least 0.15), so
  they separate in greyscale.
- Category fills step in lightness (complaint lightest, feature request
  darkest in light mode) so neighbours stay apart for colour-blind readers.
- Quota tokens are aliases: ok = neutral, warning = complaint, exceeded =
  negative.

---

## 7. Space, shape, elevation

- **Spacing:** 4 px base (`--space-unit`). Use 4, 8, 12, 16, 24, 32, 48, 64.
  Inbox rows: 12 vertical, 16 horizontal. Card padding: 16 (dense) or 24.
- **Radius:** one documented system. `radius-sm` 4 for chips and checkboxes,
  `radius-md` 8 for controls (buttons, inputs), `radius-lg` 12 for cards and
  panels, `radius-xl` 16 for modals and sheets, `radius-full` only for avatars
  and toggles. Tailwind names: `rounded-control`, `rounded-card`,
  `rounded-sheet`.
- **Elevation:** surfaces are separated by tone and a 1 px `border` first.
  `shadow-sm` for sticky bars, `shadow-md` only for things that float (menus,
  popovers, modals). Shadows are tinted with ink, never neutral black on light.
- **Lines over boxes.** Group with dividers and space before reaching for a
  card. No cards inside cards.

---

## 8. Motion

Motion is feedback, never decoration.

| Token | Value | Use |
|---|---|---|
| `duration-fast` | 120 ms | Hover, press, colour changes |
| `duration-base` | 200 ms | Menus, popovers, row expand |
| `duration-slow` | 320 ms | Modals, sheets, page-level transitions |
| `ease-standard` | `cubic-bezier(0.2, 0, 0, 1)` | Default |
| `ease-emphasized` | `cubic-bezier(0.32, 0.72, 0, 1)` | Entering surfaces |

- Animate `transform` and `opacity` only.
- A new feedback item arriving over the realtime channel flashes its row with
  `highlight` once and fades (`duration-slow`). Nothing loops.
- Pressed controls move 1 px down; nothing scales on hover.
- `prefers-reduced-motion: reduce` collapses every transition and animation
  (global rule in `styles.scss`).

---

## 9. Light and dark

Both themes are first class and share one hierarchy. Dark is not inverted
light: surfaces step up in lightness as they come forward (sunken, canvas,
surface, raised), shadows are replaced by a hairline plus a deep shadow, and
the primary button turns paper with ink text. The signal colour lightens so it
keeps the same weight on the dark ground.

The theme class `.dark` on `<html>` is set before first paint (`index.html`)
and owned by `ThemeService`.

---

## 10. Accessibility (measured)

WCAG 2.x contrast, sRGB relative luminance. `tokens:check` asserts the
semantic pairs; the rest were computed with the same formula.

| Pair | Light | Dark | Needed |
|---|---|---|---|
| `text-primary` on `bg-surface` | 17.72 | 15.12 | 4.5 |
| `text-secondary` on `bg-surface` | 9.34 | 10.22 | 4.5 |
| `text-muted` on `bg-surface` | 5.86 | 6.89 | 4.5 |
| `text-muted` on `bg-canvas` | 5.35 | 7.32 | 4.5 |
| `text-muted` on `bg-surface-sunken` | 4.97 | 7.58 | 4.5 |
| `text-primary` on `bg-surface-selected` | 15.39 | 12.07 | 4.5 |
| `brand-on` on `brand` (primary button) | 17.72 | 16.06 | 4.5 |
| `brand-on` on `brand-active` | 10.55 | 12.28 | 4.5 |
| `brand-text` on `bg-surface` | 7.41 | 9.45 | 4.5 |
| `brand-text` on `brand-soft` | 6.25 | 7.06 | 4.5 |
| `text-inverse` on `sentiment-negative-fill` (danger button) | 7.63 | 4.99 | 4.5 |
| `source-text` on `source-bg` | 8.37 | 8.63 | 4.5 |
| `ring-focus` on `bg-surface` | 6.25 | 7.05 | 3.0 |
| `border-strong` on `bg-surface` (input edge) | 3.40 | 3.10 | 3.0 |
| semantic `-text` on `-bg` (all seven) | 7.12 to 7.81 | 10.05 to 10.30 | 4.5 |
| semantic `-fill` on `bg-surface` (all seven) | 3.34 to 9.81 | 3.57 to 11.10 | 3.0 |

---

## 11. Token changes

Every token name the components used before this identity still exists with
the same meaning; only values changed. Nothing was removed or renamed.

| Token | Change | Note |
|---|---|---|
| `signal` | added | Cobalt lamp colour |
| `signal-soft` | added | Signal tint (same value as `brand-soft`) |
| `font-sans` | added | Schibsted Grotesk stack |
| `font-mono` | added | Spline Sans Mono stack |
| `font-size-5xl`, `line-height-5xl` | added | 48 / 52, marketing display |
| `tracking-tight` | added | -0.02em, display headings (Tailwind `tracking-display`) |
| `tracking-label` | added | 0.06em, label style (Tailwind `tracking-label`) |
| `radius-xl` | added | 16 px, modals and sheets |
| `space-unit` | added | 4 px base |
| `duration-fast`, `duration-base`, `duration-slow` | added | Motion durations |
| `ease-standard`, `ease-emphasized` | added | Motion curves |
| `brand` family | value change | Was slate blue; now ink (light) / paper (dark). Cobalt moved to `brand-text` and `signal`. |
| `border-strong` | value change | Raised to 3:1 against surfaces (WCAG 1.4.11 for input edges) |
| `shadow-sm`, `shadow-md` | value change | Ink-tinted; dark mode now has a hairline plus deep shadow instead of `none` |
| `--font-*` in Tailwind | value change | `font-sans` / `font-mono` now Schibsted Grotesk / Spline Sans Mono (were IBM Plex Sans / Mono) |
| Tailwind additions | added | `signal`, `signal-soft`, `rounded-control/card/sheet`, `duration-fast/base/slow`, `ease-standard/emphasized`, `shadow-brand-sm/md`, `tracking-display/label`. Tailwind's own default scales are untouched. |
