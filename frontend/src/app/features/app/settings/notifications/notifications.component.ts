import { ChangeDetectionStrategy, Component, computed, inject, OnInit } from '@angular/core';

import { errorMessageForCode } from '../../../../core/errors/error-messages';
import { NotificationsStore } from '../../../../core/settings/notifications.store';
import { AppNotification, NotificationChannels } from '../../../../core/settings/settings.models';
import { ButtonComponent } from '../../../../shared/ui/button/button.component';
import { EmptyStateComponent } from '../../../../shared/ui/empty-state/empty-state.component';
import { formatDateTime } from '../../../../shared/format/format';

interface PreferenceRow {
  readonly type: string;
  readonly label: string;
  readonly description: string;
  readonly channels: NotificationChannels;
}

/**
 * `/app/settings/notifications` — how we reach you, and what we have sent.
 *
 * Spec 7.3 requires the 80% quota warning by **email and in-app**, which is why
 * there are two channels per row and why this screen also renders what the
 * in-app channel produced. A preferences page that could not show the
 * notifications it governs would be a switch with no lamp.
 *
 * Rows come from the server's key set, not a list written here: a notification
 * type added on the backend appears with a fallback label rather than being
 * silently unconfigurable.
 */
@Component({
  selector: 'app-notifications',
  standalone: true,
  imports: [ButtonComponent, EmptyStateComponent],
  templateUrl: './notifications.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class NotificationsComponent implements OnInit {
  private readonly store = inject(NotificationsStore);

  protected readonly loading = this.store.loading;
  protected readonly itemsLoading = this.store.itemsLoading;
  protected readonly items = this.store.items;
  protected readonly saving = this.store.saving;
  protected readonly markingId = this.store.markingId;
  protected readonly isEmpty = this.store.isEmpty;
  protected readonly unreadCount = this.store.unreadCount;

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  protected readonly rows = computed<readonly PreferenceRow[]>(() => {
    const preferences = this.store.preferences();
    return this.store.types().map((type) => ({
      type,
      label: notificationTypeLabel(type),
      description: notificationTypeDescription(type),
      channels: preferences[type]
    }));
  });

  protected readonly mailLabel = $localize`:Notification delivery channel@@settings.notifications.channel.mail:Email`;
  protected readonly databaseLabel = $localize`:Notification delivery channel@@settings.notifications.channel.database:In the app`;
  protected readonly markReadLabel = $localize`:Mark a notification as read@@settings.notifications.markRead:Mark as read`;
  protected readonly readLabel = $localize`:State of an already-read notification@@settings.notifications.read:Read`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly emptyHeading = $localize`:Empty state heading@@app.settings.notifications.empty:Nothing has been sent yet`;
  protected readonly emptyDescription = $localize`:Notifications empty state@@settings.notifications.empty.description:Messages we send you in the app — the quota warning, for one — collect here.`;

  ngOnInit(): void {
    this.store.loadIfNeeded();
  }

  protected reload(): void {
    this.store.load();
  }

  protected checkboxLabel(row: PreferenceRow, channel: keyof NotificationChannels): string {
    const channelName = channel === 'mail' ? this.mailLabel : this.databaseLabel;
    return `${row.label} — ${channelName}`;
  }

  protected onToggle(row: PreferenceRow, channel: keyof NotificationChannels, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    this.store.setChannel(row.type, channel, checked);
  }

  protected sentAt(item: AppNotification): string {
    return formatDateTime(item.created_at);
  }

  /**
   * Notification bodies are server data, so the type is rendered as a known
   * heading and the payload is not interpolated into a sentence. Anything else
   * would put an unbounded server string into the UI's own copy.
   */
  protected titleOf(item: AppNotification): string {
    return notificationTypeLabel(shortType(item.type));
  }

  protected isUnread(item: AppNotification): boolean {
    return item.read_at === null;
  }

  protected markRead(item: AppNotification): void {
    this.store.markRead(item.id);
  }
}

/** `App\Notifications\QuotaWarningNotification` -> `quota_warning`. */
function shortType(type: string): string {
  const tail = type.split('\\').pop() ?? type;
  return tail
    .replace(/Notification$/, '')
    .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .toLowerCase();
}

function notificationTypeLabel(type: string): string {
  switch (type) {
    case 'quota_warning':
      return $localize`:Notification type@@settings.notifications.type.quotaWarning:Quota running out`;
    default:
      return type;
  }
}

function notificationTypeDescription(type: string): string {
  switch (type) {
    case 'quota_warning':
      return $localize`:Notification type description@@settings.notifications.type.quotaWarning.description:Sent once you have used 80% of the analyses in your plan, while there is still time to act.`;
    default:
      return '';
  }
}
