import { computed, Injectable, signal } from '@angular/core';

import { Company, User } from './auth.models';

const TOKEN_STORAGE_KEY = 'omnihear.token';

/**
 * `unknown`  — a token was restored from storage but `GET /auth/me` has not
 *              confirmed it yet, so we must not decide anything from it.
 * `authenticated` / `anonymous` — settled states.
 */
export type SessionStatus = 'unknown' | 'authenticated' | 'anonymous';

function readStoredToken(): string | null {
  try {
    return typeof localStorage !== 'undefined' ? localStorage.getItem(TOKEN_STORAGE_KEY) : null;
  } catch {
    // Private browsing / storage disabled: fall back to an in-memory session.
    return null;
  }
}

function writeStoredToken(token: string | null): void {
  try {
    if (typeof localStorage === 'undefined') {
      return;
    }
    if (token === null) {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
    } else {
      localStorage.setItem(TOKEN_STORAGE_KEY, token);
    }
  } catch {
    // Ignore — the session simply does not survive a reload.
  }
}

/**
 * Single source of truth for "who is signed in".
 *
 * Deliberately signal-only and dependency-free: the HTTP interceptors, the route
 * guards and the shell header all read it, so it must not pull `HttpClient` in
 * (that would make the interceptor -> store -> interceptor cycle unbreakable).
 * Network calls live in `AuthService`, which writes into this store.
 */
@Injectable({ providedIn: 'root' })
export class AuthStore {
  private readonly tokenSignal = signal<string | null>(readStoredToken());
  private readonly userSignal = signal<User | null>(null);
  private readonly companySignal = signal<Company | null>(null);
  private readonly statusSignal = signal<SessionStatus>(readStoredToken() === null ? 'anonymous' : 'unknown');

  readonly token = this.tokenSignal.asReadonly();
  readonly user = this.userSignal.asReadonly();
  readonly company = this.companySignal.asReadonly();
  readonly status = this.statusSignal.asReadonly();

  readonly isAuthenticated = computed(() => this.statusSignal() === 'authenticated' && this.userSignal() !== null);
  readonly isEmailVerified = computed(() => this.userSignal()?.email_verified_at != null);
  readonly role = computed(() => this.userSignal()?.role ?? null);

  /** Called after register/login/me — the only way to reach `authenticated`. */
  setSession(token: string, user: User, company: Company): void {
    this.tokenSignal.set(token);
    writeStoredToken(token);
    this.userSignal.set(user);
    this.companySignal.set(company);
    this.statusSignal.set('authenticated');
  }

  /** Refreshes the identity while keeping the token that is already held. */
  setIdentity(user: User, company: Company): void {
    this.userSignal.set(user);
    this.companySignal.set(company);
    this.statusSignal.set(this.tokenSignal() === null ? 'anonymous' : 'authenticated');
  }

  setUser(user: User): void {
    this.userSignal.set(user);
  }

  setCompany(company: Company): void {
    this.companySignal.set(company);
  }

  clear(): void {
    this.tokenSignal.set(null);
    writeStoredToken(null);
    this.userSignal.set(null);
    this.companySignal.set(null);
    this.statusSignal.set('anonymous');
  }
}
