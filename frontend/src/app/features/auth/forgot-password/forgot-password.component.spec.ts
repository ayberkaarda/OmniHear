import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { ForgotPasswordComponent } from './forgot-password.component';

interface ForgotInternals {
  form: { controls: { email: { setValue(v: string): void } } };
  onSubmit(): void;
}

describe('ForgotPasswordComponent', () => {
  let fixture: ComponentFixture<ForgotPasswordComponent>;
  let component: ForgotInternals;
  let http: HttpTestingController;

  beforeEach(async () => {
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [ForgotPasswordComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    }).compileComponents();

    fixture = TestBed.createComponent(ForgotPasswordComponent);
    component = fixture.componentInstance as unknown as ForgotInternals;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('shows the same neutral confirmation the API guarantees, without leaking existence', async () => {
    component.form.controls.email.setValue('ada@acme.com');
    component.onSubmit();

    const request = http.expectOne(`${environment.apiBaseUrl}/v1/auth/forgot-password`);
    expect(request.request.body).toEqual({ email: 'ada@acme.com' });
    request.flush({ message: 'ok' }, { status: 202, statusText: 'Accepted' });

    fixture.detectChanges();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    const confirmation = element.querySelector('[data-testid="forgot-password-sent"]');
    expect(confirmation).toBeTruthy();
    expect(confirmation?.getAttribute('role')).toBe('status');
    // The form is replaced, so the address cannot be probed repeatedly from this state.
    expect(element.querySelector('form')).toBeNull();
  });

  it('never calls the API with an invalid address', () => {
    component.form.controls.email.setValue('not-an-email');
    component.onSubmit();

    http.expectNone(`${environment.apiBaseUrl}/v1/auth/forgot-password`);
  });
});
