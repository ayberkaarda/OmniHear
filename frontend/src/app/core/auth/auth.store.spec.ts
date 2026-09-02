import { TestBed } from '@angular/core/testing';

import { makeCompany, makeUser } from './auth.fixtures';
import { AuthStore } from './auth.store';

const TOKEN_KEY = 'omnihear.token';

function newStore(): AuthStore {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({ providers: [AuthStore] });
  return TestBed.inject(AuthStore);
}

describe('AuthStore', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('starts anonymous when no token is stored', () => {
    const store = newStore();

    expect(store.status()).toBe('anonymous');
    expect(store.isAuthenticated()).toBe(false);
    expect(store.token()).toBeNull();
    expect(store.role()).toBeNull();
  });

  it('starts in the unknown state when a token is restored from storage', () => {
    localStorage.setItem(TOKEN_KEY, '1|restored');

    const store = newStore();

    expect(store.token()).toBe('1|restored');
    expect(store.status()).toBe('unknown');
    // A restored token alone is not proof of a session: it may have been revoked.
    expect(store.isAuthenticated()).toBe(false);
  });

  it('setSession authenticates, exposes the identity and persists the token', () => {
    const store = newStore();

    store.setSession('1|abc', makeUser(), makeCompany());

    expect(store.isAuthenticated()).toBe(true);
    expect(store.status()).toBe('authenticated');
    expect(store.user()?.email).toBe('ada@acme.com');
    expect(store.company()?.quota_limit).toBe(200);
    expect(store.role()).toBe('owner');
    expect(localStorage.getItem(TOKEN_KEY)).toBe('1|abc');
  });

  it('setIdentity settles an unknown session without touching the token', () => {
    localStorage.setItem(TOKEN_KEY, '1|restored');
    const store = newStore();

    store.setIdentity(makeUser(), makeCompany());

    expect(store.status()).toBe('authenticated');
    expect(store.token()).toBe('1|restored');
  });

  it('isEmailVerified follows email_verified_at', () => {
    const store = newStore();

    store.setSession('1|abc', makeUser({ email_verified_at: null }), makeCompany());
    expect(store.isEmailVerified()).toBe(false);

    store.setUser(makeUser({ email_verified_at: '2026-09-02T11:04:03+00:00' }));
    expect(store.isEmailVerified()).toBe(true);
  });

  it('clear() wipes the identity and removes the persisted token', () => {
    const store = newStore();
    store.setSession('1|abc', makeUser(), makeCompany());

    store.clear();

    expect(store.isAuthenticated()).toBe(false);
    expect(store.status()).toBe('anonymous');
    expect(store.user()).toBeNull();
    expect(store.company()).toBeNull();
    expect(localStorage.getItem(TOKEN_KEY)).toBeNull();
  });
});
