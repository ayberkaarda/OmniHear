const defaultTheme = require('tailwindcss/defaultTheme');

/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: ['./src/**/*.{html,ts}'],
  theme: {
    extend: {
      // The families index.html has been loading since F1 — but nothing ever
      // bound them, so every screen rendered in the system stack while the CDN
      // request went out anyway. Self-hosting them (styles/fonts.css) fixed the
      // privacy half; this line is the half that makes the request worth making.
      // Tailwind's own defaults stay as the fallback chain.
      fontFamily: {
        sans: ['"IBM Plex Sans"', ...defaultTheme.fontFamily.sans],
        mono: ['"IBM Plex Mono"', ...defaultTheme.fontFamily.mono],
      },

      colors: {
        // Surfaces / structure
        canvas: 'var(--bg-canvas)',
        surface: 'var(--bg-surface)',
        'surface-raised': 'var(--bg-surface-raised)',
        'surface-sunken': 'var(--bg-surface-sunken)',
        'surface-hover': 'var(--bg-surface-hover)',
        'surface-selected': 'var(--bg-surface-selected)',
        overlay: 'var(--bg-overlay)',

        border: 'var(--border)',
        'border-strong': 'var(--border-strong)',

        // Text
        'text-primary': 'var(--text-primary)',
        'text-secondary': 'var(--text-secondary)',
        'text-muted': 'var(--text-muted)',
        'text-disabled': 'var(--text-disabled)',
        'text-inverse': 'var(--text-inverse)',

        // Brand
        brand: 'var(--brand)',
        'brand-hover': 'var(--brand-hover)',
        'brand-active': 'var(--brand-active)',
        'brand-soft': 'var(--brand-soft)',
        'brand-text': 'var(--brand-text)',
        'brand-on': 'var(--brand-on)',

        'ring-focus': 'var(--ring-focus)',
        highlight: 'var(--highlight)',

        // Sentiment
        'sentiment-negative-text': 'var(--sentiment-negative-text)',
        'sentiment-negative-bg': 'var(--sentiment-negative-bg)',
        'sentiment-negative-border': 'var(--sentiment-negative-border)',
        'sentiment-negative-fill': 'var(--sentiment-negative-fill)',
        'sentiment-neutral-text': 'var(--sentiment-neutral-text)',
        'sentiment-neutral-bg': 'var(--sentiment-neutral-bg)',
        'sentiment-neutral-border': 'var(--sentiment-neutral-border)',
        'sentiment-neutral-fill': 'var(--sentiment-neutral-fill)',
        'sentiment-positive-text': 'var(--sentiment-positive-text)',
        'sentiment-positive-bg': 'var(--sentiment-positive-bg)',
        'sentiment-positive-border': 'var(--sentiment-positive-border)',
        'sentiment-positive-fill': 'var(--sentiment-positive-fill)',

        // Category
        'category-complaint-text': 'var(--category-complaint-text)',
        'category-complaint-bg': 'var(--category-complaint-bg)',
        'category-complaint-border': 'var(--category-complaint-border)',
        'category-complaint-fill': 'var(--category-complaint-fill)',
        'category-praise-text': 'var(--category-praise-text)',
        'category-praise-bg': 'var(--category-praise-bg)',
        'category-praise-border': 'var(--category-praise-border)',
        'category-praise-fill': 'var(--category-praise-fill)',
        'category-bug-text': 'var(--category-bug-text)',
        'category-bug-bg': 'var(--category-bug-bg)',
        'category-bug-border': 'var(--category-bug-border)',
        'category-bug-fill': 'var(--category-bug-fill)',
        'category-feature-request-text': 'var(--category-feature-request-text)',
        'category-feature-request-bg': 'var(--category-feature-request-bg)',
        'category-feature-request-border': 'var(--category-feature-request-border)',
        'category-feature-request-fill': 'var(--category-feature-request-fill)',

        // Source
        'source-text': 'var(--source-text)',
        'source-bg': 'var(--source-bg)',
        'source-border': 'var(--source-border)',

        // Status
        'status-success': 'var(--status-success)',
        'status-warning': 'var(--status-warning)',
        'status-error': 'var(--status-error)',
        'status-info': 'var(--status-info)',
        'status-paused': 'var(--status-paused)',

        // Quota (aliases — see tokens.json)
        'quota-ok-text': 'var(--quota-ok-text)',
        'quota-ok-bg': 'var(--quota-ok-bg)',
        'quota-warning-text': 'var(--quota-warning-text)',
        'quota-warning-bg': 'var(--quota-warning-bg)',
        'quota-exceeded-text': 'var(--quota-exceeded-text)',
        'quota-exceeded-bg': 'var(--quota-exceeded-bg)'
      }
    }
  },
  plugins: []
};
