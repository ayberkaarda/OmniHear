import { DOCUMENT } from '@angular/common';
import { afterNextRender, ChangeDetectionStrategy, Component, DestroyRef, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ButtonStyleDirective } from '../../shared/ui/button/button-style.directive';
import { LogoComponent } from '../../shared/ui/logo/logo.component';

type Sentiment = 'negative' | 'neutral' | 'positive';

interface FeedRow {
  source: string;
  author: string;
  time: string;
  score: string;
  sentiment: Sentiment;
  /** Language the customer wrote in; also keeps the uppercase labels right under a Turkish page. */
  lang: 'en' | 'tr';
  /** Kept exactly as the customer wrote it. `mark` is the phrase the marker lands on. */
  before: string;
  mark?: string;
  after?: string;
  /** Hidden on phones, so the narrow feed keeps three rows around the marked one. */
  wideOnly?: boolean;
}

interface Channel {
  name: string;
  what: string;
  how: string;
}

interface ReadStep {
  title: string;
  body: string;
}

const EMAIL = $localize`:@@landing.source.email.name:E-mail`;

/** Number of bars in the hero voiceprint; phones show the most recent half. */
const VOICEPRINT_BARS = 144;

/**
 * Sample voiceprint: one bar per comment, height = |score|, colour = sentiment.
 * Deterministic (a tiny LCG) so every render and every screenshot is the same.
 */
function sampleVoiceprint(): readonly { h: number; s: Sentiment }[] {
  let seed = 7;
  const next = () => (seed = (seed * 48271) % 2147483647) / 2147483647;
  return Array.from({ length: VOICEPRINT_BARS }, () => {
    const r = next();
    const s: Sentiment = r < 0.42 ? 'negative' : r < 0.6 ? 'neutral' : 'positive';
    const h = s === 'neutral' ? 0.08 + next() * 0.14 : 0.26 + next() * 0.7;
    return { h: Math.round(h * 100), s };
  });
}

/**
 * Public marketing page.
 *
 * Static by design: no HTTP call and no store, so it renders for a signed-out
 * visitor and stays in its own lazy chunk. Every customer comment on it is
 * sample data and labelled as such. The FAQ uses native `<details>` so it works
 * without JavaScript.
 *
 * The one scripted motion is the reading in "How a comment is read": an
 * IntersectionObserver moves `step` as each explanation crosses the middle of
 * the viewport, and CSS sweeps the marker over the comment. Without an
 * observer, or under reduced motion, `step` stays at the final state.
 */
@Component({
  selector: 'app-landing',
  imports: [RouterLink, ButtonStyleDirective, LogoComponent],
  templateUrl: './landing.component.html',
  styleUrl: './landing.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class LandingComponent {
  /** Free-plan analysis allowance, spec 7.2. Mirrors backend `config/quota.php`. */
  protected readonly freePlanQuota = 200;

  protected readonly negativeLabel = $localize`:@@landing.preview.sentiment.negative:Negative`;
  protected readonly bugLabel = $localize`:@@landing.preview.category.bug:Bug`;

  protected readonly feed: readonly FeedRow[] = [
    {
      source: 'Google Play',
      author: 'Deniz Aksoy',
      time: '09:41',
      score: '+0.71',
      lang: 'tr',
      sentiment: 'positive',
      before: 'Yeni sürümde senkronizasyon çok daha hızlı, elinize sağlık.',
      wideOnly: true
    },
    {
      source: 'Zendesk',
      author: 'Marco Ferri',
      time: '09:12',
      score: '+0.06',
      lang: 'en',
      sentiment: 'neutral',
      before: 'Could the weekly summary be exported as CSV?'
    },
    {
      source: 'App Store',
      author: 'Elif Kaya',
      time: '08:57',
      score: '−0.82',
      lang: 'en',
      sentiment: 'negative',
      before: 'Since the last update it ',
      mark: 'crashes every time',
      after: ' I open notifications.'
    },
    {
      source: EMAIL,
      author: 'Burak Yıldız',
      time: '08:30',
      score: '−0.47',
      lang: 'tr',
      sentiment: 'negative',
      before: 'Bu ay faturam iki kez kesildi, iadeyi hâlâ bekliyorum.'
    },
    {
      source: 'Trustpilot',
      author: 'Hannah Weiss',
      time: '08:04',
      score: '−0.12',
      lang: 'en',
      sentiment: 'neutral',
      before: 'Tracking works, but the delivery screen could be clearer.',
      wideOnly: true
    }
  ];

  protected readonly voiceprint = sampleVoiceprint();

  protected readonly channels: readonly Channel[] = [
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

  /** One step per reading; step N switches on the Nth reading on the stage. */
  protected readonly steps: readonly ReadStep[] = [
    {
      title: $localize`:@@landing.read.mark.title:Mark the phrase`,
      body: $localize`:@@landing.read.mark.body:The marker lands on the words that drove the score, so you see why before you read the rest.`
    },
    {
      title: $localize`:@@landing.read.language.title:Detect the language`,
      body: $localize`:@@landing.read.language.body:Each comment is read in the language it was written in. Turkish stays Turkish.`
    },
    {
      title: $localize`:@@landing.read.sentiment.title:Score the feeling`,
      body: $localize`:@@landing.read.sentiment.body:A number from −1 to +1, stored with its confidence and the model version.`
    },
    {
      title: $localize`:@@landing.read.category.title:Sort it`,
      body: $localize`:@@landing.read.category.body:Complaint, praise, bug or feature request. One per comment.`
    },
    {
      title: $localize`:@@landing.read.keywords.title:Pull the keywords`,
      body: $localize`:@@landing.read.keywords.body:The words the reading keyed on, so the same problem can be found across channels.`
    }
  ];

  protected readonly keywords = ['update', 'crash', 'notifications'];

  /** How far the reading has got, 0 to 5. Starts complete: the static page is the final state. */
  protected readonly step = signal(5);

  /** True once the observer drives `step`; until then every step reads as current. */
  protected readonly live = signal(false);

  constructor() {
    const document = inject(DOCUMENT);
    const destroyRef = inject(DestroyRef);

    afterNextRender(() => {
      const view = document.defaultView;

      /*
       * A cold load of `/#pricing` (or an older `/#integrations` / `/#features`
       * link, kept alive by alias anchors in the template) arrives before this
       * page has rendered, so the browser's own fragment jump finds nothing.
       * Repeat it once the sections exist; `scroll-margin-top` clears the header.
       */
      const id = decodeURIComponent(view?.location.hash.slice(1) ?? '');
      const target = id ? document.getElementById(id) : null;
      target?.scrollIntoView?.();

      const reduce = view?.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? true;
      if (!view || reduce || typeof view.IntersectionObserver !== 'function') {
        return;
      }
      const items = Array.from(document.querySelectorAll<HTMLElement>('[data-read-step]'));
      if (!items.length) {
        return;
      }
      this.step.set(0);
      this.live.set(true);
      const observer = new view.IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (entry.isIntersecting) {
              this.step.set(Number(entry.target.getAttribute('data-read-step')));
            }
          }
        },
        // A thin band across the middle of the viewport: a step is "current" while it crosses it.
        { rootMargin: '-48% 0px -48% 0px' }
      );
      items.forEach((item) => observer.observe(item));
      destroyRef.onDestroy(() => observer.disconnect());
    });
  }
}
