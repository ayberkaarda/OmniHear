import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';

import { HealthService } from '../../core/health/health.service';
import { HealthComponent } from './health.component';

describe('HealthComponent', () => {
  it('renders and shows the healthy status once the API call resolves', async () => {
    const healthServiceMock: Partial<HealthService> = {
      getHealth: jest.fn().mockReturnValue(of({ status: 'ok' }))
    };

    await TestBed.configureTestingModule({
      imports: [HealthComponent],
      providers: [{ provide: HealthService, useValue: healthServiceMock }]
    }).compileComponents();

    const fixture = TestBed.createComponent(HealthComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(fixture.componentInstance.state()).toBe('success');
    expect(fixture.componentInstance.status()).toBe('ok');

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('[data-testid="health-success"]')).toBeTruthy();
    expect(healthServiceMock.getHealth).toHaveBeenCalledTimes(1);
  });

  it('shows an error state when the API call fails', async () => {
    const healthServiceMock: Partial<HealthService> = {
      getHealth: jest.fn().mockReturnValue(
        new Observable((subscriber) => subscriber.error(new Error('network error')))
      )
    };

    await TestBed.configureTestingModule({
      imports: [HealthComponent],
      providers: [{ provide: HealthService, useValue: healthServiceMock }]
    }).compileComponents();

    const fixture = TestBed.createComponent(HealthComponent);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(fixture.componentInstance.state()).toBe('error');

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('[data-testid="health-error"]')).toBeTruthy();
  });
});
