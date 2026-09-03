import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { ToastService } from '../toast/toast.service';
import { NotificationsStore } from './notifications.store';
import { makeNotification, makeNotificationPage, makeNotificationPreferences } from './settings.fixtures';

const PREFERENCES = `${environment.apiBaseUrl}/v1/settings/notifications`;
const NOTIFICATIONS = `${environment.apiBaseUrl}/v1/notifications`;

describe('NotificationsStore', () => {
  let store: NotificationsStore;
  let toasts: ToastService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(NotificationsStore);
    toasts = TestBed.inject(ToastService);
    http = TestBed.inject(HttpTestingController);
    store.reset();
    toasts.clear();
  });

  afterEach(() => http.verify());

  function settle(): void {
    store.load();
    http.expectOne(PREFERENCES).flush(makeNotificationPreferences());
    http.expectOne(NOTIFICATIONS).flush(makeNotificationPage());
  }

  it('renders whatever keys the server sends, not a list written here', () => {
    store.load();
    http.expectOne(PREFERENCES).flush({
      preferences: {
        quota_warning: { mail: true, database: true },
        weekly_digest: { mail: false, database: true }
      }
    });
    http.expectOne(NOTIFICATIONS).flush(makeNotificationPage([]));

    expect(store.types()).toEqual(['quota_warning', 'weekly_digest']);
  });

  it('sends the whole map on a toggle and takes the server answer back', () => {
    settle();

    store.setChannel('quota_warning', 'mail', false);
    // Optimistic: the checkbox has already moved.
    expect(store.preferences()['quota_warning'].mail).toBe(false);

    const request = http.expectOne(PREFERENCES);
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ preferences: { quota_warning: { mail: false, database: true } } });
    request.flush({ preferences: { quota_warning: { mail: false, database: true } } });

    expect(store.saving()).toBe(false);
    expect(store.preferences()['quota_warning'].mail).toBe(false);
  });

  /** A control that keeps a setting the server rejected is worse than no control. */
  it('puts the toggle back when the save fails, and says so', () => {
    settle();

    store.setChannel('quota_warning', 'database', false);
    http
      .expectOne(PREFERENCES)
      .flush({ code: 'SERVER_ERROR', message: 'boom' }, { status: 500, statusText: 'Server Error' });

    expect(store.preferences()['quota_warning'].database).toBe(true);
    expect(toasts.toasts()).toHaveLength(1);
  });

  it('marks one notification read without re-reading the list', () => {
    store.load();
    http.expectOne(PREFERENCES).flush(makeNotificationPreferences());
    http.expectOne(NOTIFICATIONS).flush(makeNotificationPage([makeNotification({ id: 'abc' })]));

    expect(store.unreadCount()).toBe(1);

    store.markRead('abc');
    const request = http.expectOne(`${NOTIFICATIONS}/abc/read`);
    expect(request.request.method).toBe('POST');
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(store.unreadCount()).toBe(0);
    expect(store.items()[0].read_at).not.toBeNull();
  });

  it('keeps the preference half usable when the message list fails', () => {
    store.load();
    http.expectOne(PREFERENCES).flush(makeNotificationPreferences());
    http.expectOne(NOTIFICATIONS).flush({ code: 'SERVER_ERROR', message: 'boom' }, { status: 500, statusText: 'x' });

    expect(store.state()).toBe('ready');
    expect(store.itemsState()).toBe('error');
    expect(store.types()).toEqual(['quota_warning']);
  });
});
