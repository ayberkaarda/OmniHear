import { ChangeDetectionStrategy, Component, computed, inject, OnInit, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { errorMessageForCode } from '../../../../core/errors/error-messages';
import { ApiKeysStore } from '../../../../core/settings/api-keys.store';
import { ApiKey, DeviceSession } from '../../../../core/settings/settings.models';
import { ButtonComponent } from '../../../../shared/ui/button/button.component';
import { EmptyStateComponent } from '../../../../shared/ui/empty-state/empty-state.component';
import { InputComponent } from '../../../../shared/ui/form-field/input.component';
import { ModalComponent } from '../../../../shared/ui/modal/modal.component';
import { formatDateTime } from '../../../../shared/format/format';
import { controlErrorMessage, serverFieldError } from '../../../auth/shared/form-errors';

type Dialog = 'none' | 'create' | 'reveal' | 'revokeKey' | 'revokeSession';

/**
 * `/app/settings/api-keys` — programmatic keys, and the devices this account is
 * signed in on.
 *
 * **Two lists that must never be confused.** An API key and a device session
 * are both Sanctum tokens and look identical in a table; they are separated by
 * ability and by endpoint (`GET /settings/api-keys` versus `GET /auth/tokens`),
 * and the failure `docs/contracts/settings-api.md` section 3 exists to prevent
 * is one screen revoking the other's rows. They are rendered as two headed
 * sections with two different revoke paths, and no control on this screen can
 * reach the other list's endpoint.
 *
 * **The plaintext key is shown once and the screen says so before the dialog
 * closes.** The value cannot be recovered afterwards — the server keeps a hash
 * — so the reveal dialog states that plainly *above* the value rather than as a
 * footnote under it, and its dismiss button is labelled with what dismissing
 * costs. It is not dismissible by Esc or a backdrop click, because losing the
 * value to a stray keystroke is unrecoverable.
 */
@Component({
  selector: 'app-api-keys',
  standalone: true,
  imports: [ReactiveFormsModule, ButtonComponent, EmptyStateComponent, InputComponent, ModalComponent],
  templateUrl: './api-keys.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ApiKeysComponent implements OnInit {
  private readonly store = inject(ApiKeysStore);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly keys = this.store.keys;
  protected readonly sessions = this.store.sessions;
  protected readonly loading = this.store.loading;
  protected readonly sessionsLoading = this.store.sessionsLoading;
  protected readonly isEmpty = this.store.isEmpty;
  protected readonly creating = this.store.creating;
  protected readonly revokingKeyId = this.store.revokingKeyId;
  protected readonly revokingSessionId = this.store.revokingSessionId;
  protected readonly plainTextToken = this.store.plainTextToken;
  protected readonly createdKey = this.store.createdKey;
  protected readonly canManageKeys = this.store.canManageKeys;

  protected readonly dialog = signal<Dialog>('none');
  protected readonly selectedKey = signal<ApiKey | null>(null);
  protected readonly selectedSession = signal<DeviceSession | null>(null);
  protected readonly copied = signal(false);
  private readonly formTick = signal(0);

  protected readonly createForm = this.fb.group({
    name: ['', [Validators.required, Validators.maxLength(255)]]
  });

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  protected readonly revokeKeyPrompt = computed(() => {
    const key = this.selectedKey();
    if (key === null) {
      return '';
    }
    const name = key.name;
    return $localize`:Confirmation before revoking an API key@@settings.apiKeys.revoke.prompt:Revoke ${name}:name:? Anything still using this key stops working immediately, and the key cannot be restored.`;
  });

  protected readonly revokeSessionPrompt = computed(() => {
    const session = this.selectedSession();
    if (session === null) {
      return '';
    }
    const name = session.name;
    return $localize`:Confirmation before revoking a device session@@settings.sessions.revoke.prompt:Sign ${name}:name: out? If this is the device you are using now, you will be returned to the sign-in screen.`;
  });

  protected readonly createLabel = $localize`:Open the create-key dialog@@settings.apiKeys.create:Create an API key`;
  protected readonly createTitle = $localize`:Create key dialog title@@settings.apiKeys.create.title:Create an API key`;
  protected readonly nameLabel = $localize`:API key form field@@settings.apiKeys.field.name:What is this key for?`;
  protected readonly nameHelper = $localize`:Helper under the API key name field@@settings.apiKeys.field.nameHelper:A name only you see, so you can tell your keys apart later — "billing export", "staging".`;
  protected readonly createSubmitLabel = $localize`:Create the API key@@settings.apiKeys.create.submit:Create key`;
  protected readonly revealTitle = $localize`:Reveal dialog title@@settings.apiKeys.reveal.title:Copy your key now`;
  protected readonly revealDismissLabel = $localize`:Dismiss the reveal dialog@@settings.apiKeys.reveal.dismiss:I have copied it`;
  protected readonly copyLabel = $localize`:Copy the key to the clipboard@@settings.apiKeys.reveal.copy:Copy`;
  protected readonly copiedLabel = $localize`:Confirmation that the key was copied@@settings.apiKeys.reveal.copied:Copied`;
  protected readonly revokeKeyTitle = $localize`:Revoke key dialog title@@settings.apiKeys.revoke.title:Revoke API key`;
  protected readonly revokeSessionTitle = $localize`:Revoke session dialog title@@settings.sessions.revoke.title:Sign this device out`;
  protected readonly revokeLabel = $localize`:Revoke an API key@@settings.apiKeys.action.revoke:Revoke`;
  protected readonly signOutDeviceLabel = $localize`:Sign one device out@@settings.sessions.action.revoke:Sign out`;
  protected readonly cancelLabel = $localize`:Dismiss a dialog@@common.cancel:Cancel`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly emptyHeading = $localize`:Empty state heading@@app.settings.apiKeys.empty:No API key has been created`;
  protected readonly emptyDescription = $localize`:API keys empty state@@settings.apiKeys.empty.description:An API key lets a script or another system read this company's feedback without a browser session.`;
  protected readonly neverUsedLabel = $localize`:Shown instead of a last-used date@@settings.apiKeys.neverUsed:Never used`;

  ngOnInit(): void {
    this.store.loadIfNeeded();
  }

  protected reload(): void {
    this.store.load();
  }

  protected lastUsed(value: string | null): string {
    return value === null ? this.neverUsedLabel : formatDateTime(value);
  }

  protected created(value: string | null): string {
    return formatDateTime(value);
  }

  protected openCreate(): void {
    this.store.clearCreateErrors();
    this.createForm.reset({ name: '' });
    this.dialog.set('create');
  }

  protected openRevokeKey(key: ApiKey): void {
    this.selectedKey.set(key);
    this.dialog.set('revokeKey');
  }

  protected openRevokeSession(session: DeviceSession): void {
    this.selectedSession.set(session);
    this.dialog.set('revokeSession');
  }

  protected closeDialog(): void {
    this.dialog.set('none');
    this.selectedKey.set(null);
    this.selectedSession.set(null);
    this.store.clearCreateErrors();
  }

  /** Closing the reveal is the moment the value stops existing outside the user's clipboard. */
  protected dismissReveal(): void {
    this.dialog.set('none');
    this.copied.set(false);
    this.store.clearPlainTextToken();
  }

  protected onFieldBlur(): void {
    this.formTick.update((value) => value + 1);
  }

  protected createError(field: string): string | undefined {
    this.formTick();
    return serverFieldError(this.store.createErrors(), field) ?? controlErrorMessage(this.createForm.get(field));
  }

  protected submitCreate(): void {
    if (this.createForm.invalid) {
      this.createForm.markAllAsTouched();
      this.onFieldBlur();
      return;
    }
    this.copied.set(false);
    this.store.create(this.createForm.getRawValue().name.trim(), () => this.dialog.set('reveal'));
  }

  protected confirmRevokeKey(): void {
    const key = this.selectedKey();
    if (key === null) {
      return;
    }
    this.store.revokeKey(key.id, () => this.closeDialog());
  }

  protected confirmRevokeSession(): void {
    const session = this.selectedSession();
    if (session === null) {
      return;
    }
    this.store.revokeSession(session.id, () => this.closeDialog());
  }

  /**
   * Best-effort. The Clipboard API needs a secure context and a permission the
   * user can refuse, so the value stays selectable on screen and the button is
   * an accelerator rather than the only way to get the key out.
   */
  protected copyToken(): void {
    const token = this.plainTextToken();
    if (token === null || typeof navigator === 'undefined' || navigator.clipboard === undefined) {
      return;
    }
    void navigator.clipboard.writeText(token).then(
      () => this.copied.set(true),
      () => this.copied.set(false)
    );
  }
}
