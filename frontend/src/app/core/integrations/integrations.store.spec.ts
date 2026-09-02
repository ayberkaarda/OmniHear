import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { ToastService } from '../toast/toast.service';
import { makeIntegration, makeIntegrationPage } from './integration.fixtures';
import { IntegrationsStore } from './integrations.store';

const BASE = `${environment.apiBaseUrl}/v1/integrations`;

describe('IntegrationsStore', () => {
  let store: IntegrationsStore;
  let toasts: ToastService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(IntegrationsStore);
    toasts = TestBed.inject(ToastService);
    http = TestBed.inject(HttpTestingController);
    store.reset();
    toasts.clear();
  });

  afterEach(() => http.verify());

  it('reads the list at the contract maximum page size', () => {
    store.load();
    const request = http.expectOne((candidate) => candidate.url === BASE);
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(makeIntegrationPage());

    expect(store.state()).toBe('ready');
    expect(store.items()).toHaveLength(1);
    expect(store.isEmpty()).toBe(false);
  });

  it('sends credentials on create and never keeps them anywhere afterwards', () => {
    store.load();
    http.expectOne((candidate) => candidate.url === BASE).flush(makeIntegrationPage([]));

    store.create({
      platform: 'zendesk',
      settings: { subdomain: 'acme' },
      credentials: { email: 'ops@acme.com', api_token: 'secret-token' }
    });

    const post = http.expectOne((candidate) => candidate.url === BASE && candidate.method === 'POST');
    expect(post.request.body).toEqual({
      platform: 'zendesk',
      settings: { subdomain: 'acme' },
      credentials: { email: 'ops@acme.com', api_token: 'secret-token' }
    });

    // The API answers without credentials (invariant I5) and the store keeps
    // exactly what the API said — the secret exists only in the request body.
    post.flush({ integration: makeIntegration({ id: 2, platform: 'zendesk' }) });
    http.expectOne((candidate) => candidate.url === BASE).flush(makeIntegrationPage([makeIntegration({ id: 2 })]));

    expect(JSON.stringify(store.items())).not.toContain('secret-token');
    expect(store.saving()).toBe(false);
  });

  it('keeps a 422 field map for the form and stops saving', () => {
    store.create({ platform: 'appstore', settings: {} });

    http
      .expectOne((candidate) => candidate.method === 'POST')
      .flush(
        { code: 'VALIDATION_ERROR', message: 'invalid', errors: { 'settings.app_id': ['required'] } },
        { status: 422, statusText: 'Unprocessable Content' }
      );

    expect(store.saving()).toBe(false);
    expect(store.saveErrors()).toEqual({ 'settings.app_id': ['required'] });
  });

  it('marks only the row being synced as busy, and toasts once queued', () => {
    store.load();
    http
      .expectOne((candidate) => candidate.url === BASE)
      .flush(makeIntegrationPage([makeIntegration({ id: 1 }), makeIntegration({ id: 2 })]));

    store.sync(1);
    expect(store.syncingIds().has(1)).toBe(true);
    expect(store.syncingIds().has(2)).toBe(false);

    // A second click while the first is in flight must not queue a second run.
    store.sync(1);
    const sync = http.expectOne(`${BASE}/1/sync`);
    sync.flush({ message: 'Sync queued.' }, { status: 202, statusText: 'Accepted' });

    expect(store.syncingIds().has(1)).toBe(false);
    expect(toasts.toasts()).toHaveLength(1);
    expect(toasts.toasts()[0].tone).toBe('success');

    http.expectOne((candidate) => candidate.url === BASE).flush(makeIntegrationPage());
  });

  it('clears the busy flag and raises no toast when a sync is refused', () => {
    store.sync(3);
    http
      .expectOne(`${BASE}/3/sync`)
      .flush({ code: 'SYNC_IN_PROGRESS', message: 'busy' }, { status: 409, statusText: 'Conflict' });

    expect(store.syncingIds().has(3)).toBe(false);
    // 409 is already on screen as a toast keyed by its code, raised by the
    // interceptor; a second one here would double it.
    expect(toasts.toasts()).toHaveLength(0);
  });

  it('drops the row locally on a 204 delete', () => {
    store.load();
    http
      .expectOne((candidate) => candidate.url === BASE)
      .flush(makeIntegrationPage([makeIntegration({ id: 1 }), makeIntegration({ id: 2 })]));

    const closed = jest.fn();
    store.remove(1, closed);
    expect(store.deletingId()).toBe(1);

    http.expectOne(`${BASE}/1`).flush(null, { status: 204, statusText: 'No Content' });

    expect(store.deletingId()).toBeNull();
    expect(store.items().map((item) => item.id)).toEqual([2]);
    expect(closed).toHaveBeenCalledTimes(1);
  });

  it('omits credentials from a PATCH that left them blank', () => {
    store.update(4, { settings: { subdomain: 'acme' }, status: 'paused' });

    const patch = http.expectOne(`${BASE}/4`);
    expect(patch.request.method).toBe('PATCH');
    expect(patch.request.body).toEqual({ settings: { subdomain: 'acme' }, status: 'paused' });
    expect(Object.keys(patch.request.body as object)).not.toContain('credentials');

    patch.flush({ integration: makeIntegration({ id: 4, status: 'paused' }) });
    http.expectOne((candidate) => candidate.url === BASE).flush(makeIntegrationPage());
  });
});
