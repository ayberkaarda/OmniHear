import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { User, UserRole } from '../../../../core/auth/auth.models';
import { errorMessageForCode } from '../../../../core/errors/error-messages';
import { TeamStore } from '../../../../core/settings/team.store';
import { BadgeComponent } from '../../../../shared/ui/badge/badge.component';
import { ButtonComponent } from '../../../../shared/ui/button/button.component';
import { InputComponent } from '../../../../shared/ui/form-field/input.component';
import { SelectComponent, SelectOption } from '../../../../shared/ui/form-field/select.component';
import { ModalComponent } from '../../../../shared/ui/modal/modal.component';
import { formatDate } from '../../../../shared/format/format';
import { controlErrorMessage, serverFieldError } from '../../../auth/shared/form-errors';

type Dialog = 'none' | 'invite' | 'remove';

/**
 * `/app/settings/team` — the company's members and their roles.
 *
 * **The screen does not offer an action the server is going to refuse.** The
 * policies are the authority (spec section 8), but a disabled control with a
 * reason attached is a better answer than a `403 FORBIDDEN` toast for a rule
 * the user could not have known. Three rules are mirrored here, and each one
 * is a predicate on `TeamStore` rather than an inline condition in this
 * template:
 *
 * - the last owner is not demotable and not removable;
 * - nobody edits their own role;
 * - the invite dialog offers only roles at or below the inviter's own.
 */
@Component({
  selector: 'app-team',
  standalone: true,
  imports: [ReactiveFormsModule, BadgeComponent, ButtonComponent, InputComponent, SelectComponent, ModalComponent],
  templateUrl: './team.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class TeamComponent implements OnInit {
  private readonly store = inject(TeamStore);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly members = this.store.members;
  protected readonly loading = this.store.loading;
  protected readonly inviting = this.store.inviting;
  protected readonly updatingId = this.store.updatingId;
  protected readonly removingId = this.store.removingId;
  protected readonly canInvite = this.store.canInvite;

  protected readonly dialog = signal<Dialog>('none');
  protected readonly selected = signal<User | null>(null);
  private readonly formTick = signal(0);

  protected readonly inviteForm = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    role: ['member', [Validators.required]]
  });

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  protected readonly roleOptions = computed<SelectOption[]>(() =>
    this.store.invitableRoles().map((role) => ({ value: role, label: roleLabel(role) }))
  );

  /** Every role is offered on the row select; the store refuses the ones that break a rule. */
  protected readonly assignableRoleOptions = computed<SelectOption[]>(() =>
    (['owner', 'admin', 'member'] as const).map((role) => ({ value: role, label: roleLabel(role) }))
  );

  protected readonly removePrompt = computed(() => {
    const member = this.selected();
    if (member === null) {
      return '';
    }
    const name = member.name;
    return $localize`:Confirmation before removing a team member@@settings.team.remove.prompt:Remove ${name}:name: from the company? Their access ends immediately and every device they are signed in on is signed out.`;
  });

  protected readonly inviteLabel = $localize`:Open the invite dialog@@settings.team.invite:Invite a member`;
  protected readonly inviteTitle = $localize`:Invite dialog title@@settings.team.invite.title:Invite a member`;
  protected readonly inviteEmailLabel = $localize`:Invite form field@@settings.team.invite.email:Email address`;
  protected readonly inviteRoleLabel = $localize`:Invite form field@@settings.team.invite.role:Role`;
  protected readonly inviteSubmitLabel = $localize`:Send the invitation@@settings.team.invite.submit:Send invitation`;
  protected readonly removeTitle = $localize`:Remove member dialog title@@settings.team.remove.title:Remove member`;
  protected readonly removeConfirmLabel = $localize`:Confirm removing a member@@settings.team.remove.confirm:Remove`;
  protected readonly removeLabel = $localize`:Remove a team member@@settings.team.action.remove:Remove`;
  protected readonly cancelLabel = $localize`:Dismiss a dialog@@common.cancel:Cancel`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly roleFieldLabel = $localize`:Row-level role picker label@@settings.team.roleFor:Role`;
  protected readonly lastOwnerHint = $localize`:Why the owner row cannot be changed@@settings.team.lastOwner:A company must keep at least one owner.`;
  protected readonly selfHint = $localize`:Why your own row cannot be changed@@settings.team.selfRow:You cannot change your own role.`;

  ngOnInit(): void {
    this.store.loadIfNeeded();
  }

  protected reload(): void {
    this.store.load();
  }

  protected roleLabelOf(role: UserRole): string {
    return roleLabel(role);
  }

  protected joinedOn(member: User): string {
    return formatDate(member.created_at);
  }

  protected isSelf(member: User): boolean {
    return member.id === this.store.currentUserId();
  }

  protected canChangeRoleOf(member: User): boolean {
    return this.store.canChangeRoleOf(member);
  }

  protected canRemove(member: User): boolean {
    return this.store.canRemove(member);
  }

  /** The one-line reason a row's controls are inert, for the row's own hint. */
  protected lockReason(member: User): string | null {
    if (this.isSelf(member)) {
      return this.selfHint;
    }
    if (member.role === 'owner' && this.store.ownerCount() <= 1) {
      return this.lastOwnerHint;
    }
    return null;
  }

  protected roleSelectLabel(member: User): string {
    return `${this.roleFieldLabel} — ${member.name}`;
  }

  protected onRoleChange(member: User, value: string | null): void {
    if (value === null || value === member.role) {
      return;
    }
    this.store.changeRole(member, value as UserRole);
  }

  protected openInvite(): void {
    this.store.clearInviteErrors();
    this.inviteForm.reset({ email: '', role: 'member' });
    this.dialog.set('invite');
  }

  protected openRemove(member: User): void {
    this.selected.set(member);
    this.dialog.set('remove');
  }

  protected closeDialog(): void {
    this.dialog.set('none');
    this.selected.set(null);
    this.store.clearInviteErrors();
  }

  protected onFieldBlur(): void {
    this.formTick.update((value) => value + 1);
  }

  protected inviteError(field: string): string | undefined {
    this.formTick();
    return serverFieldError(this.store.inviteErrors(), field) ?? controlErrorMessage(this.inviteForm.get(field));
  }

  protected submitInvite(): void {
    if (this.inviteForm.invalid) {
      this.inviteForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }
    const { email, role } = this.inviteForm.getRawValue();
    this.store.invite({ email: email.trim(), role: role as UserRole }, () => this.closeDialog());
  }

  protected confirmRemove(): void {
    const member = this.selected();
    if (member === null) {
      return;
    }
    this.store.remove(member, () => this.closeDialog());
  }
}

/**
 * Spec section 8's three roles. Localized here rather than in
 * `shared/labels/domain-labels.ts` because that file is the *feedback* domain's
 * vocabulary and this one is the tenancy domain's; sharing a module would drag
 * the wrong strings into the wrong chunk.
 */
function roleLabel(role: UserRole): string {
  switch (role) {
    case 'owner':
      return $localize`:Team role@@label.role.owner:Owner`;
    case 'admin':
      return $localize`:Team role@@label.role.admin:Administrator`;
    default:
      return $localize`:Team role@@label.role.member:Member`;
  }
}
