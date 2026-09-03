import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../../environments/environment';
import { makeCompany, makeUser } from '../../../../core/auth/auth.fixtures';
import { AuthStore } from '../../../../core/auth/auth.store';
import { ApiKeysStore } from '../../../../core/settings/api-keys.store';
import { makeApiKey, makeApiKeyCreated, makeDeviceSession } from '../../../../core/settings/settings.fixtures';
import { ApiKeysComponent } from './api-keys.component';

const KEYS = `${environment.apiBaseUrl}/v1/settings/api-keys`;
const TOKENS = `${environment.apiBaseUrl}/v1/auth/tokens`;

describe('ApiKeysComponent', () => {
  let fixture: ComponentFixture<ApiKeysComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [ApiKeysComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(ApiKeysStore).reset();
    TestBed.inject(AuthStore).setSession('1|abc', makeUser({ role: 'owner' }), makeCompany());
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(ApiKeysComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  function settle(keys = [makeApiKey()], sessions = [makeDeviceSession()]): void {
    http.expectOne(KEYS).flush({ data: keys });
    http.expectOne(TOKENS).flush({ data: sessions });
    fixture.detectChanges();
  }

  function buttonWith(text: string, root: ParentNode = element): HTMLButtonElement {
    const match = Array.from(root.querySelectorAll('button')).find((button) =>
      (button.textContent ?? '').includes(text)
    );
    if (match === undefined) {
      throw new Error(`No button containing "${text}"`);
    }
    return match as HTMLButtonElement;
  }

  it('keeps the two lists apart on screen', () => {
    settle([makeApiKey({ name: 'billing export' })], [makeDeviceSession({ name: 'Ada laptop' })]);

    expect(element.textContent).toContain('billing export');
    expect(element.textContent).toContain('Ada laptop');
    // The device list says what it is, so nobody reads it as a key list.
    expect(element.textContent).toContain('These are browser sessions, not API keys');
  });

  it('warns that the key is shown once before it is created', () => {
    settle();

    buttonWith('Create an API key').click();
    fixture.detectChanges();

    expect(element.textContent).toContain('shown to you once');
  });

  /**
   * The one-shot value, and the sentence that has to be read before the dialog
   * closes. The wording is asserted because it is the whole safety property of
   * this screen.
   */
  it('shows the plaintext key once, says so, and cannot be dismissed by accident', () => {
    settle();

    buttonWith('Create an API key').click();
    fixture.detectChanges();

    const nameInput = element.querySelector('app-modal input') as HTMLInputElement;
    nameInput.value = 'staging';
    nameInput.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    buttonWith('Create key').click();
    http
      .expectOne((candidate) => candidate.url === KEYS && candidate.method === 'POST')
      .flush(makeApiKeyCreated(), { status: 201, statusText: 'Created' });
    fixture.detectChanges();

    const reveal = element.querySelector('div[role="alertdialog"]') as HTMLElement;
    expect(reveal).toBeTruthy();
    expect(reveal.textContent).toContain('This is the only time this key is shown');
    expect(element.querySelector('[data-testid="api-key-plaintext"]')?.textContent).toBe(
      '7|abcdefghijklmnopqrstuvwxyz'
    );

    // Not dismissible: Esc would destroy a value that cannot be recovered.
    reveal.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    expect(element.querySelector('[data-testid="api-key-plaintext"]')).toBeTruthy();

    buttonWith('I have copied it', reveal).click();
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="api-key-plaintext"]')).toBeNull();
    // Gone from the DOM entirely, not merely hidden.
    expect(element.innerHTML).not.toContain('7|abcdefghijklmnopqrstuvwxyz');
  });

  it('offers no key controls to a member', () => {
    TestBed.inject(AuthStore).setSession('1|abc', makeUser({ role: 'member' }), makeCompany());
    fixture.detectChanges();
    settle();

    expect(Array.from(element.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Create an API key'))).toBe(
      false
    );
    expect(Array.from(element.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Revoke'))).toBe(
      false
    );
    // Signing a device out is not a key action and stays available to everyone.
    expect(Array.from(element.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Sign out'))).toBe(
      true
    );
  });

  it('revokes a key through the key endpoint and a device through the token endpoint', () => {
    settle();

    buttonWith('Revoke').click();
    fixture.detectChanges();
    const keyDialog = element.querySelector('div[role="alertdialog"]') as HTMLElement;
    buttonWith('Revoke', keyDialog).click();
    http.expectOne(`${KEYS}/1`).flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    buttonWith('Sign out').click();
    fixture.detectChanges();
    const sessionDialog = element.querySelector('div[role="alertdialog"]') as HTMLElement;
    buttonWith('Sign out', sessionDialog).click();
    http.expectOne(`${TOKENS}/11`).flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="empty-state"]')).toBeTruthy();
  });
});
