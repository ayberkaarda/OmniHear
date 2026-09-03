import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { ApiKeysStore } from './api-keys.store';
import { makeApiKey, makeApiKeyCreated, makeDeviceSession } from './settings.fixtures';

const KEYS = `${environment.apiBaseUrl}/v1/settings/api-keys`;
const TOKENS = `${environment.apiBaseUrl}/v1/auth/tokens`;

describe('ApiKeysStore', () => {
  let store: ApiKeysStore;
  let auth: AuthStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(ApiKeysStore);
    auth = TestBed.inject(AuthStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
    auth.setSession('1|abc', makeUser(), makeCompany());
  });

  afterEach(() => http.verify());

  function settle(): void {
    store.load();
    http.expectOne(KEYS).flush({ data: [makeApiKey()] });
    http.expectOne(TOKENS).flush({ data: [makeDeviceSession()] });
  }

  /**
   * The boundary contract section 3 exists to protect: two lists, two
   * endpoints, and no control that can reach the other one's.
   */
  it('reads the two lists from the two different endpoints', () => {
    settle();

    expect(store.keys()).toHaveLength(1);
    expect(store.sessions()).toHaveLength(1);
    expect(store.state()).toBe('ready');
    expect(store.sessionsState()).toBe('ready');
  });

  it('revokes a key and a session through their own endpoints', () => {
    settle();

    store.revokeKey(1);
    const keyDelete = http.expectOne(`${KEYS}/1`);
    expect(keyDelete.request.method).toBe('DELETE');
    keyDelete.flush(null, { status: 204, statusText: 'No Content' });

    expect(store.keys()).toHaveLength(0);
    // The session list is untouched by a key revoke, and vice versa.
    expect(store.sessions()).toHaveLength(1);

    store.revokeSession(11);
    const sessionDelete = http.expectOne(`${TOKENS}/11`);
    expect(sessionDelete.request.method).toBe('DELETE');
    sessionDelete.flush(null, { status: 204, statusText: 'No Content' });

    expect(store.sessions()).toHaveLength(0);
  });

  /**
   * Signing a stolen device out is the one action here that must not depend on
   * anything else working, so the two reads carry separate state.
   */
  it('still lists devices when the key list fails', () => {
    store.load();
    http.expectOne(KEYS).flush({ code: 'FORBIDDEN', message: 'no' }, { status: 403, statusText: 'Forbidden' });
    http.expectOne(TOKENS).flush({ data: [makeDeviceSession()] });

    expect(store.state()).toBe('error');
    expect(store.sessionsState()).toBe('ready');
    expect(store.sessions()).toHaveLength(1);
  });

  it('holds the plaintext key only until the dialog is dismissed', () => {
    settle();

    store.create('staging');
    http.expectOne((candidate) => candidate.url === KEYS && candidate.method === 'POST').flush(makeApiKeyCreated(), {
      status: 201,
      statusText: 'Created'
    });

    expect(store.plainTextToken()).toBe('7|abcdefghijklmnopqrstuvwxyz');
    expect(store.createdKey()?.name).toBe('staging');
    // The row itself never carries the value; only the create response did.
    expect(JSON.stringify(store.keys())).not.toContain('abcdefghijklmnopqrstuvwxyz');

    store.clearPlainTextToken();
    expect(store.plainTextToken()).toBeNull();
    expect(store.createdKey()).toBeNull();
  });

  it('is `owner` or `admin` only for the key half', () => {
    auth.setSession('1|abc', makeUser({ role: 'member' }), makeCompany());
    expect(store.canManageKeys()).toBe(false);

    auth.setSession('1|abc', makeUser({ role: 'admin' }), makeCompany());
    expect(store.canManageKeys()).toBe(true);

    auth.setSession('1|abc', makeUser({ role: 'owner' }), makeCompany());
    expect(store.canManageKeys()).toBe(true);
  });

  it('keeps a create 422 on the name field', () => {
    settle();

    store.create('');
    http
      .expectOne((candidate) => candidate.url === KEYS && candidate.method === 'POST')
      .flush(
        { code: 'VALIDATION_ERROR', message: 'invalid', errors: { name: ['A name is required.'] } },
        { status: 422, statusText: 'Unprocessable Content' }
      );

    expect(store.createErrors()).toEqual({ name: ['A name is required.'] });
    expect(store.plainTextToken()).toBeNull();
  });
});
