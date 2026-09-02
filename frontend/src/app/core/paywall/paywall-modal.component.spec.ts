import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';

import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { PaywallModalComponent } from './paywall-modal.component';
import { PaywallService } from './paywall.service';

describe('PaywallModalComponent', () => {
  let fixture: ComponentFixture<PaywallModalComponent>;
  let paywall: PaywallService;
  let router: Router;

  beforeEach(async () => {
    localStorage.clear();
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [PaywallModalComponent],
      providers: [provideRouter([])]
    }).compileComponents();

    fixture = TestBed.createComponent(PaywallModalComponent);
    paywall = TestBed.inject(PaywallService);
    router = TestBed.inject(Router);
    TestBed.inject(AuthStore).setSession('1|abc', makeUser(), makeCompany({ quota_limit: 200 }));
    fixture.detectChanges();
  });

  it('renders nothing until the quota wall is triggered', () => {
    expect((fixture.nativeElement as HTMLElement).querySelector('[role="alertdialog"]')).toBeNull();
  });

  it('opens as an alertdialog that cannot be dismissed by Escape or the backdrop', async () => {
    paywall.open();
    fixture.detectChanges();
    await fixture.whenStable();

    const dialog = (fixture.nativeElement as HTMLElement).querySelector('[role="alertdialog"]') as HTMLElement;
    expect(dialog).toBeTruthy();
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(dialog.getAttribute('aria-labelledby')).toBeTruthy();

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    await fixture.whenStable();

    expect(paywall.isOpen()).toBe(true);
  });

  it('shows the plan limit taken from the session', async () => {
    paywall.open();
    fixture.detectChanges();
    await fixture.whenStable();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('200 / 200');
  });

  it('the upgrade action closes the wall and routes to billing', async () => {
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);
    paywall.open();
    fixture.detectChanges();
    await fixture.whenStable();

    const buttons = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('button'));
    const upgrade = buttons[buttons.length - 1];
    upgrade.click();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith(['/app/settings/billing']);
    expect(paywall.isOpen()).toBe(false);
  });

  it('returns focus to the element that had it when the wall closes', async () => {
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();
    expect(document.activeElement).toBe(trigger);

    paywall.open();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(document.activeElement).not.toBe(trigger);

    paywall.close();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(document.activeElement).toBe(trigger);
    trigger.remove();
  });
});
