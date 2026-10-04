import { DOCUMENT } from '@angular/common';
import { afterNextRender, ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ButtonStyleDirective } from '../../shared/ui/button/button-style.directive';
import { IconComponent } from '../../shared/ui/icon/icon.component';
import { LogoComponent } from '../../shared/ui/logo/logo.component';

type Sentiment = 'negative' | 'neutral' | 'positive';
type Category = 'complaint' | 'praise' | 'bug' | 'featureRequest';

interface PreviewRow {
  source: string;
  author: string;
  time: string;
  /** Kept exactly as the customer wrote it, in their own language. */
  text: string;
  sentiment: Sentiment;
  score: string;
  category: Category;
  /** The one row lifted out of the list, like the signal slice of the mark. */
  pulled?: boolean;
  /** Hidden below `md` so the phone composition keeps three rows around the pulled one. */
  wideOnly?: boolean;
}

interface Source {
  name: string;
  what: string;
  how: string;
}

const SENTIMENT_LABEL: Record<Sentiment, string> = {
  negative: $localize`:@@landing.preview.sentiment.negative:Negative`,
  neutral: $localize`:@@landing.preview.sentiment.neutral:Neutral`,
  positive: $localize`:@@landing.preview.sentiment.positive:Positive`
};

const CATEGORY_LABEL: Record<Category, string> = {
  complaint: $localize`:@@landing.preview.category.complaint:Complaint`,
  praise: $localize`:@@landing.preview.category.praise:Praise`,
  bug: $localize`:@@landing.preview.category.bug:Bug`,
  featureRequest: $localize`:@@landing.preview.category.featureRequest:Feature request`
};

/** Chip colours come from the data palette only; the signal colour never marks data. */
const SENTIMENT_CHIP: Record<Sentiment, string> = {
  negative: 'border-[var(--sentiment-negative-border)] bg-[var(--sentiment-negative-bg)] text-[var(--sentiment-negative-text)]',
  neutral: 'border-[var(--sentiment-neutral-border)] bg-[var(--sentiment-neutral-bg)] text-[var(--sentiment-neutral-text)]',
  positive: 'border-[var(--sentiment-positive-border)] bg-[var(--sentiment-positive-bg)] text-[var(--sentiment-positive-text)]'
};

const CATEGORY_CHIP: Record<Category, string> = {
  complaint: 'border-[var(--category-complaint-border)] bg-[var(--category-complaint-bg)] text-[var(--category-complaint-text)]',
  praise: 'border-[var(--category-praise-border)] bg-[var(--category-praise-bg)] text-[var(--category-praise-text)]',
  bug: 'border-[var(--category-bug-border)] bg-[var(--category-bug-bg)] text-[var(--category-bug-text)]',
  featureRequest:
    'border-[var(--category-feature-request-border)] bg-[var(--category-feature-request-bg)] text-[var(--category-feature-request-text)]'
};

const EMAIL = $localize`:@@landing.source.email.name:E-mail`;

/**
 * Public marketing page.
 *
 * Static by design: no HTTP call and no store, so it renders for a signed-out
 * visitor and stays in its own lazy chunk. The inbox preview is sample data
 * declared here and labelled as such on the page; the FAQ uses native
 * `<details>/<summary>` so it works without JavaScript.
 */
@Component({
  selector: 'app-landing',
  imports: [RouterLink, ButtonStyleDirective, IconComponent, LogoComponent],
  templateUrl: './landing.component.html',
  styleUrl: './landing.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class LandingComponent {
  /**
   * A cold load of `/#pricing` (or an older `/#integrations` / `/#features`
   * link, kept alive by alias anchors in the template) arrives before this
   * page has rendered, so the browser's own fragment jump finds nothing.
   * Repeat it once the sections exist; `scrollIntoView` honours the
   * `scroll-margin-top` that clears the sticky header.
   */
  constructor() {
    const document = inject(DOCUMENT);
    afterNextRender(() => {
      const id = decodeURIComponent(document.defaultView?.location.hash.slice(1) ?? '');
      const target = id ? document.getElementById(id) : null;
      target?.scrollIntoView?.();
    });
  }

  /** Free-plan analysis allowance, spec 7.2. Mirrors backend `config/quota.php`. */
  protected readonly freePlanQuota = 200;

  protected readonly sentimentLabel = SENTIMENT_LABEL;
  protected readonly categoryLabel = CATEGORY_LABEL;
  protected readonly sentimentChip = SENTIMENT_CHIP;
  protected readonly categoryChip = CATEGORY_CHIP;

  protected readonly previewRows: readonly PreviewRow[] = [
    {
      source: 'Google Play',
      author: 'Deniz Aksoy',
      time: '09:41',
      text: 'Yeni sürümde senkronizasyon çok daha hızlı, elinize sağlık.',
      sentiment: 'positive',
      score: '+0.71',
      category: 'praise',
      wideOnly: true
    },
    {
      source: 'Zendesk',
      author: 'Marco Ferri',
      time: '09:12',
      text: 'Could the weekly summary be exported as CSV?',
      sentiment: 'neutral',
      score: '+0.06',
      category: 'featureRequest'
    },
    {
      source: 'App Store',
      author: 'Elif Kaya',
      time: '08:57',
      text: 'Since the last update it crashes every time I open notifications.',
      sentiment: 'negative',
      score: '−0.82',
      category: 'bug',
      pulled: true
    },
    {
      source: EMAIL,
      author: 'Burak Yıldız',
      time: '08:30',
      text: 'Bu ay faturam iki kez kesildi, iadeyi hâlâ bekliyorum.',
      sentiment: 'negative',
      score: '−0.47',
      category: 'complaint'
    },
    {
      source: 'Trustpilot',
      author: 'Hannah Weiss',
      time: '08:04',
      text: 'Tracking works, but the delivery screen could be clearer.',
      sentiment: 'neutral',
      score: '−0.12',
      category: 'complaint',
      wideOnly: true
    }
  ];

  /** The pulled row, read closely. Keyword spans are what the analyser keyed on. */
  protected readonly specimen: readonly { text: string; keyword?: boolean }[] = [
    { text: 'Since the last ' },
    { text: 'update', keyword: true },
    { text: ' it ' },
    { text: 'crashes', keyword: true },
    { text: ' every time I open ' },
    { text: 'notifications', keyword: true },
    { text: '.' }
  ];

  protected readonly specimenKeywords = this.specimen.filter((part) => part.keyword).map((part) => part.text);

  protected readonly sources: readonly Source[] = [
    {
      name: 'App Store',
      what: $localize`:@@landing.source.appStore.what:Customer reviews and star ratings from the public review feed.`,
      how: $localize`:@@landing.source.appStore.how:No credentials`
    },
    {
      name: 'Google Play',
      what: $localize`:@@landing.source.googlePlay.what:Reviews of your Android app, newest first.`,
      how: $localize`:@@landing.source.googlePlay.how:Package name and service account`
    },
    {
      name: 'Zendesk',
      what: $localize`:@@landing.source.zendesk.what:Support tickets, read from the incremental export.`,
      how: $localize`:@@landing.source.zendesk.how:Subdomain and API token`
    },
    {
      name: 'Trustpilot',
      what: $localize`:@@landing.source.trustpilot.what:Reviews written about your business unit.`,
      how: $localize`:@@landing.source.trustpilot.how:Business unit ID and API key`
    },
    {
      name: EMAIL,
      what: $localize`:@@landing.source.email.what:Messages sent to a shared support address.`,
      how: $localize`:@@landing.source.email.how:Any JMAP mailbox`
    },
    {
      name: 'Mastodon',
      what: $localize`:@@landing.source.mastodon.what:Public posts under a hashtag you choose.`,
      how: $localize`:@@landing.source.mastodon.how:A hashtag, no account`
    }
  ];
}
