# Settings API — profile, team, API keys, notifications, platforms

Status: **binding for W5.** Written by the main thread before dispatch so the two
tracks do not guess at each other's shape.

Conventions, pagination and the error envelope come from `docs/contracts/http-api-v1.md`.
Everything here is behind `auth:sanctum` + `verified` + the tenant scope, i.e. it
lives in a file under `routes/api/`.

Source of truth: spec §4 (`/app/settings/*`), §7.3, §8.

---

## 1. Profile

### `GET /api/v1/settings/profile` -> `200`

`{ "user": { … } }` — the `user` shape from the HTTP contract §4.

### `PATCH /api/v1/settings/profile` -> `200`

`{ "name": "…", "email": "…" }`, both optional.

**Changing the email un-verifies the account** and sends a new verification mail:
otherwise a user could move their account to an address they do not control and keep
the verified status that spec §7.1 makes mandatory. Say so in the response so the SPA
can react rather than discovering it on the next 403:

```json
{ "user": { }, "email_verification_required": true }
```

The disposable/free-domain policy from registration applies here too — the same
`DISPOSABLE_EMAIL` code, not a new one.

### `PATCH /api/v1/settings/password` -> `204`

`{ "current_password", "password", "password_confirmation" }`.
Wrong current password -> `422 VALIDATION_ERROR` on the `current_password` field.
**Revokes every other token**, keeping the caller's own, and writes an audit entry.

## 2. Team (spec §8 roles: owner / admin / member)

### `GET /api/v1/settings/team` -> `200`

`{ "data": [user], "meta": {…} }` — the company's users. Any role may read.

### `POST /api/v1/settings/team/invitations` -> `201`

`{ "email": "…", "role": "admin|member" }`. **owner or admin.**
Nobody may invite at a role above their own, and only an `owner` may grant `owner`.

An invitation is a row, not a created user: an `invitations` table with
`UNIQUE(company_id, email)`, a signed token, and an expiry. Creating the user at
invite time would put an account with no password into the tenant.

### `PATCH /api/v1/settings/team/{user}` -> `200`

`{ "role": "…" }`. **owner only.** A user may not change their own role, and the
**last owner may not be demoted** — a company with no owner can never be billed,
erased, or have its team managed again.

### `DELETE /api/v1/settings/team/{user}` -> `204`

**owner or admin**, never yourself, never the last owner. Revokes that user's tokens.
A user from another company answers **404, not 403** (invariant I1).

## 3. API keys

These are Sanctum tokens, like device sessions — which makes the boundary the
important part of this section.

**A device session and an API key must be distinguishable, or one screen will revoke
the other's rows.** They are separated by ability:

| | created by | abilities | listed at |
|---|---|---|---|
| device session | `POST /auth/login` | `['session']` | `GET /auth/tokens` |
| API key | `POST /settings/api-keys` | `['api']` | `GET /settings/api-keys` |

Each endpoint filters by ability and shows only its own kind. Existing tokens created
before this distinction carry `['*']`; treat them as sessions.

### `GET /api/v1/settings/api-keys` -> `200`

`{ "data": [ { "id", "name", "last_used_at", "created_at" } ] }`. Never the hash.

### `POST /api/v1/settings/api-keys` -> `201`

`{ "name": "…" }`. **owner or admin.** The response is the **only** time the
plaintext key is ever available:

```json
{ "api_key": { "id": 1, "name": "…", "created_at": "…" }, "plain_text_token": "1|abc…" }
```

Audited. The SPA must say plainly that the value cannot be retrieved again.

### `DELETE /api/v1/settings/api-keys/{id}` -> `204`

**owner or admin.** Another company's key -> **404**.

## 4. Notifications

### `GET /api/v1/settings/notifications` -> `200`

```json
{ "preferences": { "quota_warning": { "mail": true, "database": true } } }
```

### `PATCH /api/v1/settings/notifications` -> `200` — **owner or admin**

Same shape. The role restriction was added during implementation and is recorded
here rather than left in the code: the preference is company-wide, so a `member`
could otherwise silence the owners' mailbox. `GET` stays open to every role.
**The SPA must not offer the toggle to a member** — it would earn a 403.

Spec §7.3 requires the 80% warning by **email and in-app**, so the `database` channel
joins `mail` and Laravel's `notifications` table is added.

### `GET /api/v1/notifications` -> `200`

`{ "data": [ { "id", "type", "data", "read_at", "created_at" } ], "meta": {…} }` —
paginated, newest first, scoped to the authenticated user.

### `POST /api/v1/notifications/{id}/read` -> `204`

Another user's notification -> **404**.

## 5. Platforms

### `GET /api/v1/integrations/platforms` -> `200`

```json
{
  "data": [
    {
      "platform": "appstore",
      "requires_credentials": false,
      "settings": [ { "key": "app_id", "required": true, "format": null } ],
      "credentials": []
    }
  ]
}
```

This exists because the frontend currently hand-copies `config/connectors.php` into a
`CONNECTABLE_PLATFORMS` constant. Zendesk was added on the backend while the frontend
agent was working, and the mismatch was caught by hand — the next one would reach a
user as a `422`. The endpoint publishes what the connector registry actually holds, so
the integration form is server-driven and cannot drift.

`credentials` lists **key names and whether they are required**, never a value.

`format` is `null` when the server applies no format rule — it reports what is
actually enforced, not what would be nice. An earlier draft of this file showed
`"digits"` for `app_id`, but nothing validates that, and publishing an unenforced
constraint from the endpoint whose whole purpose is to end drift would have started
a new one. Optional settings appear too, with `required: false`; without them the
flag would always be `true` and carry no information.

---

## 6. Rules that apply to all of it

- Role checks are policies, not inline `if` statements, and every one of them has a
  test for the role that must be refused.
- Cross-tenant access answers **404, never 403** (invariant I1).
- Every mutating endpoint writes an `audit_logs` row through the existing
  `AuditLogger`; the table exists precisely so these actions are recoverable after the
  fact.
- No new error code without asking. The catalogue in `http-api-v1.md` §2 already
  covers everything here.
