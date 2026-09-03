import { computed, inject, Injectable, signal } from '@angular/core';

import { EMPTY_META, PaginationMeta } from '../api/pagination';
import { RequestState } from '../api/request-state';
import { User, UserRole } from '../auth/auth.models';
import { AuthStore } from '../auth/auth.store';
import { FieldErrors } from '../errors/api-error';
import { errorCodeOf, fieldErrorsOf } from '../errors/error-code';
import { ToastService } from '../toast/toast.service';
import { InvitationBody, ROLE_RANK, rolesAssignableBy } from './settings.models';
import { SettingsService } from './settings.service';

/**
 * `/app/settings/team` — who is in the company and at which role.
 *
 * **The role rules live here as well as on the server, and that is on purpose.**
 * The policies are the authority; these predicates exist so the UI never offers
 * a control that is going to be refused. Spec section 8's three rules:
 *
 * - the **last owner may not be demoted** — a company with no owner can never
 *   be billed, erased, or have its team managed again;
 * - **nobody changes their own role**, which is the same rule from the other
 *   side: it is how the last owner would demote themselves;
 * - **nobody invites above their own role**, and only an `owner` grants `owner`.
 *
 * Each predicate answers for one row, so the template asks rather than
 * re-deriving the rule per button.
 */
@Injectable({ providedIn: 'root' })
export class TeamStore {
  private readonly service = inject(SettingsService);
  private readonly auth = inject(AuthStore);
  private readonly toasts = inject(ToastService);

  private readonly membersSignal = signal<readonly User[]>([]);
  private readonly metaSignal = signal<PaginationMeta>(EMPTY_META);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private readonly invitingSignal = signal(false);
  private readonly inviteErrorsSignal = signal<FieldErrors | null>(null);
  private readonly updatingIdSignal = signal<number | null>(null);
  private readonly removingIdSignal = signal<number | null>(null);

  private requestToken = 0;

  readonly members = this.membersSignal.asReadonly();
  readonly meta = this.metaSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();
  readonly inviting = this.invitingSignal.asReadonly();
  readonly inviteErrors = this.inviteErrorsSignal.asReadonly();
  readonly updatingId = this.updatingIdSignal.asReadonly();
  readonly removingId = this.removingIdSignal.asReadonly();

  readonly loading = computed(() => this.stateSignal() === 'idle' || this.stateSignal() === 'loading');
  readonly currentUserId = computed(() => this.auth.user()?.id ?? null);
  readonly currentRole = computed<UserRole | null>(() => this.auth.role());

  readonly ownerCount = computed(() => this.membersSignal().filter((member) => member.role === 'owner').length);

  /** `owner` and `admin` may invite; a `member` sees no invite control at all. */
  readonly canInvite = computed(() => {
    const role = this.currentRole();
    return role === 'owner' || role === 'admin';
  });

  /** Never above your own role, and `owner` only from an `owner`. */
  readonly invitableRoles = computed<readonly UserRole[]>(() => rolesAssignableBy(this.currentRole()));

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.team().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.membersSignal.set(response.data);
        this.metaSignal.set(response.meta);
        this.stateSignal.set('ready');
      },
      error: (error: unknown) => {
        if (token !== this.requestToken) {
          return;
        }
        this.errorCodeSignal.set(errorCodeOf(error));
        this.stateSignal.set('error');
      }
    });
  }

  loadIfNeeded(): void {
    if (this.stateSignal() === 'idle') {
      this.load();
    }
  }

  /** Only an `owner` may change a role, and never their own (contract section 2). */
  canChangeRoleOf(member: User): boolean {
    return this.currentRole() === 'owner' && member.id !== this.currentUserId();
  }

  /**
   * The last owner may not be demoted. The check is on the count of owners in
   * the loaded list, which is the whole company: the list is read at the
   * contract's maximum page size and `meta.last_page` is surfaced so a company
   * that outgrows one page is visible rather than silently mis-counted.
   */
  canDemote(member: User): boolean {
    if (!this.canChangeRoleOf(member)) {
      return false;
    }
    return member.role !== 'owner' || this.ownerCount() > 1;
  }

  /** `owner` or `admin`, never yourself, never the last owner. */
  canRemove(member: User): boolean {
    const role = this.currentRole();
    if (role !== 'owner' && role !== 'admin') {
      return false;
    }
    if (member.id === this.currentUserId()) {
      return false;
    }
    if (member.role === 'owner' && this.ownerCount() <= 1) {
      return false;
    }
    // An admin may not act on someone ranked above them.
    return ROLE_RANK[member.role] <= ROLE_RANK[role];
  }

  clearInviteErrors(): void {
    this.inviteErrorsSignal.set(null);
  }

  invite(body: InvitationBody, onSuccess?: () => void): void {
    if (this.invitingSignal()) {
      return;
    }
    this.invitingSignal.set(true);
    this.inviteErrorsSignal.set(null);

    this.service.invite(body).subscribe({
      next: () => {
        this.invitingSignal.set(false);
        onSuccess?.();
        // Deliberately no optimistic row: an invitation is a row in its own
        // table, not a user. The team list only grows once the invitee accepts,
        // and adding a placeholder would claim a member the company has not got.
        this.toasts.success(
          $localize`:Toast after sending a team invitation@@settings.team.invited:Invitation sent. The member appears here once they accept it.`
        );
      },
      error: (error: unknown) => {
        this.invitingSignal.set(false);
        this.inviteErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  changeRole(member: User, role: UserRole): void {
    if (this.updatingIdSignal() !== null || !this.canChangeRoleOf(member)) {
      return;
    }
    if (role !== 'owner' && member.role === 'owner' && !this.canDemote(member)) {
      return;
    }
    this.updatingIdSignal.set(member.id);

    this.service.updateRole(member.id, { role }).subscribe({
      next: (response) => {
        this.updatingIdSignal.set(null);
        this.membersSignal.update((members) =>
          members.map((item) => (item.id === response.user.id ? response.user : item))
        );
      },
      error: () => {
        // The interceptor has already surfaced the code; the row keeps the role
        // the server still holds.
        this.updatingIdSignal.set(null);
      }
    });
  }

  remove(member: User, onSuccess?: () => void): void {
    if (this.removingIdSignal() !== null || !this.canRemove(member)) {
      return;
    }
    this.removingIdSignal.set(member.id);

    this.service.removeMember(member.id).subscribe({
      next: () => {
        this.removingIdSignal.set(null);
        this.membersSignal.update((members) => members.filter((item) => item.id !== member.id));
        onSuccess?.();
      },
      error: () => {
        this.removingIdSignal.set(null);
      }
    });
  }

  reset(): void {
    this.requestToken++;
    this.membersSignal.set([]);
    this.metaSignal.set(EMPTY_META);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
    this.invitingSignal.set(false);
    this.inviteErrorsSignal.set(null);
    this.updatingIdSignal.set(null);
    this.removingIdSignal.set(null);
  }
}
