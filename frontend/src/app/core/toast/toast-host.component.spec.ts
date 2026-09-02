import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ToastHostComponent } from './toast-host.component';
import { ToastService } from './toast.service';

describe('ToastHostComponent', () => {
  let fixture: ComponentFixture<ToastHostComponent>;
  let toasts: ToastService;

  beforeEach(async () => {
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({ imports: [ToastHostComponent] }).compileComponents();
    fixture = TestBed.createComponent(ToastHostComponent);
    toasts = TestBed.inject(ToastService);
    fixture.detectChanges();
  });

  it('mounts both live regions before anything is announced', () => {
    const element = fixture.nativeElement as HTMLElement;

    // The regions must pre-exist: a live region inserted together with its
    // content is frequently not announced by screen readers.
    expect(element.querySelector('[aria-live="assertive"][role="alert"]')).toBeTruthy();
    expect(element.querySelector('[aria-live="polite"][role="status"]')).toBeTruthy();
  });

  it('routes an error into the assertive region and a success into the polite one', async () => {
    toasts.error('something failed');
    toasts.success('saved');
    fixture.detectChanges();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    const assertive = element.querySelector('[aria-live="assertive"]') as HTMLElement;
    const polite = element.querySelector('[aria-live="polite"]') as HTMLElement;

    expect(assertive.textContent).toContain('something failed');
    expect(assertive.textContent).not.toContain('saved');
    expect(polite.textContent).toContain('saved');
  });

  it('every toast carries a labelled dismiss button that removes it', async () => {
    toasts.error('dismiss me', 0);
    fixture.detectChanges();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    const button = element.querySelector('button[aria-label]') as HTMLButtonElement;
    expect(button).toBeTruthy();

    button.click();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(toasts.toasts()).toHaveLength(0);
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('dismiss me');
  });
});
