import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeCompany, makeUser } from '../auth/auth.fixtures';
import { AuthStore } from '../auth/auth.store';
import { makeTeamPage } from './settings.fixtures';
import { TeamStore } from './team.store';

const TEAM = `${environment.apiBaseUrl}/v1/settings/team`;

const OWNER = makeUser({ id: 1, name: 'Ada', role: 'owner' });
const SECOND_OWNER = makeUser({ id: 2, name: 'Alan', role: 'owner', email: 'alan@acme.com' });
const ADMIN = makeUser({ id: 3, name: 'Grace', role: 'admin', email: 'grace@acme.com' });
const MEMBER = makeUser({ id: 4, name: 'Mary', role: 'member', email: 'mary@acme.com' });

describe('TeamStore', () => {
  let store: TeamStore;
  let auth: AuthStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(TeamStore);
    auth = TestBed.inject(AuthStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
  });

  afterEach(() => http.verify());

  function signInAs(user = OWNER, members = [OWNER, ADMIN, MEMBER]): void {
    auth.setSession('1|abc', user, makeCompany());
    store.load();
    http.expectOne((candidate) => candidate.url === TEAM).flush(makeTeamPage(members));
  }

  it('reads the whole company at the contract maximum page size', () => {
    auth.setSession('1|abc', OWNER, makeCompany());
    store.load();
    const request = http.expectOne((candidate) => candidate.url === TEAM);
    expect(request.request.params.get('per_page')).toBe('100');
    request.flush(makeTeamPage([OWNER]));

    expect(store.members()).toHaveLength(1);
  });

  /** Spec section 8: a company with no owner can never be billed or managed again. */
  it('refuses to demote or remove the last owner', () => {
    signInAs(OWNER, [OWNER, ADMIN]);

    expect(store.ownerCount()).toBe(1);
    expect(store.canDemote(OWNER)).toBe(false);
    expect(store.canRemove(OWNER)).toBe(false);

    store.changeRole(OWNER, 'member');
    store.remove(OWNER);
    http.expectNone(() => true);
  });

  it('allows demoting an owner once there is a second one', () => {
    signInAs(OWNER, [OWNER, SECOND_OWNER]);

    expect(store.canDemote(SECOND_OWNER)).toBe(true);

    store.changeRole(SECOND_OWNER, 'admin');
    const request = http.expectOne(`${TEAM}/2`);
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ role: 'admin' });
    request.flush({ user: { ...SECOND_OWNER, role: 'admin' } });

    expect(store.members().find((member) => member.id === 2)?.role).toBe('admin');
  });

  it('never offers to change or remove your own row', () => {
    signInAs(OWNER, [OWNER, SECOND_OWNER]);

    expect(store.canChangeRoleOf(OWNER)).toBe(false);
    expect(store.canRemove(OWNER)).toBe(false);

    store.changeRole(OWNER, 'member');
    http.expectNone(() => true);
  });

  it('lets only an owner change a role', () => {
    signInAs(ADMIN);

    expect(store.canChangeRoleOf(MEMBER)).toBe(false);
    store.changeRole(MEMBER, 'admin');
    http.expectNone(() => true);
  });

  it('lets an admin remove a member but not an owner', () => {
    signInAs(ADMIN, [OWNER, SECOND_OWNER, ADMIN, MEMBER]);

    expect(store.canRemove(MEMBER)).toBe(true);
    // Two owners exist, so the last-owner rule is not what is refusing this.
    expect(store.ownerCount()).toBe(2);
    expect(store.canRemove(OWNER)).toBe(false);
  });

  it('offers no role above the inviter own', () => {
    signInAs(ADMIN);
    expect(store.invitableRoles()).toEqual(['admin', 'member']);
    expect(store.canInvite()).toBe(true);

    signInAs(OWNER);
    expect(store.invitableRoles()).toEqual(['owner', 'admin', 'member']);

    signInAs(MEMBER);
    expect(store.canInvite()).toBe(false);
    expect(store.invitableRoles()).toEqual(['member']);
  });

  /**
   * An invitation is a row in its own table, not a user. Adding an optimistic
   * member would claim someone the company has not got.
   */
  it('adds no row when an invitation is sent', () => {
    signInAs(OWNER, [OWNER]);

    store.invite({ email: 'new@acme.com', role: 'member' });
    const request = http.expectOne(`${TEAM}/invitations`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ email: 'new@acme.com', role: 'member' });
    request.flush({}, { status: 201, statusText: 'Created' });

    expect(store.members()).toHaveLength(1);
    expect(store.inviting()).toBe(false);
  });

  it('keeps the invitation 422 on its own field', () => {
    signInAs(OWNER, [OWNER]);

    store.invite({ email: 'taken@acme.com', role: 'member' });
    http
      .expectOne(`${TEAM}/invitations`)
      .flush(
        { code: 'VALIDATION_ERROR', message: 'invalid', errors: { email: ['Already invited.'] } },
        { status: 422, statusText: 'Unprocessable Content' }
      );

    expect(store.inviteErrors()).toEqual({ email: ['Already invited.'] });
  });

  it('drops a removed member from the list', () => {
    signInAs(OWNER, [OWNER, MEMBER]);

    store.remove(MEMBER);
    http.expectOne(`${TEAM}/4`).flush(null, { status: 204, statusText: 'No Content' });

    expect(store.members().map((member) => member.id)).toEqual([1]);
  });

  it('keeps the row when the server refuses a role change', () => {
    signInAs(OWNER, [OWNER, MEMBER]);

    store.changeRole(MEMBER, 'admin');
    http
      .expectOne(`${TEAM}/4`)
      .flush({ code: 'FORBIDDEN', message: 'policy denied' }, { status: 403, statusText: 'Forbidden' });

    expect(store.members().find((member) => member.id === 4)?.role).toBe('member');
    expect(store.updatingId()).toBeNull();
  });
});
