import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, Router, RouterStateSnapshot, UrlTree } from '@angular/router';
import { firstValueFrom, isObservable, Observable } from 'rxjs';

import { environment } from '../../../environments/environment';
import { authGuard, guestGuard } from './auth.guard';
import { makeCompany, makeUser } from './auth.fixtures';
import { AuthStore } from './auth.store';

const LOGIN_TREE = { __tree: 'login' } as unknown as UrlTree;
const OVERVIEW_TREE = { __tree: 'overview' } as unknown as UrlTree;

interface RouterStub {
  createUrlTree: jest.Mock;
}

function setup(storedToken: string | null): { store: AuthStore; router: RouterStub; http: HttpTestingController } {
  localStorage.clear();
  if (storedToken !== null) {
    localStorage.setItem('omnihear.token', storedToken);
  }

  const router: RouterStub = {
    createUrlTree: jest.fn((commands: unknown[]) => (commands[0] === '/auth/login' ? LOGIN_TREE : OVERVIEW_TREE))
  };

  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), { provide: Router, useValue: router }]
  });

  return { store: TestBed.inject(AuthStore), router, http: TestBed.inject(HttpTestingController) };
}

const route = {} as ActivatedRouteSnapshot;
const state = { url: '/app/inbox/42' } as RouterStateSnapshot;

function runAuthGuard(): boolean | UrlTree | Observable<boolean | UrlTree> {
  return TestBed.runInInjectionContext(() => authGuard(route, state)) as
    | boolean
    | UrlTree
    | Observable<boolean | UrlTree>;
}

describe('authGuard', () => {
  it('lets an authenticated user through', () => {
    const { store } = setup(null);
    store.setSession('1|abc', makeUser(), makeCompany());

    expect(runAuthGuard()).toBe(true);
  });

  it('redirects to the login page and remembers the requested url', () => {
    const { router } = setup(null);

    expect(runAuthGuard()).toBe(LOGIN_TREE);
    expect(router.createUrlTree).toHaveBeenCalledWith(['/auth/login'], {
      queryParams: { redirect: '/app/inbox/42' }
    });
  });

  it('resolves a restored token with GET /auth/me before deciding', async () => {
    const { http } = setup('1|restored');

    const result = runAuthGuard();
    expect(isObservable(result)).toBe(true);

    const pending = firstValueFrom(result as Observable<boolean | UrlTree>);
    http
      .expectOne(`${environment.apiBaseUrl}/v1/auth/me`)
      .flush({ user: makeUser(), company: makeCompany() });

    await expect(pending).resolves.toBe(true);
  });

  it('clears the store and redirects when the restored token is rejected', async () => {
    const { http, store } = setup('1|revoked');

    const pending = firstValueFrom(runAuthGuard() as Observable<boolean | UrlTree>);
    http
      .expectOne(`${environment.apiBaseUrl}/v1/auth/me`)
      .flush({ code: 'UNAUTHENTICATED', message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    await expect(pending).resolves.toBe(LOGIN_TREE);
    expect(store.isAuthenticated()).toBe(false);
    expect(localStorage.getItem('omnihear.token')).toBeNull();
  });
});

describe('guestGuard', () => {
  it('allows an anonymous visitor', () => {
    setup(null);

    expect(TestBed.runInInjectionContext(() => guestGuard(route, state))).toBe(true);
  });

  it('sends an authenticated user to the dashboard', () => {
    const { store, router } = setup(null);
    store.setSession('1|abc', makeUser(), makeCompany());

    expect(TestBed.runInInjectionContext(() => guestGuard(route, state))).toBe(OVERVIEW_TREE);
    expect(router.createUrlTree).toHaveBeenCalledWith(['/app/overview']);
  });
});
