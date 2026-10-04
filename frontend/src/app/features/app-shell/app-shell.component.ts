import { ChangeDetectionStrategy, Component, computed, effect, inject, OnDestroy, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter, map } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { AuthStore } from '../../core/auth/auth.store';
import { PaywallModalComponent } from '../../core/paywall/paywall-modal.component';
import { RealtimeBridge } from '../../core/realtime/realtime.bridge';
import { ButtonComponent } from '../../shared/ui/button/button.component';
import { IconComponent } from '../../shared/ui/icon/icon.component';
import { IconName } from '../../shared/ui/icon/icon.types';
import { LogoComponent } from '../../shared/ui/logo/logo.component';
import { QuotaMeterComponent } from './quota-meter.component';
import { ThemeToggleComponent } from './theme-toggle.component';

type Section = 'overview' | 'inbox' | 'integrations' | 'settings';

interface NavItem {
  readonly section: Section;
  readonly link: string;
  readonly icon: IconName;
}

/** Order is the order on screen, in the rail and in the phone tab bar alike. */
const NAV: readonly NavItem[] = [
  { section: 'overview', link: '/app/overview', icon: 'eye' },
  { section: 'inbox', link: '/app/inbox', icon: 'mail' },
  { section: 'integrations', link: '/app/integrations', icon: 'link' },
  { section: 'settings', link: '/app/settings', icon: 'user' }
];

const RAIL_LINK =
  'relative flex h-9 items-center gap-3 rounded-control px-3 text-sm transition-colors duration-fast ease-standard ' +
  'hover:bg-[var(--bg-surface-hover)] hover:text-[var(--text-primary)] ' +
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring-focus)]';
const TAB_LINK =
  'relative flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-medium ' +
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring-focus)] active:translate-y-px';
const LINK_ACTIVE = 'shell-selected font-medium text-[var(--text-primary)]';

const SECTION_PATTERN = /^\/app\/(overview|inbox|integrations|settings)(?:\/([^/?#]+))?/;

/**
 * Chrome for every `/app/**` screen: skip link, primary navigation landmark,
 * identity/quota rail and the single `<main>` the child routes render into.
 *
 * Wide screens get a quiet rail on the left; phones get a sticky top bar and a
 * tab bar along the bottom edge, where a thumb reaches it. Both carry the same
 * four destinations. The account block (quota, theme, sign out) sits at the
 * foot of the rail and, on phones, behind the avatar button in the top bar.
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
  styleUrl: './app-shell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(document:keydown.escape)': 'closeAccount()' }
})
export class AppShellComponent implements OnDestroy {
  private readonly authService = inject(AuthService);
  private readonly authStore = inject(AuthStore);
  private readonly realtime = inject(RealtimeBridge);
  private readonly router = inject(Router);

  protected readonly nav = NAV;

  protected readonly user = this.authStore.user;
  protected readonly company = this.authStore.company;
  protected readonly emailVerified = this.authStore.isEmailVerified;
  protected readonly signingOut = signal(false);

  /** Phone-only account sheet behind the avatar button. */
  protected readonly accountOpen = signal(false);

  protected readonly userInitial = computed(() => this.user()?.name.trim().charAt(0).toUpperCase() ?? '?');

  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects)
    ),
    { initialValue: this.router.url }
  );

  /** Where the user is, for the breadcrumb in the top bar. */
  protected readonly location = computed(() => {
    const match = SECTION_PATTERN.exec(this.url());
    if (!match) {
      return null;
    }
    const section = match[1] as Section;
    // Only the inbox has a record level worth naming; settings sub-pages carry their own nav.
    const record = section === 'inbox' && match[2] && /^\d+$/.test(match[2]) ? match[2] : null;
    return { section, record };
  });

  protected readonly primaryNavLabel = $localize`:Primary navigation landmark label@@shell.nav.primary:Primary`;
  protected readonly themeGroupHeading = $localize`:Theme switch group label@@shell.theme.label:Colour theme`;
  protected readonly signOutLabel = $localize`:Sign out button label@@shell.signOut:Sign out`;

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

    // A navigation always lands on a fresh screen, never under an open sheet.
    effect(() => {
      this.url();
      this.accountOpen.set(false);
    });
  }

  /** Leaving `/app/**` (sign-out, a dead token, or a plain navigation) closes the socket. */
  ngOnDestroy(): void {
    this.realtime.stop();
  }

  protected railLinkClasses(active: boolean): string {
    return `${RAIL_LINK} ${active ? LINK_ACTIVE : 'text-[var(--text-secondary)]'}`;
  }

  protected tabClasses(active: boolean): string {
    return `${TAB_LINK} ${active ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`;
  }

  protected toggleAccount(): void {
    this.accountOpen.update((open) => !open);
  }

  protected closeAccount(): void {
    this.accountOpen.set(false);
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
