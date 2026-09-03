import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { makeCompany, makeUser } from '../../../core/auth/auth.fixtures';
import { AuthStore } from '../../../core/auth/auth.store';
import { AcceptInvitationComponent } from './accept-invitation.component';

const TOKEN = 'tok3n';
const SHOW_URL = `${environment.apiBaseUrl}/v1/invitations/${TOKEN}`;
const ACCEPT_URL = `${SHOW_URL}/accept`;

const PENDING = {
  invitation: {
    email: 'invitee@acme.test',
    company_name: 'Acme Industries',
    role: 'admin' as const,
    expires_at: '2026-09-10T00:00:00+00:00'
  }
};

async function createFixture(token?: string): Promise<ComponentFixture<AcceptInvitationComponent>> {
  TestBed.resetTestingModule();
  await TestBed.configureTestingModule({
    imports: [AcceptInvitationComponent],
    providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
  }).compileComponents();

  const fixture = TestBed.createComponent(AcceptInvitationComponent);
  if (token !== undefined) {
    fixture.componentRef.setInput('token', token);
  }
  fixture.detectChanges();
  return fixture;
}

function fill(fixture: ComponentFixture<AcceptInvitationComponent>, values: Record<string, string>): void {
  const element = fixture.nativeElement as HTMLElement;
  for (const [name, value] of Object.entries(values)) {
    const input = element.querySelector<HTMLInputElement>(`app-input[formcontrolname="${name}"] input`);
    if (input === null) {
      throw new Error(`No input for "${name}"`);
    }
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }
  fixture.detectChanges();
}

describe('AcceptInvitationComponent', () => {
  beforeEach(() => localStorage.clear());

  it('asks the server nothing when the link carries no token', async () => {
    const fixture = await createFixture();
    const http = TestBed.inject(HttpTestingController);

    http.expectNone(SHOW_URL);
    expect((fixture.nativeElement as HTMLElement).querySelector('[data-testid="invitation-invalid"]')).toBeTruthy();
    http.verify();
  });

  it('shows who invited whom, and at what role, before asking for a password', async () => {
    const fixture = await createFixture(TOKEN);
    const http = TestBed.inject(HttpTestingController);

    const request = http.expectOne(SHOW_URL);
    expect(request.request.method).toBe('GET');
    request.flush(PENDING);

    fixture.detectChanges();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    const summary = element.querySelector('[data-testid="invitation-summary"]');
    expect(summary?.textContent).toContain('Acme Industries');
    expect(summary?.textContent).toContain('invitee@acme.test');
    // The role is rendered as its label, not as the wire value.
    expect(summary?.textContent).toContain('Administrator');
    expect(element.querySelector('app-input[formcontrolname="password"] input')).toBeTruthy();
    // And the address is not offered as an editable field: it is fixed by the row.
    expect(element.querySelector('app-input[formcontrolname="email"]')).toBeNull();
    http.verify();
  });

  it('gives one refusal for an expired, a spent and an unknown token alike', async () => {
    const fixture = await createFixture(TOKEN);
    const http = TestBed.inject(HttpTestingController);

    http.expectOne(SHOW_URL).flush({ code: 'NOT_FOUND', message: 'Not found.' }, { status: 404, statusText: 'Not Found' });

    fixture.detectChanges();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('[data-testid="invitation-invalid"]')).toBeTruthy();
    // Nothing on the screen guesses which of the three it was — the server
    // refuses to distinguish them, and the SPA must not undo that.
    expect(element.textContent).not.toContain('expired token');
    expect(element.querySelector('form')).toBeNull();
    http.verify();
  });

  it('accepts, lands in the authenticated state and does not send an email field', async () => {
    const fixture = await createFixture(TOKEN);
    const http = TestBed.inject(HttpTestingController);
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    http.expectOne(SHOW_URL).flush(PENDING);
    fixture.detectChanges();
    await fixture.whenStable();

    fill(fixture, {
      name: 'Grace Hopper',
      password: 'correct-horse-battery',
      password_confirmation: 'correct-horse-battery'
    });

    (fixture.nativeElement as HTMLElement).querySelector('form')?.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const request = http.expectOne(ACCEPT_URL);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'Grace Hopper',
      password: 'correct-horse-battery',
      password_confirmation: 'correct-horse-battery'
    });

    const user = makeUser({ email: 'invitee@acme.test', role: 'admin' });
    request.flush({ token: 'sanctum-token', user, company: makeCompany() });

    fixture.detectChanges();
    await fixture.whenStable();

    // The same authenticated state register() produces, from the other door.
    expect(TestBed.inject(AuthStore).isAuthenticated()).toBe(true);
    // Straight to the product: the account is created already verified, so
    // there is no /auth/verify-email detour to make.
    expect(navigate).toHaveBeenCalledWith(['/app/overview']);
    http.verify();
  });

  it('will not submit a mismatched password pair', async () => {
    const fixture = await createFixture(TOKEN);
    const http = TestBed.inject(HttpTestingController);

    http.expectOne(SHOW_URL).flush(PENDING);
    fixture.detectChanges();
    await fixture.whenStable();

    fill(fixture, {
      name: 'Grace Hopper',
      password: 'correct-horse-battery',
      password_confirmation: 'something-else-entirely'
    });

    (fixture.nativeElement as HTMLElement).querySelector('form')?.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    http.expectNone(ACCEPT_URL);
    http.verify();
  });

  it('renders the server field error when the address already has an account', async () => {
    const fixture = await createFixture(TOKEN);
    const http = TestBed.inject(HttpTestingController);

    http.expectOne(SHOW_URL).flush(PENDING);
    fixture.detectChanges();
    await fixture.whenStable();

    fill(fixture, {
      name: 'Grace Hopper',
      password: 'correct-horse-battery',
      password_confirmation: 'correct-horse-battery'
    });

    (fixture.nativeElement as HTMLElement).querySelector('form')?.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    http.expectOne(ACCEPT_URL).flush(
      {
        code: 'VALIDATION_ERROR',
        message: 'The given data was invalid.',
        errors: { email: ['An account already exists for this email address. Sign in instead.'] }
      },
      { status: 422, statusText: 'Unprocessable' }
    );

    fixture.detectChanges();
    await fixture.whenStable();

    // The session is untouched and the form is still there to try again from.
    expect(TestBed.inject(AuthStore).isAuthenticated()).toBe(false);
    expect((fixture.nativeElement as HTMLElement).querySelector('form')).toBeTruthy();
    http.verify();
  });
});
