/**
 * Small, static registry of inline icon glyphs shared by the design-system
 * components (badge, kpi-card, form-field, data-table, modal, ...).
 *
 * All icons are decorative (aria-hidden) — any accessible name is carried by
 * surrounding text or an explicit aria-label on the consuming element, never
 * by the icon itself.
 */

export type IconName =
  | 'smile'
  | 'meh'
  | 'frown'
  | 'megaphone'
  | 'heart'
  | 'bug'
  | 'lightbulb'
  | 'check-circle'
  | 'x-circle'
  | 'pause-circle'
  | 'alert-triangle'
  | 'info'
  | 'arrow-up'
  | 'arrow-down'
  | 'chevron-down'
  | 'chevron-up'
  | 'search'
  | 'mail'
  | 'lock'
  | 'user'
  | 'eye'
  | 'eye-off'
  | 'calendar'
  | 'phone'
  | 'tag'
  | 'link'
  | 'x'
  | 'check'
  | 'plus';

export type IconShapeTag = 'path' | 'circle' | 'line' | 'polyline' | 'rect';

export interface IconShape {
  tag: IconShapeTag;
  attrs: Record<string, string | number>;
}

export interface IconDef {
  viewBox: string;
  shapes: IconShape[];
}

/** 24x24, stroke-based glyphs (Lucide-style), stroke="currentColor". */
export const ICON_REGISTRY: Record<IconName, IconDef> = {
  smile: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } },
      { tag: 'path', attrs: { d: 'M8 14s1.5 2 4 2 4-2 4-2' } },
      { tag: 'line', attrs: { x1: 9, y1: 9, x2: 9.01, y2: 9 } },
      { tag: 'line', attrs: { x1: 15, y1: 9, x2: 15.01, y2: 9 } }
    ]
  },
  meh: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } },
      { tag: 'line', attrs: { x1: 8, y1: 15, x2: 16, y2: 15 } },
      { tag: 'line', attrs: { x1: 9, y1: 9, x2: 9.01, y2: 9 } },
      { tag: 'line', attrs: { x1: 15, y1: 9, x2: 15.01, y2: 9 } }
    ]
  },
  frown: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } },
      { tag: 'path', attrs: { d: 'M16 16s-1.5-2-4-2-4 2-4 2' } },
      { tag: 'line', attrs: { x1: 9, y1: 9, x2: 9.01, y2: 9 } },
      { tag: 'line', attrs: { x1: 15, y1: 9, x2: 15.01, y2: 9 } }
    ]
  },
  megaphone: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'path', attrs: { d: 'm3 11 18-6v14L3 15v-4Z' } },
      { tag: 'path', attrs: { d: 'M11.6 16.8a3 3 0 1 1-5.8-1.6' } }
    ]
  },
  heart: {
    viewBox: '0 0 24 24',
    shapes: [
      {
        tag: 'path',
        attrs: {
          d: 'M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'
        }
      }
    ]
  },
  bug: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'path', attrs: { d: 'M9 7.13V6a3 3 0 1 1 6 0v1.13' } },
      { tag: 'path', attrs: { d: 'M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6Z' } },
      { tag: 'line', attrs: { x1: 12, y1: 11, x2: 12, y2: 20 } },
      { tag: 'line', attrs: { x1: 6, y1: 13, x2: 3, y2: 13 } },
      { tag: 'line', attrs: { x1: 21, y1: 13, x2: 18, y2: 13 } },
      { tag: 'line', attrs: { x1: 4, y1: 20, x2: 7, y2: 17 } },
      { tag: 'line', attrs: { x1: 20, y1: 20, x2: 17, y2: 17 } }
    ]
  },
  lightbulb: {
    viewBox: '0 0 24 24',
    shapes: [
      {
        tag: 'path',
        attrs: { d: 'M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1.3.5 2.6 1.5 3.5.8.8 1.3 1.5 1.5 2.5' }
      },
      { tag: 'line', attrs: { x1: 9, y1: 18, x2: 15, y2: 18 } },
      { tag: 'line', attrs: { x1: 10, y1: 22, x2: 14, y2: 22 } }
    ]
  },
  'check-circle': {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } },
      { tag: 'path', attrs: { d: 'm9 12 2 2 4-4' } }
    ]
  },
  'x-circle': {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } },
      { tag: 'line', attrs: { x1: 15, y1: 9, x2: 9, y2: 15 } },
      { tag: 'line', attrs: { x1: 9, y1: 9, x2: 15, y2: 15 } }
    ]
  },
  'pause-circle': {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } },
      { tag: 'line', attrs: { x1: 10, y1: 9, x2: 10, y2: 15 } },
      { tag: 'line', attrs: { x1: 14, y1: 9, x2: 14, y2: 15 } }
    ]
  },
  'alert-triangle': {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'path', attrs: { d: 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z' } },
      { tag: 'line', attrs: { x1: 12, y1: 9, x2: 12, y2: 13 } },
      { tag: 'line', attrs: { x1: 12, y1: 17, x2: 12.01, y2: 17 } }
    ]
  },
  info: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } },
      { tag: 'line', attrs: { x1: 12, y1: 16, x2: 12, y2: 12 } },
      { tag: 'line', attrs: { x1: 12, y1: 8, x2: 12.01, y2: 8 } }
    ]
  },
  'arrow-up': {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'line', attrs: { x1: 12, y1: 19, x2: 12, y2: 5 } },
      { tag: 'polyline', attrs: { points: '5 12 12 5 19 12' } }
    ]
  },
  'arrow-down': {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'line', attrs: { x1: 12, y1: 5, x2: 12, y2: 19 } },
      { tag: 'polyline', attrs: { points: '19 12 12 19 5 12' } }
    ]
  },
  'chevron-down': {
    viewBox: '0 0 24 24',
    shapes: [{ tag: 'polyline', attrs: { points: '6 9 12 15 18 9' } }]
  },
  'chevron-up': {
    viewBox: '0 0 24 24',
    shapes: [{ tag: 'polyline', attrs: { points: '18 15 12 9 6 15' } }]
  },
  search: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'circle', attrs: { cx: 11, cy: 11, r: 8 } },
      { tag: 'line', attrs: { x1: 21, y1: 21, x2: 16.65, y2: 16.65 } }
    ]
  },
  mail: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'rect', attrs: { x: 2, y: 4, width: 20, height: 16, rx: 2 } },
      { tag: 'path', attrs: { d: 'm22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7' } }
    ]
  },
  lock: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'rect', attrs: { x: 3, y: 11, width: 18, height: 11, rx: 2 } },
      { tag: 'path', attrs: { d: 'M7 11V7a5 5 0 0 1 10 0v4' } }
    ]
  },
  user: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'path', attrs: { d: 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2' } },
      { tag: 'circle', attrs: { cx: 12, cy: 7, r: 4 } }
    ]
  },
  eye: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'path', attrs: { d: 'M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z' } },
      { tag: 'circle', attrs: { cx: 12, cy: 12, r: 3 } }
    ]
  },
  'eye-off': {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'path', attrs: { d: 'M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68' } },
      { tag: 'path', attrs: { d: 'M6.61 6.61A13.53 13.53 0 0 0 2 12s3 8 10 8a9.74 9.74 0 0 0 5.39-1.61' } },
      { tag: 'line', attrs: { x1: 2, y1: 2, x2: 22, y2: 22 } }
    ]
  },
  calendar: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'rect', attrs: { x: 3, y: 4, width: 18, height: 18, rx: 2 } },
      { tag: 'line', attrs: { x1: 16, y1: 2, x2: 16, y2: 6 } },
      { tag: 'line', attrs: { x1: 8, y1: 2, x2: 8, y2: 6 } },
      { tag: 'line', attrs: { x1: 3, y1: 10, x2: 21, y2: 10 } }
    ]
  },
  phone: {
    viewBox: '0 0 24 24',
    shapes: [
      {
        tag: 'path',
        attrs: {
          d: 'M13.83 21a2 2 0 0 0 1.66-2.14L14 15l-3.5-1L7 10 5 5.5l-1.86-.66A2 2 0 0 0 1 6.5c0 8.5 8 16.5 16 16.5Z'
        }
      }
    ]
  },
  tag: {
    viewBox: '0 0 24 24',
    shapes: [
      {
        tag: 'path',
        attrs: {
          d: 'M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.41l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42Z'
        }
      },
      { tag: 'circle', attrs: { cx: 7.5, cy: 7.5, r: 0.5 } }
    ]
  },
  link: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'path', attrs: { d: 'M9 17H7A5 5 0 0 1 7 7h2' } },
      { tag: 'path', attrs: { d: 'M15 7h2a5 5 0 1 1 0 10h-2' } },
      { tag: 'line', attrs: { x1: 8, y1: 12, x2: 16, y2: 12 } }
    ]
  },
  x: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'line', attrs: { x1: 18, y1: 6, x2: 6, y2: 18 } },
      { tag: 'line', attrs: { x1: 6, y1: 6, x2: 18, y2: 18 } }
    ]
  },
  check: {
    viewBox: '0 0 24 24',
    shapes: [{ tag: 'polyline', attrs: { points: '20 6 9 17 4 12' } }]
  },
  plus: {
    viewBox: '0 0 24 24',
    shapes: [
      { tag: 'line', attrs: { x1: 12, y1: 5, x2: 12, y2: 19 } },
      { tag: 'line', attrs: { x1: 5, y1: 12, x2: 19, y2: 12 } }
    ]
  }
};
