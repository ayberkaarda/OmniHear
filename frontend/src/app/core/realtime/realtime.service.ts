import { Injectable, inject, signal } from '@angular/core';

import { environment } from '../../../environments/environment';
import { AuthStore } from '../auth/auth.store';
import {
  channelNameFor,
  FEEDBACK_ANALYZED_EVENT,
  FeedbackAnalyzedEvent,
  parseFeedbackAnalyzed,
  parseQuotaThresholdReached,
  QUOTA_THRESHOLD_REACHED_EVENT,
  QuotaThresholdReachedEvent
} from './realtime.models';

/**
 * `disabled`  — no `REVERB_APP_KEY` in this build; realtime was never attempted.
 * `unavailable` — it was attempted and the library or the socket did not come up.
 *
 * Both are non-error states for the user: the application is fully usable
 * without a socket, and the data is correct after a manual refresh
 * (`docs/contracts/realtime.md` section 4).
 */
export type RealtimeStatus = 'idle' | 'disabled' | 'connecting' | 'connected' | 'disconnected' | 'unavailable';

export interface RealtimeHandlers {
  readonly onFeedbackAnalyzed: (event: FeedbackAnalyzedEvent) => void;
  readonly onQuotaThresholdReached: (event: QuotaThresholdReachedEvent) => void;
}

/**
 * The bits of Echo this application uses, declared structurally.
 *
 * Deliberately not `import type Echo from 'laravel-echo'`: a type-only import
 * would be erased from the bundle, but it would still couple this file to the
 * package's generic signatures, and the whole point of the seam is that the
 * library is reachable only through one narrow surface. Everything below is
 * verified against `node_modules/laravel-echo/dist/echo.d.ts`.
 */
interface EchoChannelLike {
  listen(event: string, callback: (payload: unknown) => void): unknown;
}

interface EchoLike {
  private(channel: string): EchoChannelLike;
  leave(channel: string): void;
  disconnect(): void;
  connector?: { pusher?: { connection?: { bind(event: string, callback: (payload: unknown) => void): void } } };
}

/**
 * The websocket seam — and the one place in the application allowed to reach
 * pusher-js.
 *
 * **Why the dynamic `import()` is not a style choice.** `pusher-js@8.6.0` is
 * 15.62 kB brotli and `laravel-echo@2.4.0` is 2.54 kB (measured,
 * `docs/LESSONS.md`); the post-ADR-0008 transfer headroom is under 12 kB. The
 * two together are larger than the entire remaining budget, so they must never
 * enter the initial chunk. This service is therefore reached only from inside
 * the authenticated shell — never from `app.config.ts`, never from a route the
 * landing or auth pages can touch — and the libraries themselves load from an
 * `import()` that runs after `connect()` is called. CONTRIBUTING.md Trap 2 calls a
 * library in the initial chunk a class C failure: the threshold does not move,
 * the code does.
 *
 * The service owns the connection and nothing else. Turning an event into
 * application state is `RealtimeBridge`'s job, so this file has no dependency
 * on any feature store and can be tested without one.
 */
@Injectable({ providedIn: 'root' })
export class RealtimeService {
  private readonly auth = inject(AuthStore);

  private readonly statusSignal = signal<RealtimeStatus>('idle');
  readonly status = this.statusSignal.asReadonly();

  private echo: EchoLike | null = null;
  private channelName: string | null = null;
  /** Incremented by every connect/disconnect so a late `import()` cannot revive a closed session. */
  private generation = 0;
  private pending: Promise<void> | null = null;

  /**
   * Opens the tenant's private channel. Idempotent: calling it again while a
   * connection exists or is being built is a no-op, so the shell may call it
   * from a lifecycle hook without guarding.
   */
  connect(handlers: RealtimeHandlers): Promise<void> {
    if (this.echo !== null || this.pending !== null) {
      return this.pending ?? Promise.resolve();
    }

    const companyId = this.auth.user()?.company_id ?? this.auth.company()?.id ?? null;
    const token = this.auth.token();

    if (environment.reverb.key.length === 0) {
      // No key in this build. Say so explicitly rather than attempting a
      // connection that can only fail, and skip the import entirely.
      this.statusSignal.set('disabled');
      return Promise.resolve();
    }

    if (companyId === null || token === null) {
      // Called before the session settled. Not an error; the shell calls again
      // once `/auth/me` has resolved.
      return Promise.resolve();
    }

    const generation = ++this.generation;
    this.statusSignal.set('connecting');

    this.pending = this.open(generation, companyId, token, handlers).finally(() => {
      this.pending = null;
    });

    return this.pending;
  }

  /** Closes the channel. Safe to call when nothing is open. */
  disconnect(): void {
    this.generation++;
    const echo = this.echo;
    const channel = this.channelName;
    this.echo = null;
    this.channelName = null;

    if (echo === null) {
      if (this.statusSignal() !== 'disabled') {
        this.statusSignal.set('idle');
      }
      return;
    }

    try {
      if (channel !== null) {
        echo.leave(channel);
      }
      echo.disconnect();
    } catch {
      // A socket that is already gone is exactly the state we were asking for.
    }
    this.statusSignal.set('disconnected');
  }

  private async open(
    generation: number,
    companyId: number,
    token: string,
    handlers: RealtimeHandlers
  ): Promise<void> {
    let echo: EchoLike;

    try {
      // Both libraries live in their own lazy chunk from here.
      const [echoModule, pusherModule] = await Promise.all([import('laravel-echo'), import('pusher-js')]);

      if (generation !== this.generation) {
        // Signed out (or reconnected) while the chunk was in flight.
        return;
      }

      const EchoCtor = echoModule.default as unknown as new (options: Record<string, unknown>) => EchoLike;

      echo = new EchoCtor({
        broadcaster: 'reverb',
        key: environment.reverb.key,
        wsHost: environment.reverb.host,
        wsPort: environment.reverb.port,
        wssPort: environment.reverb.port,
        forceTLS: environment.reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // `PusherConnector.connect()` reads `options.Pusher` before falling back
        // to `window.Pusher`, so passing it here keeps the library out of the
        // global scope (verified in `dist/echo.js`).
        Pusher: pusherModule.default,
        authEndpoint: `${environment.apiBaseUrl}/v1/broadcasting/auth`,
        auth: {
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json'
          }
        }
      });
    } catch {
      // A blocked CDN, an offline first load, a bundler that could not fetch the
      // chunk. None of it may break the screen the user is on.
      if (generation === this.generation) {
        this.statusSignal.set('unavailable');
      }
      return;
    }

    const channelName = channelNameFor(companyId);

    try {
      const channel = echo.private(channelName);

      channel.listen(`.${FEEDBACK_ANALYZED_EVENT}`, (payload: unknown) => {
        const event = parseFeedbackAnalyzed(payload);
        if (event !== null) {
          handlers.onFeedbackAnalyzed(event);
        }
      });

      channel.listen(`.${QUOTA_THRESHOLD_REACHED_EVENT}`, (payload: unknown) => {
        const event = parseQuotaThresholdReached(payload);
        if (event !== null) {
          handlers.onQuotaThresholdReached(event);
        }
      });

      // Purely informational: the shell renders it, nothing branches on it.
      echo.connector?.pusher?.connection?.bind('state_change', (state: unknown) => {
        if (generation !== this.generation) {
          return;
        }
        const current = (state as { current?: unknown } | null)?.current;
        this.statusSignal.set(current === 'connected' ? 'connected' : 'connecting');
      });
    } catch {
      if (generation === this.generation) {
        this.statusSignal.set('unavailable');
      }
      try {
        echo.disconnect();
      } catch {
        // Nothing to clean up.
      }
      return;
    }

    this.echo = echo;
    this.channelName = channelName;
    this.statusSignal.set('connected');
  }
}
