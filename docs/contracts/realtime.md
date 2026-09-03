# Realtime — Reverb, channel authorization, and the client seam

Status: **binding for W5.** Written by the main thread before dispatch. The server
half already exists and is tested; this file records what it emits so the client
does not have to guess, and states the one constraint that shapes the client design.

Source of truth for behaviour: `docs/OMNIHEAR-SPEC.md` §2, §6.5, §6.6.

---

## 1. What already exists on the server

- `routes/channels.php` registers `company.{companyId}` with `App\Broadcasting\CompanyChannel`.
- Authorization endpoint: **`POST /api/v1/broadcasting/auth`**, behind `auth:sanctum`
  **and `verified`**. It is deliberately inside `/api/v1` rather than at Laravel's
  default path, so a rejected subscription answers with the `{code, message}`
  envelope every other client-facing failure uses.
- `CompanyChannel::join` compares the authenticated user's `company_id` against the
  channel segment and rejects a segment that is not a bare integer. That is
  invariant **I1** on the websocket surface and it has its own tests.

Two facts the client must not rediscover, both already recorded in `docs/LESSONS.md`:

- Channels are registered on the broadcaster instance that was default **at boot**.
- The auth endpoint returns `403 EMAIL_NOT_VERIFIED` for an unverified user.

## 2. Events

Names are what `broadcastAs()` returns, so the client subscribes to these strings,
not to class names.

### `feedback.analyzed`

```json
{
  "feedback_id": 1,
  "sentiment_label": "negative",
  "sentiment_score": -0.5497,
  "category": "bug",
  "model_version": "omnihear-onnx-f50df013ccc9"
}
```

**Deliberately not the whole feedback.** The payload is an invalidation signal plus
enough to update a row in place; anything more would duplicate the resource shape in
a second place and drift from it. A client that needs the full record fetches
`GET /api/v1/feedbacks/{id}`.

### `quota.threshold-reached`

```json
{ "used": 160, "limit": 200, "remaining": 40 }
```

Spec §7.3's soft warning at 80%. `remaining` is floored at zero.

## 3. Client constraint — this decides the design

`pusher-js@8.6.0` is **15.62 kB brotli** and `laravel-echo@2.4.0` is **2.54 kB**
(measured, `docs/LESSONS.md`). Transfer headroom after ADR-0008 is **12.91 kB**, so
the two together are more than the entire budget.

Therefore realtime **must not enter the initial bundle**. It is loaded with a
dynamic `import()` from inside the authenticated app shell, after auth resolves —
never from `app.config.ts`, never from a route that the landing or auth pages reach.
CLAUDE.md Trap 2 classifies a library in the initial chunk as **class C**: the
threshold does not move, the code does.

`angular.json` gains an `anyScript` budget when the realtime chunk lands, so the
lazy chunk that now holds a real library is not unbudgeted.

## 4. Client behaviour

- Connect after authentication, disconnect on logout and on token revocation.
- Subscribe to `private-company.{companyId}` from the authenticated user's company.
- `feedback.analyzed` updates the inbox row in place if it is on screen and nudges
  the overview KPIs; **the list is not re-fetched on every event.** Spec §6.6 asks
  for optimistic update, and a refetch per event turns a burst of analyses into a
  request storm.
- A dropped connection degrades to the current behaviour: the data is still correct
  after a manual refresh. Realtime is an enhancement, and nothing may become
  unreachable when the socket is down.
- The `.env` values the SPA needs are `REVERB_APP_KEY`, host, port and scheme.
  `REVERB_APP_SECRET` is server-side only and must never reach the client bundle.
- **Dev key:** `infra/docker-compose.dev.yml` sets `REVERB_APP_KEY` to
  `omnihear-dev-reverb-key` (default, overridable via env var) on `backend`,
  `horizon` and `reverb` alike, and `frontend/src/environments/environment.development.ts`
  carries the same literal. `backend/.env.example` still shows `REPLACE_ME` —
  that file is not what the running containers read once `--no-reload` is in
  effect (`docs/LESSONS.md`, 2026-09-03). `REVERB_APP_SECRET` defaults to
  `dev-only-not-a-real-reverb-secret` in the same compose file and is never
  written to any frontend file.

## 5. What this does not cover

Presence channels, typing indicators, and per-user (as opposed to per-company)
channels. None is in the spec.
