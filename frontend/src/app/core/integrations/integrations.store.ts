import { computed, inject, Injectable, signal } from '@angular/core';
import { Observable } from 'rxjs';

import { EMPTY_META, PaginationMeta } from '../api/pagination';
import { RequestState } from '../api/request-state';
import { FieldErrors } from '../errors/api-error';
import { errorCodeOf, fieldErrorsOf } from '../errors/error-code';
import { ToastService } from '../toast/toast.service';
import { Integration, IntegrationWriteBody } from './integration.models';
import { IntegrationsService } from './integrations.service';

/**
 * Connected channels, and the health of each connection.
 *
 * Write operations carry their own pending state rather than putting the whole
 * list back into `loading`: syncing one integration must not blank the other
 * five, and the row being synced is the only one whose control should be busy.
 *
 * `saveErrors` holds the per-field detail of a `422` for the create/edit form.
 * The user-facing sentence for the error *code* is produced by the interceptor
 * (which has already raised the toast); this is only the field-level extra a
 * form can show next to an input.
 */
@Injectable({ providedIn: 'root' })
export class IntegrationsStore {
  private readonly service = inject(IntegrationsService);
  private readonly toasts = inject(ToastService);

  private readonly itemsSignal = signal<readonly Integration[]>([]);
  private readonly metaSignal = signal<PaginationMeta>(EMPTY_META);
  private readonly stateSignal = signal<RequestState>('idle');
  private readonly errorCodeSignal = signal<string | null>(null);

  private readonly savingSignal = signal(false);
  private readonly saveErrorsSignal = signal<FieldErrors | null>(null);
  private readonly syncingIdsSignal = signal<ReadonlySet<number>>(new Set());
  private readonly deletingIdSignal = signal<number | null>(null);

  private requestToken = 0;

  readonly items = this.itemsSignal.asReadonly();
  readonly meta = this.metaSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  readonly errorCode = this.errorCodeSignal.asReadonly();
  readonly saving = this.savingSignal.asReadonly();
  readonly saveErrors = this.saveErrorsSignal.asReadonly();
  readonly syncingIds = this.syncingIdsSignal.asReadonly();
  readonly deletingId = this.deletingIdSignal.asReadonly();

  readonly isEmpty = computed(() => this.stateSignal() === 'ready' && this.itemsSignal().length === 0);
  readonly hasMorePages = computed(() => this.metaSignal().last_page > 1);
  readonly erroredCount = computed(() => this.itemsSignal().filter((item) => item.status === 'error').length);

  load(): void {
    const token = ++this.requestToken;
    this.stateSignal.set('loading');
    this.errorCodeSignal.set(null);

    this.service.list().subscribe({
      next: (response) => {
        if (token !== this.requestToken) {
          return;
        }
        this.itemsSignal.set(response.data);
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

  clearSaveErrors(): void {
    this.saveErrorsSignal.set(null);
  }

  create(body: IntegrationWriteBody, onSuccess?: () => void): void {
    this.save(this.service.create(body), onSuccess);
  }

  update(id: number, body: IntegrationWriteBody, onSuccess?: () => void): void {
    this.save(this.service.update(id, body), onSuccess);
  }

  remove(id: number, onSuccess?: () => void): void {
    if (this.deletingIdSignal() !== null) {
      return;
    }
    this.deletingIdSignal.set(id);

    this.service.remove(id).subscribe({
      next: () => {
        this.deletingIdSignal.set(null);
        this.itemsSignal.update((items) => items.filter((item) => item.id !== id));
        onSuccess?.();
      },
      error: () => {
        // The interceptor has already surfaced the code; the row simply stays.
        this.deletingIdSignal.set(null);
      }
    });
  }

  /**
   * `202` means queued, not finished. The list is re-read so `last_synced_at`
   * and `sync_error` update once the worker has run — polling for that would
   * be a different feature, and the realtime channel is not wired here yet.
   */
  sync(id: number): void {
    if (this.syncingIdsSignal().has(id)) {
      return;
    }
    this.syncingIdsSignal.update((ids) => new Set(ids).add(id));

    this.service.sync(id).subscribe({
      next: () => {
        this.releaseSync(id);
        this.toasts.success(
          $localize`:Toast after a sync has been queued@@integrations.sync.queued:Sync queued. New feedback appears once the run finishes.`
        );
        this.load();
      },
      error: () => {
        // 409 SYNC_IN_PROGRESS and 503 INTEGRATION_UNAVAILABLE are both already
        // on screen as a toast, keyed by their code.
        this.releaseSync(id);
      }
    });
  }

  reset(): void {
    this.requestToken++;
    this.itemsSignal.set([]);
    this.metaSignal.set(EMPTY_META);
    this.stateSignal.set('idle');
    this.errorCodeSignal.set(null);
    this.savingSignal.set(false);
    this.saveErrorsSignal.set(null);
    this.syncingIdsSignal.set(new Set());
    this.deletingIdSignal.set(null);
  }

  private save(request: Observable<Integration>, onSuccess?: () => void): void {
    if (this.savingSignal()) {
      return;
    }
    this.savingSignal.set(true);
    this.saveErrorsSignal.set(null);

    request.subscribe({
      next: () => {
        this.savingSignal.set(false);
        onSuccess?.();
        // Re-read rather than splice the response in: `feedback_count` and
        // `status` are server-derived and the list is small.
        this.load();
      },
      error: (error: unknown) => {
        this.savingSignal.set(false);
        this.saveErrorsSignal.set(fieldErrorsOf(error));
      }
    });
  }

  private releaseSync(id: number): void {
    this.syncingIdsSignal.update((ids) => {
      const next = new Set(ids);
      next.delete(id);
      return next;
    });
  }
}
