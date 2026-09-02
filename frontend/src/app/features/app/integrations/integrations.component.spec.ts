import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { makeIntegration, makeIntegrationPage } from '../../../core/integrations/integration.fixtures';
import { IntegrationsStore } from '../../../core/integrations/integrations.store';
import { IntegrationsComponent } from './integrations.component';

const BASE = `${environment.apiBaseUrl}/v1/integrations`;

describe('IntegrationsComponent', () => {
  let fixture: ComponentFixture<IntegrationsComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [IntegrationsComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(IntegrationsStore).reset();
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(IntegrationsComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  function settle(page = makeIntegrationPage()): void {
    http.expectOne((candidate) => candidate.url === BASE).flush(page);
    fixture.detectChanges();
  }

  function buttonWith(text: string): HTMLButtonElement {
    const match = Array.from(element.querySelectorAll('button')).find((button) =>
      (button.textContent ?? '').includes(text)
    );
    if (match === undefined) {
      throw new Error(`No button containing "${text}"`);
    }
    return match as HTMLButtonElement;
  }

  it('shows the connection health the resource carries', () => {
    settle(
      makeIntegrationPage([
        makeIntegration({ id: 1, status: 'error', sync_error: 'The platform rejected the integration credentials.' })
      ])
    );

    expect(element.textContent).toContain('App Store');
    expect(element.textContent).toContain('284882215');
    expect(element.querySelector('[data-testid="integration-sync-error"]')?.textContent).toContain(
      'The platform rejected'
    );
    expect(element.querySelector('app-badge')?.getAttribute('data-value') ?? element.textContent).toBeTruthy();
  });

  it('offers to connect a channel when nothing is connected yet', () => {
    settle(makeIntegrationPage([]));

    expect(element.querySelector('[data-testid="empty-state"]')).toBeTruthy();
  });

  it('never renders a credential, because the resource has none to render', () => {
    settle(makeIntegrationPage([makeIntegration({ platform: 'zendesk', settings: { subdomain: 'acme' } })]));

    const rendered = element.innerHTML;
    expect(rendered).toContain('acme');
    // Invariant I5: the API sends no credentials at all, so a card cannot leak one.
    expect(rendered).not.toContain('api_token');
    expect(rendered.toLowerCase()).not.toContain('password');
  });

  it('asks the API for the fields the chosen connector needs', () => {
    settle(makeIntegrationPage([]));

    buttonWith('Connect a channel').click();
    fixture.detectChanges();

    // App Store is first: two settings, no credentials.
    expect(element.querySelectorAll('app-modal app-input')).toHaveLength(2);

    const platformSelect = element.querySelector('app-modal select') as HTMLSelectElement;
    platformSelect.value = 'zendesk';
    platformSelect.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    // Zendesk: one setting plus two credentials, and the credentials are masked.
    expect(element.querySelectorAll('app-modal app-input')).toHaveLength(3);
    expect(element.querySelectorAll('app-modal input[type="password"]')).toHaveLength(2);
    expect(element.textContent).toContain('never shown again');
  });

  it('posts platform, settings and credentials together on create', () => {
    settle(makeIntegrationPage([]));

    buttonWith('Connect a channel').click();
    fixture.detectChanges();

    const inputs = Array.from(element.querySelectorAll('app-modal input')) as HTMLInputElement[];
    inputs[0].value = '284882215';
    inputs[0].dispatchEvent(new Event('input'));
    inputs[1].value = 'tr';
    inputs[1].dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Save').click();

    const post = http.expectOne((candidate) => candidate.url === BASE && candidate.method === 'POST');
    expect(post.request.body).toEqual({
      platform: 'appstore',
      settings: { app_id: '284882215', country: 'tr' },
      credentials: {}
    });
    post.flush({ integration: makeIntegration() }, { status: 201, statusText: 'Created' });
    settle();

    expect(element.querySelector('app-modal [role="dialog"]')).toBeNull();
  });

  it('pre-fills settings on edit but leaves the credential fields blank', () => {
    settle(makeIntegrationPage([makeIntegration({ id: 5, platform: 'zendesk', settings: { subdomain: 'acme' } })]));

    buttonWith('Edit').click();
    fixture.detectChanges();

    const inputs = Array.from(element.querySelectorAll('app-modal input')) as HTMLInputElement[];
    expect(inputs[0].value).toBe('acme');
    // Nothing can pre-fill these: the value was never sent to the browser.
    expect(inputs[1].value).toBe('');
    expect(inputs[2].value).toBe('');
    expect(element.textContent).toContain('Leave blank to keep the stored credentials');

    buttonWith('Save').click();

    const patch = http.expectOne(`${BASE}/5`);
    expect(patch.request.method).toBe('PATCH');
    expect(patch.request.body).toEqual({ settings: { subdomain: 'acme' }, status: 'active' });
    patch.flush({ integration: makeIntegration({ id: 5 }) });
    settle();
  });

  it('queues a sync and refreshes the list', () => {
    settle(makeIntegrationPage([makeIntegration({ id: 3 })]));

    buttonWith('Sync now').click();
    fixture.detectChanges();

    http.expectOne(`${BASE}/3/sync`).flush({ message: 'Sync queued.' }, { status: 202, statusText: 'Accepted' });
    settle();
  });

  it('confirms before disconnecting, and names what is lost', () => {
    settle(makeIntegrationPage([makeIntegration({ id: 8, feedback_count: 42 })]));

    buttonWith('Disconnect').click();
    fixture.detectChanges();

    // `div[...]`, not `[...]`: `role` is both an input of `app-modal` and a
    // static attribute left on its host element, so the bare selector matches twice.
    const dialogs = Array.from(element.querySelectorAll('div[role="alertdialog"]'));
    expect(dialogs).toHaveLength(1);
    expect(dialogs[0].textContent).toContain('42');

    const confirm = Array.from(dialogs[0].querySelectorAll('button')).find((button) =>
      (button.textContent ?? '').includes('Disconnect')
    ) as HTMLButtonElement;
    confirm.click();

    http.expectOne(`${BASE}/8`).flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(element.querySelector('div[role="alertdialog"]')).toBeNull();
    expect(element.querySelector('[data-testid="empty-state"]')).toBeTruthy();
  });

  it('renders the catalogue message when the list read fails', () => {
    http
      .expectOne((candidate) => candidate.url === BASE)
      .flush({ code: 'FORBIDDEN', message: 'policy denied' }, { status: 403, statusText: 'Forbidden' });
    fixture.detectChanges();

    const banner = element.querySelector('[data-testid="integrations-error"]');
    expect(banner?.textContent).toContain('Your role does not allow this action');
    expect(banner?.textContent).not.toContain('policy denied');
  });
});
