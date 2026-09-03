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

    const signOut = Array.from(element.querySelectorAll('button')).find(
      (button) => button.getAttribute('aria-label') === 'Sign out'
    ) as HTMLButtonElement;
    signOut.click();

    expect(stop).toHaveBeenCalled();
    http.expectOne(LOGOUT).flush(null, { status: 204, statusText: 'No Content' });
  });
});
