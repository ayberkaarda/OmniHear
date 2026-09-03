import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Route, Router, Routes } from '@angular/router';

import { routes } from './app.routes';
import { makeCompany, makeUser } from './core/auth/auth.fixtures';
import { AuthStore } from './core/auth/auth.store';

interface FlatRoute {
  path: string;
  route: Route;
}

/** Resolves every `loadComponent` / `loadChildren` in the tree, depth first. */
async function flatten(input: Routes, prefix = ''): Promise<FlatRoute[]> {
  const result: FlatRoute[] = [];

  for (const route of input) {
    const segment = route.path ?? '';
    const path = [prefix, segment].filter((part) => part.length > 0).join('/');
    result.push({ path, route });

    if (route.loadChildren !== undefined) {
      const children = (await (route.loadChildren as () => Promise<Routes>)()) as Routes;
      result.push(...(await flatten(children, path)));
    }
    if (route.children !== undefined) {
      result.push(...(await flatten(route.children, path)));
    }
  }

  return result;
}

async function componentOf(route: Route): Promise<Type<unknown> | null> {
  if (route.loadComponent === undefined) {
    return null;
  }
  return (await (route.loadComponent as () => Promise<Type<unknown>>)()) as Type<unknown>;
}

describe('application route tree', () => {
  let flat: FlatRoute[];

  beforeAll(async () => {
    flat = await flatten(routes);
  });

  it('covers every page of spec section 4', () => {
    const paths = new Set(flat.map((entry) => entry.path));

    for (const expected of [
      '',
      'billing/success',
      'billing/cancel',
      'auth/login',
      'auth/register',
      'auth/forgot-password',
      'auth/reset-password',
      'auth/verify-email',
      'app/overview',
      'app/inbox',
      'app/inbox/:id',
      'app/integrations',
      'app/settings/profile',
      'app/settings/team',
      'app/settings/billing',
      'app/settings/api-keys',
      'app/settings/notifications',
      '402',
      '**'
    ]) {
      expect(paths).toContain(expected);
    }
  });

  it('loads every screen lazily - no eager `component` reference anywhere', () => {
    for (const entry of flat) {
      expect(entry.route.component).toBeUndefined();
      if (entry.route.redirectTo === undefined && entry.route.children === undefined) {
        expect(entry.route.loadComponent ?? entry.route.loadChildren).toBeDefined();
      }
    }
  });

  it('guards `/app` and keeps `/auth/verify-email` reachable while signed in', () => {
    const named = (route: Route | undefined): string[] =>
      (route?.canActivate ?? []).map((guard) => (guard as { name: string }).name);

    const appEntries = flat.filter((entry) => entry.path === 'app');
    // The outer route carries `authGuard` and nothing heavier: everything it
    // imports lands in the initial bundle.
    expect(named(appEntries[0]?.route)).toEqual(['authGuard']);

    // Spec section 4's `SubscriptionGuard` guards the same subtree from inside
    // the lazily loaded config — `canActivate` runs after the config is
    // loaded, so this protects the same URLs at the same moment while keeping
    // `BillingStore` out of the initial chunk (measured: 6.05 kB).
    expect(appEntries.some((entry) => named(entry.route).includes('subscriptionGuard'))).toBe(true);

    // A guest guard here would fight errorInterceptor's 403 EMAIL_NOT_VERIFIED
    // redirect and produce a loop.
    const verify = flat.find((entry) => entry.path === 'auth/verify-email')?.route;
    expect(verify?.canActivate).toBeUndefined();

    for (const path of ['auth/login', 'auth/register', 'auth/forgot-password', 'auth/reset-password']) {
      expect(flat.find((entry) => entry.path === path)?.route.canActivate).toHaveLength(1);
    }
  });

  it('every routed component instantiates and renders', async () => {
    for (const entry of flat) {
      const component = await componentOf(entry.route);
      if (component === null) {
        continue;
      }

      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
      });
      TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());

      const fixture = TestBed.createComponent(component);
      fixture.detectChanges();

      expect((fixture.nativeElement as HTMLElement).innerHTML.length).toBeGreaterThan(0);
    }
  });

  /**
   * The return leg of the checkout journey. `config/stripe.php` points the
   * provider at `FRONTEND_URL/billing/{success,cancel}`; before these two
   * routes existed the user paid and landed on the not-found screen.
   */
  it('brings the browser back from a provider into the billing screen', async () => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [provideRouter(routes), provideHttpClient(), provideHttpClientTesting()]
    });
    const router = TestBed.inject(Router);
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany());

    // The redirect itself, resolved through the router rather than asserted on
    // the config: `redirectTo` is a function here, and a function that never
    // ran would be indistinguishable from a correct one.
    await router.navigateByUrl('/billing/success');
    expect(router.url).toBe('/app/settings/billing?checkout=success');

    await router.navigateByUrl('/billing/cancel');
    expect(router.url).toBe('/app/settings/billing?checkout=cancel');
  });

  /**
   * The four data screens no longer render a placeholder empty state, because
   * they are no longer placeholders: with `provideHttpClientTesting` their read
   * is in flight, so what they must render is a *loading* state. The assertion
   * is the same one it always was — the screen states what it actually knows —
   * only the honest answer has changed for those four.
   */
  const LIVE_SCREEN_BUSY_SELECTOR: Readonly<Record<string, string>> = {
    'app/overview': '[data-testid="kpi-skeleton"]',
    'app/inbox': '[data-testid="data-table-loading"]',
    'app/inbox/:id': '[data-testid="detail-skeleton"]',
    'app/integrations': '[data-testid="integrations-skeleton"]',
    // The five settings screens stopped being placeholders in this phase, so
    // the honest answer for each of them changed from an empty state to a
    // loading one — the same assertion, a different true answer.
    'app/settings/profile': '[data-testid="profile-skeleton"]',
    'app/settings/team': '[data-testid="team-skeleton"]',
    'app/settings/billing': '[data-testid="billing-skeleton"]',
    'app/settings/api-keys': '[data-testid="api-keys-skeleton"]',
    'app/settings/notifications': '[data-testid="notifications-skeleton"]'
  };

  it('gives every `/app` screen a heading and an honest state for what it knows', async () => {
    const screens = flat.filter((entry) => entry.path.startsWith('app/') && entry.route.loadComponent !== undefined);
    // overview, inbox, inbox/:id, integrations, the settings layout and its five children.
    expect(screens.length).toBe(10);

    for (const entry of screens) {
      const component = (await componentOf(entry.route)) as Type<unknown>;

      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
      });

      const fixture = TestBed.createComponent(component);
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;

      expect(element.querySelector('h1, h2')).toBeTruthy();

      const busySelector = LIVE_SCREEN_BUSY_SELECTOR[entry.path];
      if (busySelector !== undefined) {
        expect(element.querySelector(busySelector)).toBeTruthy();
      } else if (entry.path !== 'app/settings') {
        expect(element.querySelector('[data-testid="empty-state"]')).toBeTruthy();
      }
    }
  });
});
