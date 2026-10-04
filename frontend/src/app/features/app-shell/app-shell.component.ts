import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  Injector,
  OnDestroy,
  signal,
  viewChild
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter, map } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { AuthStore } from '../../core/auth/auth.store';
import { FeedbackListStore } from '../../core/feedback/feedback-list.store';
import { IntegrationsStore } from '../../core/integrations/integrations.store';
import { PaywallModalComponent } from '../../core/paywall/paywall-modal.component';
import { RealtimeBridge } from '../../core/realtime/realtime.bridge';
import { formatCount } from '../../shared/format/format';
import { analysisStatusLabel, categoryLabel, platformLabel, sentimentLabel } from '../../shared/labels/domain-labels';
import { ButtonComponent } from '../../shared/ui/button/button.component';
import { IconComponent } from '../../shared/ui/icon/icon.component';
import { IconName } from '../../shared/ui/icon/icon.types';
import { LogoComponent } from '../../shared/ui/logo/logo.component';
import { trapTabKey } from '../../shared/ui/modal/focus-trap';
import { shortcutBlocked } from '../../shared/ui/modal/modal-scope';
import { QuotaMeterComponent } from './quota-meter.component';
import { ThemeToggleComponent } from './theme-toggle.component';

type Section = 'overview' | 'inbox' | 'integrations' | 'settings';

interface NavItem {
  readonly section: Section;
  readonly link: string;
  readonly icon: IconName;
}

/** One filter that is narrowing the inbox, as the command bar shows it. */
interface CommandToken {
  readonly key: string;
  readonly text: string;
}

/**
 * Order is the order on screen: the view tabs on wide screens and the tab bar
 * on phones. The inbox leads because it is where the work is.
 */
const NAV: readonly NavItem[] = [
  { section: 'inbox', link: '/app/inbox', icon: 'inbox' },
  { section: 'overview', link: '/app/overview', icon: 'eye' },
  { section: 'integrations', link: '/app/integrations', icon: 'link' },
  { section: 'settings', link: '/app/settings', icon: 'user' }
];

/** Where the inbox search field lives; the command bar hands focus to it. */
const SEARCH_INPUT = '[data-testid="inbox-search"] input';

/**
 * Chrome for every `/app/**` screen: skip link, the command bar, the view tabs
 * and the single `<main>` the child routes render into.
 *
 * There is no sidebar. One bar runs across the top: the lockup, a command line
 * that shows what is narrowing the inbox and takes `Ctrl K` or `/`, the quota,
 * the company and the account button. Under it sit the views as large text
 * tabs. Phones keep the top bar (lockup, company, search, account) and move
 * the views to a tab bar along the bottom edge, where a thumb reaches it.
 *
 * The company name is rendered once, in the top bar, at every width. CSS
 * places it; nothing is duplicated and hidden.
 *
 * The account button opens a small modal dialog at every width (quota, theme,
 * sign out). It shares `trapTabKey` with the shared modal, makes the page
 * behind it inert, closes on Escape or a tap outside and hands focus back.
 */
@Component({
  selector: 'app-app-shell',
  imports: [
    NgTemplateOutlet,
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    IconComponent,
    ButtonComponent,
    LogoComponent,
    QuotaMeterComponent,
    ThemeToggleComponent,
    PaywallModalComponent
  ],
  templateUrl: './app-shell.component.html',
  styleUrls: ['./app-shell.component.scss', './app-shell-views.scss', './app-shell-sheet.scss', './app-shell-foot.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '(document:keydown)': 'onDocumentKeydown($event)'
  }
})
export class AppShellComponent implements OnDestroy {
  private readonly authService = inject(AuthService);
  private readonly authStore = inject(AuthStore);
  private readonly realtime = inject(RealtimeBridge);
  private readonly router = inject(Router);
  private readonly injector = inject(Injector);
  private readonly feedbackList = inject(FeedbackListStore);
  private readonly integrations = inject(IntegrationsStore);

  private readonly accountTrigger = viewChild<ElementRef<HTMLButtonElement>>('accountTrigger');
  private readonly accountSheet = viewChild<ElementRef<HTMLElement>>('accountSheet');

  protected readonly nav = NAV;

  protected readonly user = this.authStore.user;
  protected readonly company = this.authStore.company;
  protected readonly emailVerified = this.authStore.isEmailVerified;
  protected readonly signingOut = signal(false);

  private destroyed = false;

  /** The account dialog behind the avatar button. */
  protected readonly accountOpen = signal(false);

  protected readonly userInitials = computed(() => {
    const parts = (this.user()?.name ?? '').trim().split(/\s+/).filter((part) => part.length > 0);
    if (parts.length === 0) {
      return '?';
    }
    const first = parts[0].charAt(0);
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
    return `${first}${last}`.toUpperCase();
  });

  /**
   * Counts beside the view tabs. Read from what the stores already hold; the
   * shell never fetches a list just to count it.
   */
  protected readonly counts = computed<Partial<Record<Section, string>>>(() => {
    const result: Partial<Record<Section, string>> = {};
    if (this.feedbackList.state() === 'ready') {
      result.inbox = formatCount(this.feedbackList.meta().total);
    }
    if (this.integrations.state() === 'ready') {
      result.integrations = formatCount(this.integrations.items().length);
    }
    return result;
  });

  /** The filters narrowing the inbox right now, as plain values. */
  protected readonly tokens = computed<readonly CommandToken[]>(() => {
    const filters = this.feedbackList.filters();
    const tokens: CommandToken[] = [];
    if (filters.sentiment) {
      tokens.push({ key: 'sentiment', text: sentimentLabel(filters.sentiment) });
    }
    if (filters.category) {
      tokens.push({ key: 'category', text: categoryLabel(filters.category) });
    }
    if (filters.platform) {
      tokens.push({ key: 'platform', text: platformLabel(filters.platform) });
    }
    if (filters.analysis_status) {
      tokens.push({ key: 'status', text: analysisStatusLabel(filters.analysis_status) });
    }
    if (filters.integration_id !== null) {
      tokens.push({ key: 'integration', text: `#${filters.integration_id}` });
    }
    if (filters.from || filters.to) {
      tokens.push({ key: 'dates', text: `${filters.from ?? '…'} → ${filters.to ?? '…'}` });
    }
    return tokens;
  });

  /** What was typed into the inbox search, shown in place of the placeholder. */
  protected readonly query = computed(() => this.feedbackList.filters().q ?? '');

  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects)
    ),
    { initialValue: this.router.url }
  );

  protected readonly primaryNavLabel = $localize`:Primary navigation landmark label@@shell.nav.primary:Primary`;
  protected readonly themeGroupHeading = $localize`:Theme switch group label@@shell.theme.label:Colour theme`;
  protected readonly signOutLabel = $localize`:Sign out button label@@shell.signOut:Sign out`;
  protected readonly searchLabel = $localize`:Inbox search field label@@inbox.filters.search:Search comments`;

  /**
   * The only place realtime is started, and the reason it is here rather than
   * in `app.config.ts`: this component lives in a lazy chunk behind
   * `authGuard`, so pusher-js can never reach the initial bundle or a page a
   * signed-out visitor can open (`docs/contracts/realtime.md` section 3).
   *
   * An `effect` rather than a constructor call because a hard refresh mounts
   * the shell while `authGuard` is still resolving `GET /auth/me`: the company
   * id and the token both arrive a tick later, and `connect()` is idempotent.
   */
  constructor() {
    effect(() => {
      if (this.authStore.isAuthenticated()) {
        this.realtime.start();
      }
    });

    // A navigation always lands on a fresh screen, never under an open dialog.
    effect(() => {
      this.url();
      this.accountOpen.set(false);
    });
  }

  /** Leaving `/app/**` (sign-out, a dead token, or a plain navigation) closes the socket. */
  ngOnDestroy(): void {
    this.destroyed = true;
    this.realtime.stop();
  }

  protected countFor(section: Section): string | null {
    return this.counts()[section] ?? null;
  }

  /**
   * `Ctrl K` (or `Cmd K`) and `/` go to the inbox search. Neither fires while
   * a dialog is open or focus is in a field, the inbox search included: a
   * dialog keeps the keyboard, and a field keeps its own keys (`Ctrl K` is
   * left to the field and the browser there). Escape closes the account dialog.
   */
  protected onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      this.dismissAccount();
      return;
    }
    if (this.accountOpen() || shortcutBlocked(event)) {
      return;
    }
    const commandK = (event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k';
    const slash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey;
    if (commandK || slash) {
      event.preventDefault();
      this.openSearch();
    }
  }

  /** Focuses the inbox search, moving to the inbox first when needed. */
  protected openSearch(): void {
    const field = document.querySelector<HTMLInputElement>(SEARCH_INPUT);
    if (field) {
      field.focus();
      field.select();
      return;
    }
    void this.router.navigateByUrl('/app/inbox').then((moved) => {
      // The shell can be gone by the time the navigation settles.
      if (moved !== false && !this.destroyed) {
        afterNextRender(() => document.querySelector<HTMLInputElement>(SEARCH_INPUT)?.focus(), {
          injector: this.injector
        });
      }
    });
  }

  /**
   * The dialog behaves like the shared modal: focus moves into it on open, Tab
   * is contained by the same `trapTabKey`, and the page behind it is `inert`.
   */
  protected toggleAccount(): void {
    if (this.accountOpen()) {
      this.dismissAccount();
      return;
    }
    this.accountOpen.set(true);
    afterNextRender(() => this.accountSheet()?.nativeElement.focus(), { injector: this.injector });
  }

  /** Escape or a tap outside: close and hand focus back to the button that opened it. */
  protected dismissAccount(): void {
    if (!this.accountOpen()) {
      return;
    }
    this.accountOpen.set(false);
    // The trigger sits in the header, which is inert until this render lands.
    afterNextRender(() => this.accountTrigger()?.nativeElement.focus(), { injector: this.injector });
  }

  protected onSheetKeydown(event: KeyboardEvent): void {
    const sheet = this.accountSheet()?.nativeElement;
    if (event.key === 'Tab' && sheet) {
      trapTabKey(sheet, event);
    }
  }

  protected onSignOut(): void {
    if (this.signingOut()) {
      return;
    }
    this.signingOut.set(true);
    // Before the request, not after: the token that authorized the channel is
    // about to be revoked, and a socket still holding it would keep receiving
    // this tenant's events until the server noticed.
    this.realtime.stop();
    this.authService.logout().subscribe({
      next: () => {
        this.signingOut.set(false);
        void this.router.navigate(['/']);
      },
      error: () => {
        // The token is unusable either way; drop it locally and leave.
        this.signingOut.set(false);
        this.authStore.clear();
        void this.router.navigate(['/']);
      }
    });
  }
}
