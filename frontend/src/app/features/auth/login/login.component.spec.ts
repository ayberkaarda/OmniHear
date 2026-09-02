import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { makeCompany, makeUser } from '../../../core/auth/auth.fixtures';
import { AuthStore } from '../../../core/auth/auth.store';
import { errorInterceptor } from '../../../core/http/error.interceptor';
import { LoginComponent } from './login.component';

interface LoginInternals {
  form: { controls: { email: { setValue(v: string): void }; password: { setValue(v: string): void } } };
  onSubmit(): void;
  submitting(): boolean;
  errorFor(field: string): string | undefined;
}

describe('LoginComponent', () => {
  let fixture: ComponentFixture<LoginComponent>;
  let component: LoginInternals;
  let http: HttpTestingController;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([errorInterceptor])),
        provideHttpClientTesting()
      ]
    }).compileComponents();

    fixture = TestBed.createComponent(LoginComponent);
    component = fixture.componentInstance as unknown as LoginInternals;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('renders an accessible form: labelled fields and a submit button', () => {
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('h1')?.textContent).toBeTruthy();
    const inputs = Array.from(element.querySelectorAll('input'));
    expect(inputs).toHaveLength(2);
    for (const input of inputs) {
      const label = element.querySelector(`label[for="${input.id}"]`);
      expect(label).toBeTruthy();
    }
    expect(element.querySelector('button[type="submit"]')).toBeTruthy();
  });

  it('does not call the API while the form is invalid, and surfaces field errors', async () => {
    component.onSubmit();
    fixture.detectChanges();
    await fixture.whenStable();

    http.expectNone(`${environment.apiBaseUrl}/v1/auth/login`);
    expect(component.errorFor('email')).toBeTruthy();
    expect(component.errorFor('password')).toBeTruthy();
  });

  it('signs in and follows the redirect query parameter is absent -> dashboard', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    component.form.controls.email.setValue('ada@acme.com');
    component.form.controls.password.setValue('correct-horse-battery');
    component.onSubmit();

    const request = http.expectOne(`${environment.apiBaseUrl}/v1/auth/login`);
    expect(request.request.body).toEqual({
      email: 'ada@acme.com',
      password: 'correct-horse-battery',
      device_name: 'web'
    });
    request.flush({ token: '1|abc', user: makeUser(), company: makeCompany() });

    fixture.detectChanges();
    await fixture.whenStable();

    expect(TestBed.inject(AuthStore).isAuthenticated()).toBe(true);
    expect(navigate).toHaveBeenCalledWith('/app/overview');
    expect(component.submitting()).toBe(false);
  });

  it('shows the server field error returned by a 422 and stops the spinner', async () => {
    component.form.controls.email.setValue('ada@acme.com');
    component.form.controls.password.setValue('correct-horse-battery');
    component.onSubmit();

    http.expectOne(`${environment.apiBaseUrl}/v1/auth/login`).flush(
      { code: 'VALIDATION_ERROR', message: 'Invalid.', errors: { email: ['This account is locked.'] } },
      { status: 422, statusText: 'Unprocessable Content' }
    );

    fixture.detectChanges();
    await fixture.whenStable();

    expect(component.submitting()).toBe(false);
    expect(component.errorFor('email')).toBe('This account is locked.');
  });
});
