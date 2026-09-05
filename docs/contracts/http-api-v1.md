# HTTP API v1 — Laravel <-> Angular contract

Status: **binding for F2+**. Authored centrally before the F2 phase began, so
backend and frontend can be built in parallel without either side
guessing. Any change to this file is a contract change: both sides update together.

Source of truth for behaviour: `docs/OMNIHEAR-SPEC.md` sections 4, 5, 7, 8.

---

## 1. Conventions

- **Base path:** `/api/v1`. The unversioned `/api/health` probe stays where it is.
- **Auth:** Sanctum personal access tokens. `Authorization: Bearer <token>`.
- **Content type:** `application/json` in and out. `Accept: application/json` is
  required; without it Laravel may redirect instead of returning JSON.
- **Primary keys:** `bigint` auto-increment, serialized as JSON **numbers**.
  Enumeration is not a leak here because cross-tenant reads return `404`
  (invariant I1), which is exactly the mitigation an opaque id would buy.
- **Timestamps:** ISO-8601 with offset, UTC (`2026-09-02T11:04:03+00:00`).
- **No `data` wrapper on single resources.** A single resource is returned under a
  named top-level key (`user`, `company`, `feedback`). Collections are paginated
  and use `{ "data": [...], "meta": {...} }`.

### Pagination envelope

```json
{
  "data": [],
  "meta": { "current_page": 1, "per_page": 25, "total": 0, "last_page": 1 }
}
```

Query params: `?page=1&per_page=25` (`per_page` max 100, default 25).

### Response headers

| Header | When | Meaning |
|---|---|---|
| `X-Quota-Remaining` | every authenticated `/api/v1` response | `companies.quota_limit - companies.analyzed_feedback_count`, floored at `0` |
| `X-Correlation-Id` | every response | echoed from the request if present, generated otherwise |
| `Retry-After` | `429`, `402` | seconds |

---

## 2. Error envelope

Every non-2xx response from `/api/v1` — validation, auth, throttle, and unhandled
exceptions alike — has this exact shape:

```json
{ "code": "VALIDATION_ERROR", "message": "The given data was invalid.", "errors": { "email": ["..."] } }
```

- `code` — stable machine string from the catalogue below. The Angular
  interceptor maps `code` to a `$localize` message; it never renders `message` to
  the user except as a developer-facing fallback.
- `message` — English, from `lang/en`; server-localised via `Accept-Language`
  when the client asks (`lang/{tr,en}`).
- `errors` — present only for `VALIDATION_ERROR`; field to list of messages.

### Error code catalogue

| code | HTTP | Raised when |
|---|---|---|
| `VALIDATION_ERROR` | 422 | Form request validation failed |
| `INVALID_CREDENTIALS` | 401 | Login email/password mismatch |
| `UNAUTHENTICATED` | 401 | Missing/expired/revoked token |
| `EMAIL_NOT_VERIFIED` | 403 | Verified email required for this route |
| `FORBIDDEN` | 403 | Policy denied (role insufficient) |
| `NOT_FOUND` | 404 | Missing record **or** record owned by another tenant |
| `QUOTA_EXCEEDED` | 402 | Analysis quota exhausted (spec 7.4) |
| `TOO_MANY_REQUESTS` | 429 | Rate limiter tripped |
| `DISPOSABLE_EMAIL` | 422 | Registration blocked by domain blocklist (spec 7.1) |
| `SERVER_ERROR` | 500 | Unhandled exception; `message` is generic in production |

**`404`, never `403`, for cross-tenant access.** A `403` confirms the row exists.

---

## 3. Rate limits (spec section 8)

| Limiter | Limit | Applies to |
|---|---|---|
| `auth-register` | 5 / hour / IP | `POST /auth/register` |
| `auth-login` | 10 / min / IP | `POST /auth/login`, `POST /auth/forgot-password` |
| `api` (authenticated) | 120 / min / user | everything behind `auth:sanctum` |
| `public` | 30 / min / IP | unauthenticated `/api/v1` routes |

---

## 4. Resource shapes

### `user`

```json
{
  "id": 1,
  "company_id": 1,
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "role": "owner",
  "email_verified_at": "2026-09-02T11:04:03+00:00",
  "two_factor_enabled": false,
  "created_at": "2026-09-02T11:04:03+00:00"
}
```

`role` is one of `owner`, `admin`, `member`.
Never serialized: `password`, `remember_token`, `two_factor_secret`, `last_login_ip`.

### `company`

```json
{
  "id": 1,
  "name": "Acme Inc.",
  "plan": "free",
  "analyzed_feedback_count": 12,
  "quota_limit": 200,
  "quota_remaining": 188,
  "created_at": "2026-09-02T11:04:03+00:00"
}
```

`plan` is one of `free`, `pro`. The plan-to-quota mapping lives in
`config/quota.php` (`free` = 200 per spec 7.2; the `pro` value is set in the
payments phase). Nothing hard-codes `200` outside that config.

---

## 5. Endpoints — F2 (auth + tenant core)

All bodies are `application/json`.

### `POST /api/v1/auth/register` -> `201`

```json
{ "name": "Ada Lovelace", "email": "ada@acme.com", "password": "...", "password_confirmation": "...", "company_name": "Acme Inc." }
```

Rules: `name` required, max 255. `email` required, valid, unique across users, not
on the disposable-domain blocklist. `password` required, confirmed, min 12,
`Password::defaults()` (uncompromised in production). `company_name` required, max 255.

Creates the company **and** its first user with `role = owner`, in one transaction.
Sends the verification email. Returns:

```json
{ "token": "1|abc...", "user": {}, "company": {} }
```

Errors: `422 VALIDATION_ERROR`, `422 DISPOSABLE_EMAIL`, `429 TOO_MANY_REQUESTS`.

### `POST /api/v1/auth/login` -> `200`

```json
{ "email": "ada@acme.com", "password": "...", "device_name": "web" }
```

`device_name` optional, default `"web"`; it names the Sanctum token so sessions
are revocable per device (spec section 8). Records `last_login_ip`.

Returns `{ "token", "user", "company" }`.
Errors: `401 INVALID_CREDENTIALS` (same code and same timing for an unknown email
and for a wrong password), `429 TOO_MANY_REQUESTS`.

> Login succeeds for an unverified user and returns a token. Verification is
> enforced per-route by the `verified` middleware, so the SPA can render the
> "check your inbox" state from an authenticated session.

### `POST /api/v1/auth/logout` -> `204` (auth required)

Revokes **the current token only**.

### `GET /api/v1/auth/me` -> `200` (auth required)

Returns `{ "user", "company" }`.

### `POST /api/v1/auth/forgot-password` -> `202`

`{ "email": "..." }` returns `{ "message": "..." }`. Always `202`, whether or not
the email exists — no account enumeration.

### `POST /api/v1/auth/reset-password` -> `200`

`{ "token", "email", "password", "password_confirmation" }`.
Revokes every existing token for that user on success.
Errors: `422 VALIDATION_ERROR` (an invalid or expired token surfaces here).

### `POST /api/v1/auth/email/verify` -> `200`

`{ "id": 1, "hash": "...", "expires": 1234567890, "signature": "..." }`.

The emailed link points at the **frontend** route
`/auth/verify-email?id=&hash=&expires=&signature=`, which forwards these four
values to this endpoint. Returns `{ "user" }`. Idempotent: an already-verified
user gets `200`.
Errors: `422 VALIDATION_ERROR` for a bad or expired signature.

### `POST /api/v1/auth/email/resend` -> `202` (auth required)

Throttled to 6 / hour / user.

---

## 5a. Sessions and account (F2.5)

### Where `verified` applies

Spec 7.1 makes email verification mandatory, so the **tenant surface** — everything
under `routes/api/`: integrations, feedback, overview, billing — sits behind the
`verified` middleware.

Four routes are deliberately outside it, and the reason is not convenience:

- `GET /auth/me`, `POST /auth/logout`, `POST /auth/email/resend` — the SPA is sent
  to a "check your inbox" screen and has to be able to render it, resend from it,
  and leave it.
- `GET/DELETE /auth/tokens` and `DELETE /account` — revoking a stolen device token
  must not require a mailbox the user may have lost control of, and gating erasure
  behind verification would make an unverified account undeletable, which is
  exactly the data that should be easiest to erase.

### `GET /api/v1/auth/tokens` -> `200` (auth required)

```json
{ "data": [ { "id": 1, "name": "web", "last_used_at": "…|null", "created_at": "…" } ] }
```

Lists the caller's own tokens, one per device (spec 8: sessions are revocable per
device). The token hash is never serialized.

### `DELETE /api/v1/auth/tokens/{id}` -> `204` (auth required)

Revoking the current token is allowed and ends the session.
A token belonging to another user answers **404, not 403** — invariant I1's rule
covers this surface too, and a 403 would confirm the token exists.

### `DELETE /api/v1/account` -> `202` (auth required)

Right to erasure (spec 8, KVKK/GDPR). `owner` only; anyone else gets
`403 FORBIDDEN`. Deletes the company and everything cascading from it, revokes
every token, and writes an audit entry **before** the deletion.

No id in the path, on purpose: the company is read off the authenticated user, so
there is no cross-tenant request to reject in the first place.

---

## 6. Frontend obligations

- `authInterceptor` attaches `Authorization: Bearer` from the auth store.
- `errorInterceptor`:
  - `401 UNAUTHENTICATED` clears the store and redirects to `/auth/login`.
  - `402 QUOTA_EXCEEDED` opens the blocking paywall modal (spec 7.5). Never a toast.
  - `403 EMAIL_NOT_VERIFIED` redirects to `/auth/verify-email`.
  - everything else raises a toast with the `$localize` message for `code`.
- Every `code` in the catalogue above has a translated message in **both**
  `messages.xlf` and `messages.tr.xlf`. An unknown `code` falls back to a generic
  message and is logged — it never renders a raw server string.
- `X-Quota-Remaining` is read off every response into the quota signal, so the
  usage meter stays fresh without polling.

---

## 7. Reserved for later phases

Shapes are **not** frozen here; the paths are listed so nobody invents a
conflicting one.

| Phase | Path |
|---|---|
| F4 | `GET/POST/PATCH/DELETE /api/v1/integrations`, `POST /api/v1/integrations/{id}/sync` |
| F5 | `GET /api/v1/feedbacks`, `GET /api/v1/feedbacks/{id}`, `GET /api/v1/overview/kpis` |
| F6/F7 | `POST /api/v1/billing/checkout`, `GET /api/v1/billing/subscription`, `POST /api/webhooks/{stripe,iyzico}` |
| F9 | `DELETE /api/v1/account` (right to erasure) |
