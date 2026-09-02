import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Route, Routes } from '@angular/router';

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
    const appRoute = flat.find((entry) => entry.path === 'app')?.route;
    expect(appRoute?.canActivate).toHaveLength(1);

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

  it('gives every `/app` screen a heading and an honest empty state', async () => {
    const placeholders = flat.filter(
      (entry) => entry.path.startsWith('app/') && entry.route.loadComponent !== undefined
    );
    // overview, inbox, inbox/:id, integrations, the settings layout and its five children.
    expect(placeholders.length).toBe(10);

    for (const entry of placeholders) {
      const component = (await componentOf(entry.route)) as Type<unknown>;

      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
      });

      const fixture = TestBed.createComponent(component);
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;

      expect(element.querySelector('h1, h2')).toBeTruthy();
      if (entry.path !== 'app/settings') {
        expect(element.querySelector('[data-testid="empty-state"]')).toBeTruthy();
      }
    }
  });
});
