import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { makeCompany, makeUser } from '../../../core/auth/auth.fixtures';
import { AuthStore } from '../../../core/auth/auth.store';
import { VerifyEmailComponent } from './verify-email.component';

const VERIFY_URL = `${environment.apiBaseUrl}/v1/auth/email/verify`;

async function createFixture(
  params: Partial<Record<'id' | 'hash' | 'expires' | 'signature', string>>
): Promise<ComponentFixture<VerifyEmailComponent>> {
  TestBed.resetTestingModule();
  await TestBed.configureTestingModule({
    imports: [VerifyEmailComponent],
    providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
  }).compileComponents();

  const fixture = TestBed.createComponent(VerifyEmailComponent);
  for (const [key, value] of Object.entries(params)) {
    fixture.componentRef.setInput(key, value);
  }
  fixture.detectChanges();
  return fixture;
}

describe('VerifyEmailComponent', () => {
  beforeEach(() => localStorage.clear());

  it('shows the "check your inbox" state when the link parameters are absent', async () => {
    const fixture = await createFixture({});
    const http = TestBed.inject(HttpTestingController);

    http.expectNone(VERIFY_URL);
    expect((fixture.nativeElement as HTMLElement).querySelector('[data-testid="verify-awaiting"]')).toBeTruthy();
    http.verify();
  });

  it('forwards the four signed values from the emailed link and confirms', async () => {
    const fixture = await createFixture({ id: '1', hash: 'h4sh', expires: '1234567890', signature: 's1g' });
    const http = TestBed.inject(HttpTestingController);

    const request = http.expectOne(VERIFY_URL);
    expect(request.request.body).toEqual({ id: 1, hash: 'h4sh', expires: 1234567890, signature: 's1g' });
    request.flush({ user: makeUser() });

    fixture.detectChanges();
    await fixture.whenStable();

    expect((fixture.nativeElement as HTMLElement).querySelector('[data-testid="verify-success"]')).toBeTruthy();
    http.verify();
  });

  it('reports an expired link instead of a raw server message', async () => {
    const fixture = await createFixture({ id: '1', hash: 'h4sh', expires: '1', signature: 'stale' });
    const http = TestBed.inject(HttpTestingController);

    http
      .expectOne(VERIFY_URL)
      .flush({ code: 'VALIDATION_ERROR', message: 'Invalid signature.' }, { status: 422, statusText: 'Unprocessable' });

    fixture.detectChanges();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('[data-testid="verify-failed"]')).toBeTruthy();
    expect(element.textContent).not.toContain('Invalid signature.');
    http.verify();
  });

  it('offers a resend only to a signed-in user', async () => {
    const fixture = await createFixture({});
    const http = TestBed.inject(HttpTestingController);
    expect((fixture.nativeElement as HTMLElement).querySelector('button')).toBeNull();

    TestBed.inject(AuthStore).setSession('1|abc', makeUser({ email_verified_at: null }), makeCompany());
    fixture.detectChanges();
    await fixture.whenStable();

    const button = (fixture.nativeElement as HTMLElement).querySelector('button') as HTMLButtonElement;
    expect(button).toBeTruthy();

    button.click();
    http.expectOne(`${environment.apiBaseUrl}/v1/auth/email/resend`).flush({ message: 'ok' }, { status: 202, statusText: 'Accepted' });
    http.verify();
  });

  it('ignores a partially filled link rather than sending a broken request', async () => {
    const fixture = await createFixture({ id: '1', hash: 'h4sh' });
    const http = TestBed.inject(HttpTestingController);

    http.expectNone(VERIFY_URL);
    expect((fixture.nativeElement as HTMLElement).querySelector('[data-testid="verify-awaiting"]')).toBeTruthy();
    http.verify();
  });
});
