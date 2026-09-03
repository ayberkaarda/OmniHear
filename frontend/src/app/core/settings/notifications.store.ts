import { computed, inject, Injectable, signal } from '@angular/core';

import { EMPTY_META, PaginationMeta } from '../api/pagination';
import { RequestState } from '../api/request-state';
import { errorCodeOf } from '../errors/error-code';
import { ToastService } from '../toast/toast.service';
import { AppNotification, NotificationChannels, NotificationPreferences } from './settings.models';
import { SettingsService } from './settings.service';

/**
 * `/app/settings/notifications` — the delivery preferences and the in-app inbox.
 *
 * Spec 7.3 requires the 80% quota warning by **email and in-app**, which is
 * why both a `mail` and a `database` channel exist per notification type and
 * why this screen also renders what the `database` channel produced.
 *
 * The preference map is rendered from whatever keys the server sends rather
 * than a hard-coded list: a notification type added on the backend would
 * otherwise be invisible here and silently un-configurable.
 */
@Injectable({ providedIn: 'root' })
export class NotificationsStore {
  private readonly service = inject(SettingsService);
  private readonly toasts = inject(ToastService);

  private readonly preferencesSignal = signal<NotificationPreferences>({});
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);
  private readonly savingSignal = signal(false);

  private readonly itemsSignal = signal<readonly AppNotification[]>([]);
  private readonly metaSignal = signal<PaginationMeta>(EMPTY_META);
  private readonly itemsStateSignal = signal<RequestState>('idle');
  private readonly markingIdSignal = signal<string | null>(null);

  private requestToken = 0;

  readonly preferences = this.preferencesSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();
  readonly saving = this.savingSignal.asReadonly();
  readonly items = this.itemsSignal.asReadonly();
  readonly meta = this.metaSignal.asReadonly();
  readonly itemsState = this.itemsStateSignal.asReadonly();
  readonly markingId = this.markingIdSignal.asReadonly();

  readonly loading = computed(() => this.stateSignal() === 'idle' || this.stateSignal() === 'loading');
  readonly itemsLoading = computed(
    () => this.itemsStateSignal() === 'idle' || this.itemsStateSignal() === 'loading'
  );
  readonly types = computed(() => Object.keys(this.preferencesSignal()).sort());
  readonly unreadCount = computed(() => this.itemsSignal().filter((item) => item.read_at === null).length);
  readonly isEmpty = computed(() => this.itemsStateSignal() === 'ready' && this.itemsSignal().length === 0);

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.itemsStateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.notificationPreferences().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.preferencesSignal.set(response.preferences);
        this.stateSignal.set('ready');
      },
      error: (error: unknown) => {
        if (token !== this.requestToken) {
          return;
        }
        this.errorCodeSignal.set(errorCodeOf(error));
        this.stateSignal.set('error');
      }
    });

    this.service.notifications().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.itemsSignal.set(response.data);
        this.metaSignal.set(response.meta);
        this.itemsStateSignal.set('ready');
      },
      error: () => {
        if (token !== this.requestToken) {
          return;
        }
        this.itemsStateSignal.set('error');
      }
    });
  }

  loadIfNeeded(): void {
    if (this.stateSignal() === 'idle') {
      this.load();
    }
  }

  /**
   * Optimistic, and it has to be: a checkbox that only moves once the server
   * answers reads as broken. The previous map is restored on failure, so the
   * control never ends up claiming a setting that was not saved.
   */
  setChannel(type: string, channel: keyof NotificationChannels, enabled: boolean): void {
    const previous = this.preferencesSignal();
    const current = previous[type];
    if (current === undefined || this.savingSignal()) {
      return;
    }

    const next: NotificationPreferences = { ...previous, [type]: { ...current, [channel]: enabled } };
    this.preferencesSignal.set(next);
    this.savingSignal.set(true);

    this.service.updateNotificationPreferences(next).subscribe({
      next: (response) => {
        this.savingSignal.set(false);
        this.preferencesSignal.set(response.preferences);
      },
      error: () => {
        this.savingSignal.set(false);
        this.preferencesSignal.set(previous);
        this.toasts.error(
          $localize`:Toast when a notification preference could not be saved@@settings.notifications.saveFailed:That notification setting could not be saved. It has been put back the way it was.`
        );
      }
    });
  }

  markRead(id: string): void {
    if (this.markingIdSignal() !== null) {
      return;
    }
    this.markingIdSignal.set(id);

    this.service.markNotificationRead(id).subscribe({
      next: () => {
        this.markingIdSignal.set(null);
        const readAt = new Date().toISOString();
        this.itemsSignal.update((items) =>
          items.map((item) => (item.id === id ? { ...item, read_at: item.read_at ?? readAt } : item))
        );
      },
      error: () => {
        this.markingIdSignal.set(null);
      }
    });
  }

  reset(): void {
    this.requestToken++;
    this.preferencesSignal.set({});
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
    this.savingSignal.set(false);
    this.itemsSignal.set([]);
    this.metaSignal.set(EMPTY_META);
    this.itemsStateSignal.set('idle');
    this.markingIdSignal.set(null);
  }
}
