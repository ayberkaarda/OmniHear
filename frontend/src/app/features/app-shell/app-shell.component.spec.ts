import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../../core/auth/auth.fixtures';
import { AuthStore } from '../../core/auth/auth.store';
import { RealtimeBridge } from '../../core/realtime/realtime.bridge';
import { AppShellComponent } from './app-shell.component';

const LOGOUT = `${environment.apiBaseUrl}/v1/auth/logout`;

/**
 * The shell is where realtime is started, and the reason it is here rather than
 * in `app.config.ts` is a bundle constraint: this component lives in a lazy
 * chunk behind `authGuard`, so pusher-js can never reach the initial bundle or
 * a page a signed-out visitor can open.
 */
describe('AppShellComponent', () => {
  let start: jest.SpyInstance;
  let stop: jest.SpyInstance;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [AppShellComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    http = TestBed.inject(HttpTestingController);
    const bridge = TestBed.inject(RealtimeBridge);
    start = jest.spyOn(bridge, 'start').mockImplementation(() => undefined);
    stop = jest.spyOn(bridge, 'stop').mockImplementation(() => undefined);
  });

  afterEach(() => {
    jest.restoreAllMocks();
    http.verify();
  });

  it('opens the channel once the session is settled, and not before', async () => {
    const fixture = TestBed.createComponent(AppShellComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    // A hard refresh mounts the shell while `authGuard` is still resolving
    // `GET /auth/me`; there is no company id to subscribe with yet.
    expect(start).not.toHaveBeenCalled();

    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());
    fixture.detectChanges();
    await fixture.whenStable();

    expect(start).toHaveBeenCalled();
  });

  it('closes the channel when the shell is left', async () => {
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());
    const fixture = TestBed.createComponent(AppShellComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    fixture.destroy();
    expect(stop).toHaveBeenCalled();
  });

  /**
   * Before the request, not after: the token that authorized the channel is
   * about to be revoked, and a socket still holding it would keep receiving
   * this tenant's events until the server noticed.
   */
  it('closes the channel before the logout request goes out', async () => {
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());
    const fixture = TestBed.createComponent(AppShellComponent);
    const element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    await fixture.whenStable();

    // Sign out lives in the account dialog, at every width.
    (element.querySelector('[data-testid="shell-account-trigger"]') as HTMLButtonElement).click();
    fixture.detectChanges();
    await fixture.whenStable();

    const signOut = Array.from(element.querySelectorAll('[data-testid="shell-account-sheet"] button')).find(
      (button) => button.getAttribute('aria-label') === 'Sign out'
    ) as HTMLButtonElement;
    signOut.click();

    expect(stop).toHaveBeenCalled();
    http.expectOne(LOGOUT).flush(null, { status: 204, statusText: 'No Content' });
  });

  /**
   * The company name is drawn once. A second, CSS-hidden copy for another
   * breakpoint made `getByText(company)` in the E2E journey resolve to two
   * elements; responsive CSS now moves the single one instead.
   */
  it('renders the company name exactly once', async () => {
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());
    const fixture = TestBed.createComponent(AppShellComponent);
    const element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    await fixture.whenStable();

    const name = makeCompany().name;
    const holders = Array.from(element.querySelectorAll('*')).filter(
      (node) => node.children.length === 0 && (node.textContent ?? '').includes(name)
    );
    expect(holders).toHaveLength(1);
    expect(holders[0].getAttribute('data-testid')).toBe('shell-company');
  });

  it('marks the view you are on and offers search from the command bar', async () => {
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());
    const fixture = TestBed.createComponent(AppShellComponent);
    const element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    await fixture.whenStable();

    const views = element.querySelector('[data-testid="shell-views"]');
    expect(views?.getAttribute('aria-label')).toBe('Primary');
    expect(Array.from(views?.querySelectorAll('a') ?? []).map((link) => link.getAttribute('href'))).toEqual([
      '/app/inbox',
      '/app/overview',
      '/app/integrations',
      '/app/settings'
    ]);

    const command = element.querySelector('[data-testid="shell-command"]');
    expect(command?.getAttribute('aria-label')).toBe('Search comments');
    expect(command?.getAttribute('aria-keyshortcuts')).toContain('Control+K');
  });

  describe('phone account sheet', () => {
    async function openSheet() {
      TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());
      const fixture = TestBed.createComponent(AppShellComponent);
      document.body.appendChild(fixture.nativeElement);
      fixture.detectChanges();
      await fixture.whenStable();

      const element = fixture.nativeElement as HTMLElement;
      const trigger = element.querySelector('button[aria-controls="shell-account-sheet"]') as HTMLButtonElement;
      trigger.focus();
      trigger.click();
      fixture.detectChanges();
      await fixture.whenStable();

      const sheet = element.querySelector('#shell-account-sheet') as HTMLElement;
      return { fixture, element, trigger, sheet };
    }

    it('opens as a modal dialog, takes focus and makes the page behind it inert', async () => {
      const { fixture, element, sheet } = await openSheet();

      expect(sheet.getAttribute('role')).toBe('dialog');
      expect(sheet.getAttribute('aria-modal')).toBe('true');
      expect(element.querySelector(`#${sheet.getAttribute('aria-labelledby')}`)?.textContent).toContain(makeUser().name);
      expect(sheet.contains(document.activeElement)).toBe(true);
      for (const selector of ['main', 'header', '[data-testid="shell-tabbar"]', '[data-testid="shell-views"]']) {
        expect(element.querySelector(selector)?.hasAttribute('inert')).toBe(true);
      }

      fixture.destroy();
    });

    it('keeps Tab inside the sheet, wrapping at either end', async () => {
      const { fixture, sheet } = await openSheet();
      const focusable = Array.from(sheet.querySelectorAll<HTMLElement>('button:not([disabled]), a[href]'));
      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      last.focus();
      sheet.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
      expect(document.activeElement).toBe(first);

      first.focus();
      sheet.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
      expect(document.activeElement).toBe(last);

      fixture.destroy();
    });

    it('closes on Escape, lifts inert and returns focus to the avatar that opened it', async () => {
      const { fixture, element, trigger } = await openSheet();

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      fixture.detectChanges();
      await fixture.whenStable();

      expect(element.querySelector('#shell-account-sheet')).toBeNull();
      expect(element.querySelector('main')?.hasAttribute('inert')).toBe(false);
      expect(document.activeElement).toBe(trigger);

      fixture.destroy();
    });
  });
});
