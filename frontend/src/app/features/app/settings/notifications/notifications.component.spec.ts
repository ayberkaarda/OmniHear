import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../../environments/environment';
import { NotificationsStore } from '../../../../core/settings/notifications.store';
import {
  makeNotification,
  makeNotificationPage,
  makeNotificationPreferences
} from '../../../../core/settings/settings.fixtures';
import { NotificationsComponent } from './notifications.component';

const PREFERENCES = `${environment.apiBaseUrl}/v1/settings/notifications`;
const NOTIFICATIONS = `${environment.apiBaseUrl}/v1/notifications`;

describe('NotificationsComponent', () => {
  let fixture: ComponentFixture<NotificationsComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [NotificationsComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(NotificationsStore).reset();
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(NotificationsComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  function settle(items = [makeNotification()]): void {
    http.expectOne(PREFERENCES).flush(makeNotificationPreferences());
    http.expectOne(NOTIFICATIONS).flush(makeNotificationPage(items));
    fixture.detectChanges();
  }

  /** Spec 7.3: the quota warning has to be available by email *and* in-app. */
  it('offers both channels for the quota warning', () => {
    settle();

    const boxes = Array.from(element.querySelectorAll('input[type="checkbox"]')) as HTMLInputElement[];
    expect(boxes).toHaveLength(2);
    expect(boxes.every((box) => box.checked)).toBe(true);
    expect(element.textContent).toContain('Quota running out');
    expect(boxes[0].getAttribute('aria-label')).toContain('Email');
    expect(boxes[1].getAttribute('aria-label')).toContain('In the app');
  });

  it('saves a toggle as the whole preference map', () => {
    settle();

    const mail = element.querySelector('input[type="checkbox"]') as HTMLInputElement;
    mail.checked = false;
    mail.dispatchEvent(new Event('change'));

    const request = http.expectOne(PREFERENCES);
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ preferences: { quota_warning: { mail: false, database: true } } });
    request.flush({ preferences: { quota_warning: { mail: false, database: true } } });
    fixture.detectChanges();

    expect((element.querySelector('input[type="checkbox"]') as HTMLInputElement).checked).toBe(false);
  });

  it('renders the in-app messages by type, never by raw server text', () => {
    settle([makeNotification({ data: { message: '<script>alert(1)</script>' } })]);

    expect(element.textContent).toContain('Quota running out');
    expect(element.innerHTML).not.toContain('alert(1)');
  });

  it('marks one message read', () => {
    settle([makeNotification({ id: 'abc' })]);

    const markRead = Array.from(element.querySelectorAll('button')).find((button) =>
      (button.textContent ?? '').includes('Mark as read')
    ) as HTMLButtonElement;
    markRead.click();

    http.expectOne(`${NOTIFICATIONS}/abc/read`).flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(element.textContent).toContain('Read');
    expect(
      Array.from(element.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Mark as read'))
    ).toBe(false);
  });

  it('shows an honest empty state when nothing has been sent', () => {
    settle([]);
    expect(element.querySelector('[data-testid="empty-state"]')).toBeTruthy();
  });
});
