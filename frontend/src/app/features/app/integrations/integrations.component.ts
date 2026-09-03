import { ChangeDetectionStrategy, Component, computed, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { FormControl, FormGroup, NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Subscription } from 'rxjs';

import { errorMessageForCode } from '../../../core/errors/error-messages';
import { Integration, IntegrationWriteBody, Platform } from '../../../core/integrations/integration.models';
import { IntegrationsStore } from '../../../core/integrations/integrations.store';
import { PlatformsStore } from '../../../core/integrations/platforms.store';
import { BadgeComponent } from '../../../shared/ui/badge/badge.component';
import { ButtonComponent } from '../../../shared/ui/button/button.component';
import { EmptyStateComponent } from '../../../shared/ui/empty-state/empty-state.component';
import { InputComponent } from '../../../shared/ui/form-field/input.component';
import { SelectComponent, SelectOption } from '../../../shared/ui/form-field/select.component';
import { ModalComponent } from '../../../shared/ui/modal/modal.component';
import { formatCount, formatDateTime } from '../../../shared/format/format';
import { credentialLabel, platformLabel, settingLabel } from '../../../shared/labels/domain-labels';

const SETTING_PREFIX = 's_';
const CREDENTIAL_PREFIX = 'c_';

export interface ConnectorField {
  /** Form control name — prefixed so a setting and a credential cannot collide. */
  readonly control: string;
  /** The key the API expects inside `settings` / `credentials`. */
  readonly key: string;
  readonly label: string;
  /** From the registry, not from a local table: the server decides what is mandatory. */
  readonly required: boolean;
  readonly secret: boolean;
}

type DialogMode = 'none' | 'create' | 'edit' | 'delete';

/**
 * `/app/integrations` — connect a channel, watch its health, sync it on demand.
 *
 * **Credentials go one way.** `Integration` has no `credentials` field because
 * the API never serializes one (invariant I5), so there is nothing on this
 * screen that could display a stored secret even by accident. The edit form
 * leaves the credential inputs blank and says so: submitting them empty keeps
 * what is stored, and filling them in rotates it.
 *
 * **The form is server-driven.** Which platforms can be connected, and what
 * each one needs, comes from `GET /api/v1/integrations/platforms` through
 * `PlatformsStore`. It used to come from a hand-copied mirror of
 * `config/connectors.php` in `integration.models.ts`, which drifted the first
 * time the backend changed — the next drift would have reached a user as a
 * `422` on a platform this form offered.
 */
@Component({
  selector: 'app-integrations',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    BadgeComponent,
    ButtonComponent,
    EmptyStateComponent,
    InputComponent,
    SelectComponent,
    ModalComponent
  ],
  templateUrl: './integrations.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class IntegrationsComponent implements OnInit, OnDestroy {
  private readonly store = inject(IntegrationsStore);
  private readonly platforms = inject(PlatformsStore);
  private readonly fb = inject(NonNullableFormBuilder);

  private readonly subscriptions = new Subscription();

  protected readonly items = this.store.items;
  protected readonly saving = this.store.saving;
  protected readonly syncingIds = this.store.syncingIds;
  protected readonly deletingId = this.store.deletingId;
  protected readonly isEmpty = this.store.isEmpty;

  protected readonly dialog = signal<DialogMode>('none');
  protected readonly selected = signal<Integration | null>(null);
  /** `null` until the platform list has arrived: there is nothing to guess at. */
  protected readonly platform = signal<Platform | null>(null);

  /** Rebuilt whenever the platform changes: each connector asks for different fields. */
  protected readonly form = signal<FormGroup>(new FormGroup({}));

  protected readonly loading = computed(() => {
    const state = this.store.state();
    return state === 'idle' || state === 'loading';
  });

  protected readonly errorMessage = computed(() => {
    const code = this.store.errorCode();
    return code === null ? null : errorMessageForCode(code);
  });

  protected readonly platformControl = this.fb.control<string>('');
  protected readonly statusControl = this.fb.control<string>('active');

  /** Whatever the connector registry says it accepts, in the order the API sent it. */
  protected readonly platformOptions = computed<SelectOption[]>(() =>
    this.platforms.items().map((descriptor) => ({
      value: descriptor.platform,
      label: platformLabel(descriptor.platform)
    }))
  );

  protected readonly platformsLoading = this.platforms.loading;

  /**
   * The create dialog has nothing to offer until the registry answers, and it
   * says so rather than presenting an empty picker that would `422`.
   */
  protected readonly platformsUnavailable = computed(
    () => !this.platforms.loading() && this.platforms.items().length === 0
  );

  protected readonly statusOptions = computed<SelectOption[]>(() => [
    { value: 'active', label: this.statusActiveLabel },
    { value: 'paused', label: this.statusPausedLabel }
  ]);

  protected readonly settingFields = computed<readonly ConnectorField[]>(() =>
    this.platforms.settingsFor(this.platform()).map((field) => ({
      control: SETTING_PREFIX + field.key,
      key: field.key,
      label: settingLabel(field.key),
      required: field.required,
      secret: false
    }))
  );

  protected readonly credentialFields = computed<readonly ConnectorField[]>(() =>
    this.platforms.credentialsFor(this.platform()).map((field) => ({
      control: CREDENTIAL_PREFIX + field.key,
      key: field.key,
      label: credentialLabel(field.key),
      required: field.required,
      secret: true
    }))
  );

  protected readonly dialogTitle = computed(() => {
    switch (this.dialog()) {
      case 'create':
        return this.connectTitle;
      case 'edit':
        return this.editTitle;
      case 'delete':
        return this.deleteTitle;
      default:
        return '';
    }
  });

  protected readonly deletePrompt = computed(() => {
    const target = this.selected();
    if (target === null) {
      return '';
    }
    const name = platformLabel(target.platform);
    const count = formatCount(target.feedback_count);
    return $localize`:Confirmation before deleting a connection@@integrations.delete.prompt:Disconnect ${name}:platform:? Its ${count}:count: collected comments are removed with it, and this cannot be undone.`;
  });

  protected readonly connectLabel = $localize`:Open the connect-a-channel dialog@@integrations.connect:Connect a channel`;
  protected readonly connectTitle = $localize`:Connect dialog title@@integrations.dialog.createTitle:Connect a channel`;
  protected readonly editTitle = $localize`:Edit dialog title@@integrations.dialog.editTitle:Edit connection`;
  protected readonly deleteTitle = $localize`:Delete dialog title@@integrations.dialog.deleteTitle:Disconnect channel`;
  protected readonly platformFieldLabel = $localize`:Platform picker label@@integrations.field.platform:Platform`;
  protected readonly statusFieldLabel = $localize`:Connection status picker label@@integrations.field.status:Connection state`;
  protected readonly statusActiveLabel = $localize`:Connection state option@@integrations.status.active:Active — sync on schedule`;
  protected readonly statusPausedLabel = $localize`:Connection state option@@integrations.status.paused:Paused — do not sync`;
  protected readonly credentialsKeepHint = $localize`:Hint above the credential inputs when editing@@integrations.credentials.keepHint:Leave blank to keep the stored credentials. Filling a field replaces it.`;
  protected readonly credentialsWriteOnlyHint = $localize`:Hint explaining credentials are never shown again@@integrations.credentials.writeOnly:Credentials are stored encrypted and are never shown again, not even to you.`;
  protected readonly saveLabel = $localize`:Save the connection dialog@@integrations.action.save:Save`;
  protected readonly cancelLabel = $localize`:Dismiss a dialog@@common.cancel:Cancel`;
  protected readonly deleteConfirmLabel = $localize`:Confirm disconnecting a channel@@integrations.action.delete:Disconnect`;
  protected readonly syncLabel = $localize`:Trigger a sync run@@integrations.action.sync:Sync now`;
  protected readonly editLabel = $localize`:Edit a connection@@integrations.action.edit:Edit`;
  protected readonly removeLabel = $localize`:Remove a connection@@integrations.action.remove:Disconnect`;
  protected readonly retryLabel = $localize`:Retry a failed load@@common.retry:Try again`;
  protected readonly emptyHeading = $localize`:Integrations empty state@@app.integrations.empty:No channel is connected yet`;
  protected readonly emptyDescription = $localize`:Integrations empty state@@integrations.empty.description:Connect App Store or Zendesk and OmniHear starts collecting comments on a schedule.`;

  ngOnInit(): void {
    this.rebuildForm();
    this.subscriptions.add(
      this.platformControl.valueChanges.subscribe((value) => {
        this.platform.set(value === '' ? null : (value as Platform));
        this.rebuildForm();
      })
    );
    this.store.loadIfNeeded();
    // The registry drives the form, so it is read on arrival rather than when
    // the dialog opens: a spinner inside a modal the user just opened reads as
    // a broken dialog.
    this.platforms.loadIfNeeded();
  }

  ngOnDestroy(): void {
    this.subscriptions.unsubscribe();
  }

  protected reload(): void {
    this.store.load();
  }

  protected platformLabelOf(integration: Integration): string {
    return platformLabel(integration.platform);
  }

  protected isSyncing(integration: Integration): boolean {
    return this.syncingIds().has(integration.id);
  }

  protected settingsOf(integration: Integration): readonly { key: string; label: string; value: string }[] {
    return Object.entries(integration.settings).map(([key, value]) => ({
      key,
      label: settingLabel(key),
      value: String(value)
    }));
  }

  protected lastSynced(integration: Integration): string {
    return formatDateTime(integration.last_synced_at);
  }

  protected feedbackCount(integration: Integration): string {
    return formatCount(integration.feedback_count);
  }

  protected openCreate(): void {
    this.store.clearSaveErrors();
    this.selected.set(null);
    const first = this.platforms.items()[0]?.platform ?? null;
    this.platformControl.setValue(first ?? '', { emitEvent: false });
    this.platform.set(first);
    this.statusControl.setValue('active');
    this.rebuildForm();
    this.dialog.set('create');
  }

  protected openEdit(integration: Integration): void {
    this.store.clearSaveErrors();
    this.selected.set(integration);
    this.platform.set(integration.platform);
    this.platformControl.setValue(integration.platform, { emitEvent: false });
    this.statusControl.setValue(integration.status === 'paused' ? 'paused' : 'active');
    this.rebuildForm(integration);
    this.dialog.set('edit');
  }

  protected openDelete(integration: Integration): void {
    this.selected.set(integration);
    this.dialog.set('delete');
  }

  protected closeDialog(): void {
    this.dialog.set('none');
    this.selected.set(null);
    this.store.clearSaveErrors();
  }

  protected onSync(integration: Integration): void {
    this.store.sync(integration.id);
  }

  protected confirmDelete(): void {
    const target = this.selected();
    if (target === null) {
      return;
    }
    this.store.remove(target.id, () => this.closeDialog());
  }

  protected submit(): void {
    const form = this.form();
    if (form.invalid) {
      form.markAllAsTouched();
      return;
    }

    const body = this.buildBody(form);
    const target = this.selected();

    if (this.dialog() === 'edit' && target !== null) {
      this.store.update(target.id, body, () => this.closeDialog());
    } else {
      this.store.create(body, () => this.closeDialog());
    }
  }

  /**
   * Server-side field error for one control.
   *
   * The server names them `settings.app_id` / `credentials.api_token`, which is
   * why the controls carry the prefix in the first place — the mapping back is
   * mechanical rather than a lookup table that can drift.
   */
  protected serverError(field: ConnectorField): string | undefined {
    const errors = this.store.saveErrors();
    if (errors === null) {
      return undefined;
    }
    const path = `${field.secret ? 'credentials' : 'settings'}.${field.key}`;
    return errors[path]?.[0] ?? errors[field.secret ? 'credentials' : 'settings']?.[0];
  }

  protected platformError(): string | undefined {
    return this.store.saveErrors()?.['platform']?.[0];
  }

  private rebuildForm(integration?: Integration): void {
    const controls: Record<string, FormControl<string>> = {};

    for (const field of this.platforms.settingsFor(this.platform())) {
      controls[SETTING_PREFIX + field.key] = this.fb.control(
        String(integration?.settings[field.key] ?? ''),
        field.required ? [Validators.required] : []
      );
    }

    for (const field of this.platforms.credentialsFor(this.platform())) {
      // Required on create when the registry says so, always optional on edit:
      // an empty credential field on an existing connection means "keep what is
      // stored", and there is no way to pre-fill it because the value is never
      // sent to the browser (invariant I5).
      controls[CREDENTIAL_PREFIX + field.key] = this.fb.control(
        '',
        integration === undefined && field.required ? [Validators.required] : []
      );
    }

    this.form.set(new FormGroup(controls));
  }

  private buildBody(form: FormGroup): IntegrationWriteBody {
    const raw = form.getRawValue() as Record<string, string>;
    const settings: Record<string, string> = {};
    const credentials: Record<string, string> = {};

    for (const [name, value] of Object.entries(raw)) {
      if (name.startsWith(SETTING_PREFIX)) {
        settings[name.slice(SETTING_PREFIX.length)] = value.trim();
      } else if (name.startsWith(CREDENTIAL_PREFIX) && value.trim() !== '') {
        credentials[name.slice(CREDENTIAL_PREFIX.length)] = value.trim();
      }
    }

    if (this.dialog() === 'edit') {
      const body: IntegrationWriteBody = {
        settings,
        status: this.statusControl.value === 'paused' ? 'paused' : 'active'
      };
      // `platform` is `prohibited` on PATCH, and an omitted `credentials` is
      // what keeps the stored secret in place.
      return Object.keys(credentials).length > 0 ? { ...body, credentials } : body;
    }

    return { platform: this.platform() ?? undefined, settings, credentials };
  }
}
