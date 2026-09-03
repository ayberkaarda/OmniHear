import { computed, inject, Injectable, signal } from '@angular/core';

import { RequestState } from '../api/request-state';
import { errorCodeOf } from '../errors/error-code';
import { PlatformDescriptor, PlatformField } from '../settings/settings.models';
import { SettingsService } from '../settings/settings.service';

const EMPTY_FIELDS: readonly PlatformField[] = [];

/**
 * `GET /api/v1/integrations/platforms` — the connector registry, server-side.
 *
 * This store exists to delete a mirror. The integration form used to be driven
 * by `CONNECTABLE_PLATFORMS` / `REQUIRED_SETTINGS` / `REQUIRED_CREDENTIALS` in
 * `integration.models.ts`, which were hand-copied from `config/connectors.php`.
 * They drifted the first time anyone changed the backend: Zendesk was added
 * during the previous wave and the mismatch was caught by hand. The next one
 * would have reached a user as a `422` on a platform the form offered.
 *
 * The form now renders what the server says it accepts, so the two cannot
 * disagree. If the endpoint is unreachable the form has nothing to offer and
 * says so — which is honest, and better than offering a list that may be wrong.
 */
@Injectable({ providedIn: 'root' })
export class PlatformsStore {
  private readonly service = inject(SettingsService);

  private readonly itemsSignal = signal<readonly PlatformDescriptor[]>([]);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private requestToken = 0;

  readonly items = this.itemsSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();

  readonly loading = computed(() => this.stateSignal() === 'idle' || this.stateSignal() === 'loading');
  readonly isEmpty = computed(() => this.stateSignal() === 'ready' && this.itemsSignal().length === 0);
  readonly platformNames = computed(() => this.itemsSignal().map((item) => item.platform));

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.platforms().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.itemsSignal.set(response.data);
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

  descriptorFor(platform: string | null): PlatformDescriptor | null {
    if (platform === null) {
      return null;
    }
    return this.itemsSignal().find((item) => item.platform === platform) ?? null;
  }

  settingsFor(platform: string | null): readonly PlatformField[] {
    return this.descriptorFor(platform)?.settings ?? EMPTY_FIELDS;
  }

  credentialsFor(platform: string | null): readonly PlatformField[] {
    return this.descriptorFor(platform)?.credentials ?? EMPTY_FIELDS;
  }

  reset(): void {
    this.requestToken++;
    this.itemsSignal.set([]);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
  }
}
