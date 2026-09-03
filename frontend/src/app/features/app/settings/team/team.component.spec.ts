import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../../environments/environment';
import { makeCompany, makeUser } from '../../../../core/auth/auth.fixtures';
import { User } from '../../../../core/auth/auth.models';
import { AuthStore } from '../../../../core/auth/auth.store';
import { makeTeamPage } from '../../../../core/settings/settings.fixtures';
import { TeamStore } from '../../../../core/settings/team.store';
import { TeamComponent } from './team.component';

const TEAM = `${environment.apiBaseUrl}/v1/settings/team`;

const OWNER = makeUser({ id: 1, name: 'Ada', role: 'owner' });
const SECOND_OWNER = makeUser({ id: 2, name: 'Alan', role: 'owner', email: 'alan@acme.com' });
const ADMIN = makeUser({ id: 3, name: 'Grace', role: 'admin', email: 'grace@acme.com' });
const MEMBER = makeUser({ id: 4, name: 'Mary', role: 'member', email: 'mary@acme.com' });

describe('TeamComponent', () => {
  let fixture: ComponentFixture<TeamComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;

  function mount(signedInAs: User, members: readonly User[]): void {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [TeamComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(TeamStore).reset();
    TestBed.inject(AuthStore).setSession('1|abc', signedInAs, makeCompany());
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(TeamComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    http.expectOne((candidate) => candidate.url === TEAM).flush(makeTeamPage(members));
    fixture.detectChanges();
  }

  function rowFor(name: string): HTMLElement {
    const row = Array.from(element.querySelectorAll('tbody tr')).find((tr) =>
      (tr.textContent ?? '').includes(name)
    );
    if (row === undefined) {
      throw new Error(`No row for "${name}"`);
    }
    return row as HTMLElement;
  }

  afterEach(() => http.verify());

  it('lists the company with its roles', () => {
    mount(OWNER, [OWNER, ADMIN, MEMBER]);

    expect(element.querySelectorAll('tbody tr')).toHaveLength(3);
    expect(element.textContent).toContain('grace@acme.com');
  });

  /**
   * The rule, from the row's side: a control that is going to be refused is not
   * offered, and the row says why instead of leaving a dead button.
   */
  it('offers no role control and no remove on your own row', () => {
    mount(OWNER, [OWNER, SECOND_OWNER]);

    const own = rowFor('Ada');
    expect(own.querySelector('select')).toBeNull();
    expect(own.textContent).toContain('You cannot change your own role');
    expect(Array.from(own.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Remove'))).toBe(false);
  });

  it('locks the last owner and says why', () => {
    mount(SECOND_OWNER, [OWNER, SECOND_OWNER]);
    // Two owners: Ada is editable.
    expect(rowFor('Ada').querySelector('select')).toBeTruthy();

    mount(ADMIN, [OWNER, ADMIN, MEMBER]);
    const lastOwner = rowFor('Ada');
    expect(lastOwner.textContent).toContain('A company must keep at least one owner');
    expect(Array.from(lastOwner.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Remove'))).toBe(
      false
    );
  });

  it('gives an admin no role selects at all', () => {
    mount(ADMIN, [OWNER, ADMIN, MEMBER]);

    expect(element.querySelectorAll('tbody select')).toHaveLength(0);
    // But an admin may still remove a member.
    expect(
      Array.from(rowFor('Mary').querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Remove'))
    ).toBe(true);
  });

  it('hides the invite control from a member and limits an admin to their own rank', () => {
    mount(MEMBER, [OWNER, ADMIN, MEMBER]);
    expect(
      Array.from(element.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes('Invite a member'))
    ).toBe(false);

    mount(ADMIN, [OWNER, ADMIN, MEMBER]);
    const invite = Array.from(element.querySelectorAll('button')).find((b) =>
      (b.textContent ?? '').includes('Invite a member')
    ) as HTMLButtonElement;
    invite.click();
    fixture.detectChanges();

    const options = Array.from(element.querySelectorAll('app-modal option')).map((option) => option.textContent?.trim());
    expect(options).toEqual(['Administrator', 'Member']);
  });

  it('sends the invitation and adds no member row for it', () => {
    mount(OWNER, [OWNER]);

    (
      Array.from(element.querySelectorAll('button')).find((b) =>
        (b.textContent ?? '').includes('Invite a member')
      ) as HTMLButtonElement
    ).click();
    fixture.detectChanges();

    const emailInput = element.querySelector('app-modal input') as HTMLInputElement;
    emailInput.value = 'new@acme.com';
    emailInput.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    (
      Array.from(element.querySelectorAll('button')).find((b) =>
        (b.textContent ?? '').includes('Send invitation')
      ) as HTMLButtonElement
    ).click();

    const request = http.expectOne(`${TEAM}/invitations`);
    expect(request.request.body).toEqual({ email: 'new@acme.com', role: 'member' });
    request.flush({}, { status: 201, statusText: 'Created' });
    fixture.detectChanges();

    expect(element.querySelectorAll('tbody tr')).toHaveLength(1);
  });

  it('changes a role from the row select', () => {
    mount(OWNER, [OWNER, MEMBER]);

    const select = rowFor('Mary').querySelector('select') as HTMLSelectElement;
    select.value = 'admin';
    select.dispatchEvent(new Event('change'));

    const request = http.expectOne(`${TEAM}/4`);
    expect(request.request.body).toEqual({ role: 'admin' });
    request.flush({ user: { ...MEMBER, role: 'admin' } });
    fixture.detectChanges();

    expect(rowFor('Mary').querySelector('select')?.value).toBe('admin');
  });
});
